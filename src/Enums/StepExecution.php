<?php

namespace DiscoveryUkraine\SagaLaraFlow\Enums;

/**
 * What an executor managed to do with the row it was handed.
 *
 * The two failing cases look the same from the queue — nothing was settled, so the
 * job returns quietly either way — but they mean opposite things where no competitor
 * is supposed to exist. Never claiming a freshly created row is a broken invariant;
 * being superseded after the work ran is an ordinary race that replay resolves.
 */
enum StepExecution
{
    /** The row was claimed, the step ran, and its outcome was recorded. */
    case Executed;

    /** The row was already owned or no longer claimable; nothing ran. */
    case ClaimLost;

    /** The step ran, but the row changed hands before its outcome could be written. */
    case Superseded;

    /**
     * Whether this executor settled the row. Callers that only need "is there an
     * outcome to act on" — the queued jobs — read this and ignore the distinction.
     */
    public function settled(): bool
    {
        return $this === self::Executed;
    }
}
