<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;
use Throwable;

/**
 * A single retryOnSignal step whose action carries its own queue $tries, so a test
 * can drive the run to the state where one native attempt has failed and another is
 * still owed.
 */
final class UnreliableRetryOnSignalWorkflow extends Workflow
{
    /**
     * @throws Throwable
     */
    public function handle(string $orderId): mixed
    {
        return $this->action(UnreliablePaymentAction::class, $orderId)
            ->retryOnSignal('balance-refilled')
            ->run();
    }
}
