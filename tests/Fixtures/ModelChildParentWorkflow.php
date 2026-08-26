<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Parent awaiting a model-returning child, reporting the class it received.
 */
final class ModelChildParentWorkflow extends Workflow
{
    /**
     * @return array{type: string}
     */
    public function handle(): array
    {
        return ['type' => get_debug_type($this->child(ModelChildWorkflow::class)->run())];
    }
}
