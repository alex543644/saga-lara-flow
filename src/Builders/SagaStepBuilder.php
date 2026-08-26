<?php

namespace DiscoveryUkraine\SagaLaraFlow\Builders;

use Closure;
use DiscoveryUkraine\SagaLaraFlow\Enums\CompensationFailurePolicy;
use DiscoveryUkraine\SagaLaraFlow\Runtime\FlowRuntime;
use Throwable;

/**
 * One step of a saga() group: an action plus its compensation. compensateWith()
 * (class or closure), an optional per-step onCompensationFailure() and
 * compensateStepOnSelfFailure() override the group settings (precedence action > group >
 * config). retryOnSignal() mirrors the action-level policy so a step inside a group
 * can park on a signal too. step()/run() delegate back to the group so the fluent
 * chain reads as a single transactional block.
 */
final class SagaStepBuilder
{
    private string|Closure|null $compensation = null;

    /**
     * @var array<int, mixed>
     */
    private array $compensationArguments = [];

    private ?CompensationFailurePolicy $policy = null;

    private ?bool $compensateOnSelfFailure = null;

    private ?string $retrySignal = null;

    private ?int $retryMaxRetries = null;

    private ?int $retryWaitSeconds = null;

    /**
     * @var list<class-string<Throwable>>|null
     */
    private ?array $retryOnly = null;

    private ?int $reclaimStaleAfterSeconds = null;

    private ?bool $reclaimStaleEnabled = null;

    private ?int $compensationReclaimStaleAfterSeconds = null;

    private ?bool $compensationReclaimStaleEnabled = null;

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __construct(
        private readonly SagaBuilder $saga,
        private readonly string $actionClass,
        private readonly array $arguments,
    ) {}

    public function compensateWith(string|Closure $compensation, mixed ...$arguments): self
    {
        $this->compensation = $compensation;
        $this->compensationArguments = array_values($arguments);

        return $this;
    }

    public function onCompensationFailure(CompensationFailurePolicy $policy): self
    {
        $this->policy = $policy;

        return $this;
    }

    public function compensateStepOnSelfFailure(bool $compensate = true): self
    {
        $this->compensateOnSelfFailure = $compensate;

        return $this;
    }

    /**
     * Wait for a named signal instead of failing this step. Mirrors
     * ActionBuilder::retryOnSignal(), which documents the semantics. A parked step
     * suspends the group where it stands: the steps already completed keep their
     * compensations on the stack and roll back only if this one eventually gives up.
     *
     * @param  list<class-string<Throwable>>|null  $only
     */
    public function retryOnSignal(
        string $signal,
        ?int $maxRetries = null,
        ?int $waitSeconds = null,
        ?array $only = null,
    ): self {
        $this->retrySignal = $signal;
        $this->retryMaxRetries = $maxRetries;
        $this->retryWaitSeconds = $waitSeconds;
        $this->retryOnly = $only;

        return $this;
    }

    /**
     * Mirrors ActionBuilder::reclaimStaleAfter() for a step inside a group.
     */
    public function reclaimStaleAfter(int $seconds): self
    {
        $this->reclaimStaleAfterSeconds = $seconds;

        return $this;
    }

    /**
     * Mirrors ActionBuilder::enableStaleReclaim() for a step inside a group.
     */
    public function enableStaleReclaim(bool $enabled = true): self
    {
        $this->reclaimStaleEnabled = $enabled;

        return $this;
    }

    /**
     * Mirrors ActionBuilder::reclaimCompensationStaleAfter() for a step inside a group.
     */
    public function reclaimCompensationStaleAfter(int $seconds): self
    {
        $this->compensationReclaimStaleAfterSeconds = $seconds;

        return $this;
    }

    /**
     * Mirrors ActionBuilder::enableCompensationStaleReclaim() for a step inside a group.
     */
    public function enableCompensationStaleReclaim(bool $enabled = true): self
    {
        $this->compensationReclaimStaleEnabled = $enabled;

        return $this;
    }

    public function step(string $actionClass, mixed ...$arguments): self
    {
        return $this->saga->step($actionClass, ...$arguments);
    }

    /**
     * @return list<mixed>
     *
     * @throws Throwable
     */
    public function run(): array
    {
        return $this->saga->run();
    }

    /**
     * Run this step as an action carrying its saga compensation context. Invoked by
     * SagaBuilder::run() in registration order.
     *
     * @throws Throwable
     */
    public function execute(
        FlowRuntime $runtime,
        ?CompensationFailurePolicy $groupCompensationFailurePolicy,
        ?bool $groupCompensateOnSelfFailure,
        ?int $parallelGroupId
    ): mixed {
        $action = new ActionBuilder($runtime, $this->actionClass, $this->arguments);

        if ($this->compensation !== null) {
            $action->compensateWith($this->compensation, ...$this->compensationArguments);
        }

        if ($this->policy !== null) {
            $action->onCompensationFailure($this->policy);
        }

        if ($this->compensateOnSelfFailure !== null) {
            $action->compensateStepOnSelfFailure($this->compensateOnSelfFailure);
        }

        if ($this->retrySignal !== null) {
            $action->retryOnSignal(
                $this->retrySignal,
                $this->retryMaxRetries,
                $this->retryWaitSeconds,
                $this->retryOnly,
            );
        }

        if ($this->reclaimStaleAfterSeconds !== null) {
            $action->reclaimStaleAfter($this->reclaimStaleAfterSeconds);
        }

        if ($this->reclaimStaleEnabled !== null) {
            $action->enableStaleReclaim($this->reclaimStaleEnabled);
        }

        if ($this->compensationReclaimStaleAfterSeconds !== null) {
            $action->reclaimCompensationStaleAfter($this->compensationReclaimStaleAfterSeconds);
        }

        if ($this->compensationReclaimStaleEnabled !== null) {
            $action->enableCompensationStaleReclaim($this->compensationReclaimStaleEnabled);
        }

        return $action
            ->withSagaGroup($groupCompensationFailurePolicy, $groupCompensateOnSelfFailure, $parallelGroupId)
            ->run();
    }
}
