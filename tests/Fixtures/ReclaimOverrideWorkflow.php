<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Exercises the per-action and per-compensation reclaim overrides
 * (reclaimStaleAfter()/enableStaleReclaim() and their compensation-side mirrors) so
 * a test can inspect the resolved reclaim_stale_after_seconds column without
 * reaching into the builder's private state. The second step always fails, forcing
 * a real rollback so the registered compensation actually gets its own
 * CompensationRun row to inspect — merely registering it (a completed step that is
 * never rolled back) never creates one.
 */
final class ReclaimOverrideWorkflow extends Workflow
{
    public function handle(
        ?int $seconds = null,
        ?bool $enabled = null,
        ?int $compensationSeconds = null,
        ?bool $compensationEnabled = null,
    ): void {
        $action = $this->action(MakeValueAction::class, 'x')
            ->compensateWith(UndoAction::class, 'x');

        if ($seconds !== null) {
            $action->reclaimStaleAfter($seconds);
        }

        if ($enabled !== null) {
            $action->enableStaleReclaim($enabled);
        }

        if ($compensationSeconds !== null) {
            $action->reclaimCompensationStaleAfter($compensationSeconds);
        }

        if ($compensationEnabled !== null) {
            $action->enableCompensationStaleReclaim($compensationEnabled);
        }

        $action->run();

        $this->action(ThrowingAction::class)->run();
    }
}
