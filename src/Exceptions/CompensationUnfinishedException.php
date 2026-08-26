<?php

namespace DiscoveryUkraine\SagaLaraFlow\Exceptions;

use DiscoveryUkraine\SagaLaraFlow\Models\CompensationRun;

/**
 * Surfaces a compensation that had not reached a terminal state when its rollback
 * level finished, as the secondary cause attached to the run (recorded under
 * flow_run.exception['compensation']).
 *
 * Distinct from CompensationFailedException: that one ran and threw, and its own
 * exception is recorded alongside. This one has no cause to report — it never got far
 * enough to have one. Without it the rollback would look complete while one step was
 * silently never undone.
 *
 * The message states what was observed, not what will be. Usually the compensation is
 * genuinely stranded — its worker died, or its job never arrived — but a duplicate
 * delivery can also close a batch a moment before the live worker records its success,
 * and then the compensation reported here does finish, just too late to be seen.
 */
class CompensationUnfinishedException extends FlowException
{
    public static function for(CompensationRun $compensationRun): self
    {
        $label = $compensationRun->compensation_class ?? 'closure';

        return new self(sprintf(
            'Compensation %s at rollback sequence %d had not finished when its rollback level ended (status: %s).',
            $label,
            $compensationRun->sequence,
            $compensationRun->status->value,
        ));
    }
}
