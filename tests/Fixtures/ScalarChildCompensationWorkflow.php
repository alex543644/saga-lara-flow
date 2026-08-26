<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Registers a compensation carrying a completed child's result, then parks. A
 * manual compensate() rebuilds the stack through the collecting replay, which
 * resolves the child on its own branch of the seam.
 */
final class ScalarChildCompensationWorkflow extends Workflow
{
    public function handle(): void
    {
        $value = $this->child(EchoValueChildWorkflow::class, [42])->run();

        $this->action(MakeValueAction::class, 'a')
            ->compensateWith(RecordChildResultCompensation::class, $value)
            ->run();

        $this->awaitSignal('go');
    }
}
