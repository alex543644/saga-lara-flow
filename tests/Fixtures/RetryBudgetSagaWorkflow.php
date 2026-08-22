<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;
use Throwable;

/**
 * Two compensatable steps ahead of a retryOnSignal step that never succeeds, so a
 * test can watch the give-up after the budget is spent roll the saga back in
 * reverse order.
 */
final class RetryBudgetSagaWorkflow extends Workflow
{
    /**
     * @throws Throwable
     */
    public function handle(string $orderId, ?int $maxRetries = null): mixed
    {
        $this->action(MakeValueAction::class, 'reserved')
            ->compensateWith(UndoAction::class, 'reserved')
            ->run();

        $this->action(MakeValueAction::class, 'packed')
            ->compensateWith(UndoAction::class, 'packed')
            ->run();

        return $this->action(FlakyPaymentAction::class, $orderId)
            ->compensateWith(UndoAction::class, 'charged')
            ->retryOnSignal('balance-refilled', maxRetries: $maxRetries)
            ->run();
    }
}
