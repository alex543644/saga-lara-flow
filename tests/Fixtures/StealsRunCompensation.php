<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Action;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;

/**
 * Compensation that takes the run away from the rollback executing it: the row
 * leaves Cancelling while its own compensations are still running, which is the
 * one window in which a rollback can lose its landing.
 */
final class StealsRunCompensation extends Action
{
    /**
     * @return array{stolen: string}
     */
    public function handle(string $label): array
    {
        FlowRun::query()
            ->where('status', FlowStatus::Cancelling)
            ->update(['status' => FlowStatus::Failed, 'finished_at' => now()]);

        return ['stolen' => $label];
    }
}
