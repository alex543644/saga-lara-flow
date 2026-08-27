<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Action;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;

/**
 * Compensation that takes the run away from the rollback executing it: the row leaves
 * Cancelling while its own compensations are still running, which is the one window in
 * which a rollback can lose its landing. $leaveAs chooses what the thief leaves behind,
 * so a test can stage both a closed run and one that is still live.
 */
final class StealsRunCompensation extends Action
{
    public static FlowStatus $leaveAs = FlowStatus::Failed;

    public static function reset(): void
    {
        self::$leaveAs = FlowStatus::Failed;
    }

    /**
     * @return array{stolen: string}
     */
    public function handle(string $label): array
    {
        FlowRun::query()
            ->where('status', FlowStatus::Cancelling)
            ->update([
                'status' => self::$leaveAs,
                'finished_at' => self::$leaveAs->isTerminal() ? now() : null,
            ]);

        return ['stolen' => $label];
    }
}
