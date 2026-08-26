<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Action;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use RuntimeException;

/**
 * Action that loses its row to a competing worker while it is still running.
 *
 * Bumping `attempts` is exactly what a rival claim does, so this reproduces the
 * reclaim race — worker B takes over a row worker A is still executing — inside a
 * single process, without real concurrency. Whatever this action then returns (or
 * throws) belongs to an executor that no longer owns the row.
 */
final class ReclaimedMidRunAction extends Action
{
    public function handle(string $actionRunId, bool $throw = false): string
    {
        $current = ActionRun::query()->findOrFail($actionRunId);

        ActionRun::query()
            ->whereKey($actionRunId)
            ->update(['attempts' => $current->attempts + 1]);

        if ($throw) {
            throw new RuntimeException('zombie failure');
        }

        return 'zombie result';
    }
}
