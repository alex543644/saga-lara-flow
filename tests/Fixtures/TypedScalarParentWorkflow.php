<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Parent that feeds a child's result straight into an int parameter — the shape
 * an application writes, and the one that raises a TypeError when the seam
 * hands back a wrapper instead of the scalar.
 */
final class TypedScalarParentWorkflow extends Workflow
{
    public function handle(): string
    {
        $id = $this->child(EchoValueChildWorkflow::class, [42])->run();

        return $this->label($id);
    }

    private function label(int $id): string
    {
        return 'id-'.$id;
    }
}
