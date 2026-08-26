<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Parent that hands its own argument to a child and reports what came back,
 * type included, so a test can assert the shape the child seam resolves.
 */
final class ChildValueRelayWorkflow extends Workflow
{
    /**
     * @return array{type: string, received: mixed}
     */
    public function handle(mixed $value = null): array
    {
        $received = $this->child(EchoValueChildWorkflow::class, [$value])->run();

        return ['type' => get_debug_type($received), 'received' => $received];
    }
}
