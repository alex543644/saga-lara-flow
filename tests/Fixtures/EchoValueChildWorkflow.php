<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Child workflow that returns whatever it was given, so a test can drive any
 * return shape through the child seam from the parent's arguments.
 */
final class EchoValueChildWorkflow extends Workflow
{
    public function handle(mixed $value = null): mixed
    {
        return $value;
    }
}
