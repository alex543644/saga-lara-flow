<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Awaits a child, then parks on a signal, so resuming replays the child seam a
 * second time against whatever the child row holds by then.
 */
final class ChildThenSignalWorkflow extends Workflow
{
    /**
     * @return array{type: string, received: mixed}
     */
    public function handle(): array
    {
        $received = $this->child(EchoValueChildWorkflow::class, [42])->run();

        $this->awaitSignal('go');

        return ['type' => get_debug_type($received), 'received' => $received];
    }
}
