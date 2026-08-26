<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Workflow;

/**
 * Child workflow returning an Eloquent model, which the serializer stores as a
 * reference array rather than a plain value.
 */
final class ModelChildWorkflow extends Workflow
{
    public function handle(): FlowRun
    {
        $row = new FlowRun;
        $row->fill(['workflow_class' => 'marker', 'status' => FlowStatus::Pending]);
        $row->save();

        return $row;
    }
}
