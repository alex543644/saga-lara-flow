<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Action;
use RuntimeException;

/**
 * A payment step that always fails and asks the queue for two attempts of its own.
 * Used to prove that a signal-gated retry cycle is not spent while Laravel still
 * owes the step a native attempt.
 */
final class UnreliablePaymentAction extends Action
{
    public int $tries = 2;

    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    /**
     * @return array{charged: string}
     */
    public function handle(string $orderId): array
    {
        self::$calls++;

        throw new RuntimeException('insufficient balance');
    }
}
