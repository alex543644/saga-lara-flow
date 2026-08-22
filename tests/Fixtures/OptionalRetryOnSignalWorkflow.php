<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;
use Throwable;

/**
 * An optional step that also carries a retry policy. The fallback must only be
 * reached once the retry budget is spent — retryOnSignal sits between the queue's
 * own attempts and continueOnFailure in the layering of failure handling.
 */
final class OptionalRetryOnSignalWorkflow extends Workflow
{
    /**
     * @return array{charged: mixed, shipped: array{label: string}}
     *
     * @throws Throwable
     */
    public function handle(string $orderId, ?int $maxRetries = null): array
    {
        $charged = $this->action(FlakyPaymentAction::class, $orderId)
            ->continueOnFailure()
            ->fallbackValueOnFail('unpaid')
            ->retryOnSignal('balance-refilled', maxRetries: $maxRetries)
            ->run();

        $shipped = $this->action(MakeValueAction::class, 'shipped')->run();

        return ['charged' => $charged, 'shipped' => $shipped];
    }
}
