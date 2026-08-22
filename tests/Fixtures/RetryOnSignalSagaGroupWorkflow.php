<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;
use Throwable;

/**
 * The same three-step saga as RetryOnSignalWorkflow, written as an explicit
 * saga() group, so a test can prove the SagaStepBuilder mirror of retryOnSignal()
 * reaches the action builder and parks the middle step exactly the same way.
 */
final class RetryOnSignalSagaGroupWorkflow extends Workflow
{
    /**
     * @return list<mixed>
     *
     * @throws Throwable
     */
    public function handle(string $orderId, ?int $maxRetries = null): array
    {
        return $this->saga()
            ->step(MakeValueAction::class, 'created')
            ->compensateWith(UndoAction::class, 'created')
            ->step(FlakyPaymentAction::class, $orderId)
            ->compensateWith(UndoAction::class, 'charged')
            ->retryOnSignal('balance-refilled', maxRetries: $maxRetries)
            ->step(MakeValueAction::class, 'shipped')
            ->run();
    }
}
