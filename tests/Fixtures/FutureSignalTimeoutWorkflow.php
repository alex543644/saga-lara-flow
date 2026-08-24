<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Exceptions\AwaitSignalTimeoutException;
use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Awaits a signal with a deadline an hour into the future and catches the timeout,
 * so a test can move the clock past the deadline rather than staging a past one.
 * Returns whether the signal actually arrived.
 */
final class FutureSignalTimeoutWorkflow extends Workflow
{
    public function handle(): bool
    {
        try {
            $this->awaitSignal('approval', now()->addHour());

            return true;
        } catch (AwaitSignalTimeoutException) {
            return false;
        }
    }
}
