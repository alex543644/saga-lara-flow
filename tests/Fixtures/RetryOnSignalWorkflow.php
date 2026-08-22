<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;
use Throwable;

/**
 * A three-step saga whose middle step charges a card and parks on a
 * 'balance-refilled' signal instead of failing the run. The surrounding steps are
 * compensatable, so a test can prove that parking rolls nothing back and that a
 * give-up rolls back only the steps that actually completed.
 *
 * $maxRetries, $only and $waitSeconds are workflow arguments (deterministic across
 * replays) so a single fixture can exercise the budget, the exception filter and
 * the wait deadline.
 */
final class RetryOnSignalWorkflow extends Workflow
{
    /**
     * @param  list<class-string<Throwable>>|null  $only
     * @return array{created: mixed, charged: mixed, shipped: mixed}
     *
     * @throws Throwable
     */
    public function handle(
        string $orderId,
        ?int $maxRetries = null,
        ?array $only = null,
        ?int $waitSeconds = null,
    ): array {
        $created = $this->action(MakeValueAction::class, 'created')
            ->compensateWith(UndoAction::class, 'created')
            ->run();

        $charged = $this->action(FlakyPaymentAction::class, $orderId)
            ->compensateWith(UndoAction::class, 'charged')
            ->retryOnSignal(
                'balance-refilled',
                maxRetries: $maxRetries,
                waitSeconds: $waitSeconds,
                only: $only,
            )
            ->run();

        $shipped = $this->action(MakeValueAction::class, 'shipped')->run();

        return ['created' => $created, 'charged' => $charged, 'shipped' => $shipped];
    }
}
