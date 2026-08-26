<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * The optional twin of ExpiredMidRunWorkflow: replay must resolve the expiry into the
 * step's fallback rather than failing the run.
 */
final class OptionalExpiredMidRunWorkflow extends Workflow
{
    public function handle(): string
    {
        return $this->action(ExpiredMidRunAction::class)
            ->continueOnFailure()
            ->fallbackValueOnFail('fallback')
            ->run();
    }
}
