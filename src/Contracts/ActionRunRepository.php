<?php

namespace DiscoveryUkraine\SagaLaraFlow\Contracts;

use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;

/**
 * @internal This contract is an implementation seam for the package's own
 * runtime, not a public extension point. Methods may be added to it in a minor
 * release; swap behaviour through config('saga-lara-flow.models.*') instead.
 */
interface ActionRunRepository
{
    public function find(string $flowRunId, int $sequence): ?ActionRun;

    /**
     * Non-terminal action steps (Pending/Running) whose expires_at deadline has
     * passed, oldest first, capped at $limit. Used by the monitor to expire stuck
     * actions.
     *
     * @return iterable<int, ActionRun>
     */
    public function dueForExpiration(int $limit): iterable;

    /**
     * Sequential Pending action steps (parallel_group is null) older than the grace
     * window whose repair window is open and attempts are not exhausted, oldest
     * first, capped at $limit. Used by the doctor to re-dispatch a lost RunActionJob.
     *
     * @return iterable<int, ActionRun>
     */
    public function dueForRepair(int $limit, int $graceSeconds, int $maxAttempts): iterable;

    /**
     * Sequential Running action steps (parallel_group is null) past their own
     * reclaim_stale_at, whose repair window is open and attempts are not exhausted,
     * earliest deadline first, capped at $limit. Used by the doctor to re-dispatch a
     * step whose worker died mid-execution. Only rows with a reclaim window resolved
     * onto them carry that deadline, so with reclaim configured nowhere this is empty.
     *
     * @return iterable<int, ActionRun>
     */
    public function dueForStaleRunningRepair(int $limit, int $maxAttempts): iterable;
}
