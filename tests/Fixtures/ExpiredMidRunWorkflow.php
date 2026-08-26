<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Sync workflow whose only step is expired by the monitor while it runs. The step is
 * required, so replay must surface the expiry as a business failure.
 */
final class ExpiredMidRunWorkflow extends Workflow
{
    public function handle(): string
    {
        return $this->action(ExpiredMidRunAction::class)->run();
    }
}
