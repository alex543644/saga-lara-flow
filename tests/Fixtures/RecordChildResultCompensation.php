<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Action;

/**
 * Compensation that records the type of the value it was registered with, so a
 * test can see which shape the compensation-only replay resolved a child to.
 */
final class RecordChildResultCompensation extends Action
{
    /**
     * @return array{recorded: string}
     */
    public function handle(mixed $value): array
    {
        CompensationLog::record('child:'.get_debug_type($value));

        return ['recorded' => get_debug_type($value)];
    }
}
