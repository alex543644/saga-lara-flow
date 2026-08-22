<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Action;
use RuntimeException;

/**
 * A payment step that fails its first $failures calls and then succeeds, so a test
 * can drive a step through several signal-gated retry cycles. The counters are
 * static (like SideEffectCounter) because the retries happen across separate
 * replays — and, in queued mode, separate jobs — of the same run. Reset per test.
 */
final class FlakyPaymentAction extends Action
{
    public static int $failures = 0;

    public static int $calls = 0;

    public static function reset(int $failures = 0): void
    {
        self::$failures = $failures;
        self::$calls = 0;
    }

    /**
     * @return array{charged: string, calls: int}
     */
    public function handle(string $orderId): array
    {
        self::$calls++;

        if (self::$calls <= self::$failures) {
            throw new RuntimeException('insufficient balance');
        }

        return ['charged' => $orderId, 'calls' => self::$calls];
    }
}
