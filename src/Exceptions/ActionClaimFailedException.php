<?php

namespace DiscoveryUkraine\SagaLaraFlow\Exceptions;

/**
 * Raised when a synchronous claim on an action or compensation row fails
 * (ActionRecorder::startAction() / CompensationRecorder::startCompensation() return
 * false). In sync mode there is no competing process between scheduling a step and
 * running it in the same call — a failed claim there is not a race, it is a broken
 * invariant. Queued jobs treat the same failure quietly instead: a lost claim there
 * is the normal outcome of two workers legitimately racing for one row.
 */
class ActionClaimFailedException extends FlowException
{
    public static function forAction(string $actionClass, int $sequence): self
    {
        return new self(sprintf(
            'Failed to claim action %s at sequence %d for synchronous execution. '
            .'This should be unreachable in sync mode and indicates a broken invariant.',
            $actionClass,
            $sequence,
        ));
    }

    public static function forCompensation(string $compensationClass, int $sequence): self
    {
        return new self(sprintf(
            'Failed to claim compensation %s at sequence %d for synchronous execution. '
            .'This should be unreachable in sync mode and indicates a broken invariant.',
            $compensationClass,
            $sequence,
        ));
    }
}
