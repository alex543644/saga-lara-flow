<?php

namespace DiscoveryUkraine\SagaLaraFlow\Runtime;

use DateTimeInterface;
use DiscoveryUkraine\SagaLaraFlow\Concerns\NormalizesExceptions;
use DiscoveryUkraine\SagaLaraFlow\Contracts\Serializer;
use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowEventType;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionAwaitingRetry;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionCompleted;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionFailed;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionRedispatched;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionRetried;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionStarted;
use DiscoveryUkraine\SagaLaraFlow\Events\OptionalActionFailed;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Persists an action step through its lifecycle (scheduled → started → completed
 * /failed), serializing arguments and results and appending the matching events.
 */
final readonly class ActionRecorder
{
    use NormalizesExceptions;

    public function __construct(
        private EventLog $events,
        private Serializer $serializer,
    ) {}

    /**
     * Create the pending ActionRun for a scheduled step. The arguments are
     * serialized once here and become the durable source the executing job
     * (or inline run) reads back.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function scheduleAction(
        FlowRun $flowRun,
        int $sequence,
        string $actionClass,
        array $arguments,
        bool $hasCompensation = false,
        bool $continueOnFailure = false,
        ?int $parallelGroup = null,
        ?DateTimeInterface $expiresAt = null,
        ?string $actionName = null,
        ?string $retrySignal = null,
        ?int $retrySignalMaxAttempts = null,
    ): ActionRun {
        /** @var class-string<ActionRun> $model */
        $model = config('saga-lara-flow.models.action_run');

        $actionRun = new $model;

        $actionRun->fill([
            'flow_run_id' => $flowRun->id,
            'sequence' => $sequence,
            'action_class' => $actionClass,
            'action_name' => $actionName,
            'status' => ActionStatus::Pending,
            'has_compensation' => $hasCompensation,
            'continue_on_failure' => $continueOnFailure,
            'parallel_group' => $parallelGroup,
            'expires_at' => $expiresAt ?? $this->defaultExpiry(),
            'arguments' => $this->serializer->serialize($arguments),
            'attempts' => 0,
            'queue_attempts_exhausted' => false,
            'retry_signal' => $retrySignal,
            'retry_signal_attempts' => 0,
            'retry_signal_max_attempts' => $retrySignalMaxAttempts,
        ]);

        $actionRun->save();

        $this->events->record($flowRun, FlowEventType::ActionScheduled, $sequence, $actionRun, [
            'action_class' => $actionClass,
        ]);

        return $actionRun;
    }

    private function defaultExpiry(): ?DateTimeInterface
    {
        $seconds = config('saga-lara-flow.monitor.expiration.defaults.action');

        return $seconds === null ? null : Carbon::now()->addSeconds((int) $seconds);
    }

    /**
     * Record the doctor re-dispatching a stuck Pending action. The
     * action keeps its status/sequence — only a fresh RunActionJob is sent — so an
     * action.redispatched event is appended for visibility without altering history.
     */
    public function actionRedispatched(ActionRun $actionRun): void
    {
        $this->events->record(
            $actionRun->flowRun,
            FlowEventType::ActionRedispatched,
            $actionRun->sequence,
            $actionRun
        );

        event(new ActionRedispatched($actionRun));
    }

    public function startAction(ActionRun $actionRun): void
    {
        $actionRun->status = ActionStatus::Running;
        $actionRun->attempts = $actionRun->attempts + 1;
        $actionRun->started_at = Carbon::now();
        $actionRun->save();

        $this->events->record(
            $actionRun->flowRun,
            FlowEventType::ActionStarted,
            $actionRun->sequence,
            $actionRun
        );

        event(new ActionStarted($actionRun));
    }

    public function completeAction(ActionRun $actionRun, mixed $result): void
    {
        $actionRun->status = ActionStatus::Completed;
        $actionRun->result = $this->serializer->serialize($result);
        $actionRun->finished_at = Carbon::now();
        $actionRun->save();

        $this->events->record(
            $actionRun->flowRun,
            FlowEventType::ActionCompleted,
            $actionRun->sequence,
            $actionRun
        );

        event(new ActionCompleted($actionRun));
    }

    public function failAction(ActionRun $actionRun, Throwable $exception): void
    {
        $exceptionArray = $this->exceptionToArray($exception);

        $actionRun->status = ActionStatus::Failed;
        $actionRun->exception = $exceptionArray;
        $actionRun->finished_at = Carbon::now();
        $actionRun->save();

        $this->events->record(
            $actionRun->flowRun,
            FlowEventType::ActionFailed,
            $actionRun->sequence,
            $actionRun,
            [
                'exception' => $exceptionArray,
            ]
        );

        event(new ActionFailed($actionRun, $exception));
    }

    /**
     * Mark a failed optional (continueOnFailure) step as OptionalFailed once its
     * retries are exhausted. The flow is not failed; the recorded exception is
     * preserved and an optional_failed event/Laravel event is appended so the
     * give-up is visible in history.
     */
    public function optionalFail(ActionRun $actionRun): void
    {
        $actionRun->status = ActionStatus::OptionalFailed;
        $actionRun->finished_at = Carbon::now();
        $actionRun->save();

        $this->events->record(
            $actionRun->flowRun,
            FlowEventType::ActionOptionalFailed,
            $actionRun->sequence,
            $actionRun,
            $actionRun->exception !== null ? ['exception' => $actionRun->exception] : []
        );

        event(new OptionalActionFailed($actionRun));
    }

    /**
     * Record that the queue has finished retrying this step's current job, from the
     * one place that knows: Laravel's own failure hook. The retry seam reads this
     * instead of comparing the attempts counter with the action's $tries, which lives
     * in code and can change under a job already in flight. No event is appended —
     * this is queue bookkeeping, not a step in the flow's history.
     */
    public function markQueueAttemptsExhausted(ActionRun $actionRun): void
    {
        if ($actionRun->queue_attempts_exhausted) {
            return;
        }

        $actionRun->queue_attempts_exhausted = true;
        $actionRun->save();
    }

    /**
     * Close a parked step that has given up: put it back into the Failed state the
     * retry policy deferred, keeping the exception and finished_at of the attempt that
     * failed. No event is appended — action.failed was recorded back then, and the
     * give-up is visible from the flow's own failure and the timed-out wait-signal.
     */
    public function settleAwaitingRetry(ActionRun $actionRun): void
    {
        if ($actionRun->status !== ActionStatus::AwaitingRetry) {
            return;
        }

        $actionRun->status = ActionStatus::Failed;
        $actionRun->save();
    }

    /**
     * Park a failed step on its retry signal: flip it to AwaitingRetry and append an
     * action.awaiting_retry event. The step is NOT terminal — the recorded exception
     * and finished_at of the last attempt are kept so the seam can decide again once
     * the signal arrives (or the wait-signal times out).
     */
    public function awaitRetry(ActionRun $actionRun, string $signal, ?int $maxAttempts = null): void
    {
        $actionRun->status = ActionStatus::AwaitingRetry;
        $actionRun->retry_signal = $signal;

        // A row scheduled without a policy adopts one here, and the cap has to be
        // written with the signal: from this parking on the budget is read off the
        // row, so an empty column would silently mean unbounded.
        $actionRun->retry_signal_max_attempts ??= $maxAttempts;

        $actionRun->save();

        $this->events->record(
            $actionRun->flowRun,
            FlowEventType::ActionAwaitingRetry,
            $actionRun->sequence,
            $actionRun,
            [
                'signal' => $signal,
                'retry_signal_attempts' => $actionRun->retry_signal_attempts,
                'retry_signal_max_attempts' => $actionRun->retry_signal_max_attempts,
            ]
        );

        event(new ActionAwaitingRetry($actionRun, $signal));
    }

    /**
     * Start another signal-gated retry cycle: spend one unit of the budget and rewind
     * the row to Pending so the very same (flow_run_id, sequence) ordinal runs again.
     * `attempts` is deliberately untouched — it counts queue attempts within one
     * execution — and the previous exception stands until the new attempt overwrites it.
     */
    public function retryAction(ActionRun $actionRun, ?DateTimeInterface $expiresAt = null): void
    {
        $actionRun->retry_signal_attempts = $actionRun->retry_signal_attempts + 1;
        $actionRun->status = ActionStatus::Pending;
        $actionRun->started_at = null;
        $actionRun->finished_at = null;

        // A fresh job means a fresh native-attempt allowance: the queue has not given
        // up on this cycle yet, whatever it did to the previous one.
        $actionRun->queue_attempts_exhausted = false;

        $deadline = $expiresAt ?? $this->defaultExpiry();

        $actionRun->expires_at = $deadline === null ? null : Carbon::instance($deadline);
        $actionRun->save();

        $this->events->record(
            $actionRun->flowRun,
            FlowEventType::ActionRetried,
            $actionRun->sequence,
            $actionRun,
            [
                'signal' => $actionRun->retry_signal,
                'retry_signal_attempts' => $actionRun->retry_signal_attempts,
                'retry_signal_max_attempts' => $actionRun->retry_signal_max_attempts,
            ]
        );

        event(new ActionRetried($actionRun));
    }

    /**
     * Mark a still-pending/running step Expired once its expires_at deadline passes
     * (monitor): record the expiry cause and append an action.expired event. On
     * replay the seam treats Expired as a failure (or, for an optional step, as a
     * give-up returning its fallback).
     *
     * @param  array<string, mixed>  $exception
     */
    public function expireAction(ActionRun $actionRun, array $exception): void
    {
        $actionRun->status = ActionStatus::Expired;
        $actionRun->exception = $exception;
        $actionRun->finished_at = Carbon::now();
        $actionRun->save();

        $this->events->record(
            $actionRun->flowRun,
            FlowEventType::ActionExpired,
            $actionRun->sequence,
            $actionRun,
            ['exception' => $exception],
        );
    }
}
