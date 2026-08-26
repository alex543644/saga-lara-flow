<?php

namespace DiscoveryUkraine\SagaLaraFlow\Data;

use DateTimeInterface;

/**
 * Everything decided about a step before it exists as a row: which action to run,
 * with what arguments, and the options resolved for it (precedence already applied
 * by the builder). It travels unchanged from ActionBuilder / ParallelRunner through
 * ActionDispatcher to ActionRecorder::scheduleAction(), which writes it out.
 *
 * The step's identity — its run and its (flow_run_id, sequence) ordinal — stays out
 * of it: those are the caller's context, not part of the description of the work.
 */
final readonly class ActionSchedule
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __construct(
        public string $actionClass,
        public array $arguments,
        public bool $hasCompensation = false,
        public bool $continueOnFailure = false,
        public ?int $parallelGroup = null,
        public ?DateTimeInterface $expiresAt = null,
        public ?string $actionName = null,
        public ?string $retrySignal = null,
        public ?int $retrySignalMaxAttempts = null,
        public ?int $reclaimStaleAfterSeconds = null,
        public ?bool $reclaimStaleEnabled = null,
    ) {}
}
