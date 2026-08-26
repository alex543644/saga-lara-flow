<?php

namespace DiscoveryUkraine\SagaLaraFlow\Exceptions;

use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;

/**
 * Raised when a transition is refused because the run had already moved on: the row
 * no longer holds the status the caller read before deciding.
 *
 * Deliberately not an InvalidTransitionException. That one means the state graph
 * forbids the move, and repeating it cannot help. This one means the move was legal and
 * somebody else got there first, so re-reading the run and deciding again is the
 * sensible response — the opposite reaction of the two.
 */
class ConcurrentFlowTransitionException extends FlowException
{
    public static function for(FlowRun $run, FlowStatus $from, FlowStatus $to, ?FlowStatus $actual): self
    {
        return new self(sprintf(
            'Cannot transition flow run [%s] from [%s] to [%s]: the row is %s.',
            $run->id,
            $from->value,
            $to->value,
            $actual === null ? 'no longer there' : "now [{$actual->value}]",
        ));
    }
}
