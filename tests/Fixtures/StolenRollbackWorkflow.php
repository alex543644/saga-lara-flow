<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Completes one compensatable action and parks on a signal, like
 * ManualCompensateWorkflow — but its compensation moves the run out from under the
 * rollback, so a manual compensate() reaches its landing with the run already gone.
 */
final class StolenRollbackWorkflow extends Workflow
{
    public function handle(): void
    {
        $this->action(MakeValueAction::class, 'a')
            ->compensateWith(StealsRunCompensation::class, 'a')
            ->run();

        $this->awaitSignal('go');
    }
}
