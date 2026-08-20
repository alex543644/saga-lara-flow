<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Tags itself from inside handle(), in bulk and one at a time. Covers the runtime
 * tagging API: value casting, valueless tags, and last-write-wins on a repeated key.
 */
final class TaggingWorkflow extends Workflow
{
    public function handle(): void
    {
        $this->tags([
            'priority' => 'low',
            'attempt' => 1,
            'orders' => null,
        ]);

        $this->tag('tenant', 'acme');

        $this->tags(['priority' => 'high']);
    }
}
