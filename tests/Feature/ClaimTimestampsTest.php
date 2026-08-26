<?php

use DiscoveryUkraine\SagaLaraFlow\Contracts\FlowRepository;
use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\CompensationStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use DiscoveryUkraine\SagaLaraFlow\Models\CompensationRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionRecorder;
use DiscoveryUkraine\SagaLaraFlow\Runtime\CompensationRecorder;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\MakeValueAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\OneActionWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\UndoAction;

/**
 * The claim and the outcome writes are conditional UPDATEs rather than save(), which
 * is a fair place to wonder whether updated_at still moves. It does: they go through
 * an Eloquent builder, whose update() adds the timestamp column for a model that uses
 * timestamps. These tests pin that, so the row's "last written" never silently
 * freezes if one of them is ever rewritten against the base query builder.
 */
function timestampRun(): string
{
    return app(FlowRepository::class)->create([
        'workflow_class' => OneActionWorkflow::class,
        'status' => FlowStatus::Waiting,
        'arguments' => [],
    ])->id;
}

it('moves updated_at when a step is claimed and its outcome recorded', function () {
    $recorder = app(ActionRecorder::class);

    $action = ActionRun::create([
        'flow_run_id' => timestampRun(),
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Pending,
        'attempts' => 0,
    ]);

    ActionRun::query()->whereKey($action->id)->update(['updated_at' => now()->subDay()]);
    $stale = ActionRun::query()->findOrFail($action->id)->updated_at;

    expect($recorder->startAction($action->fresh()))->toBeTrue()
        ->and(ActionRun::query()->findOrFail($action->id)->updated_at->isAfter($stale))->toBeTrue();

    $claimed = ActionRun::query()->findOrFail($action->id);
    ActionRun::query()->whereKey($action->id)->update(['updated_at' => now()->subDay()]);

    expect($recorder->completeAction($claimed, ['label' => 'ok']))->toBeTrue()
        ->and(ActionRun::query()->findOrFail($action->id)->updated_at->isAfter($stale))->toBeTrue();
});

it('moves updated_at when the monitor expires a step', function () {
    $recorder = app(ActionRecorder::class);

    $action = ActionRun::create([
        'flow_run_id' => timestampRun(),
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Pending,
        'attempts' => 0,
    ]);

    ActionRun::query()->whereKey($action->id)->update(['updated_at' => now()->subDay()]);
    $stale = ActionRun::query()->findOrFail($action->id)->updated_at;

    expect($recorder->expireAction($action->fresh(), ['message' => 'deadline']))->toBeTrue()
        ->and(ActionRun::query()->findOrFail($action->id)->updated_at->isAfter($stale))->toBeTrue();
});

it('moves updated_at when a compensation is claimed and settled', function () {
    $recorder = app(CompensationRecorder::class);

    $compensation = CompensationRun::create([
        'flow_run_id' => timestampRun(),
        'sequence' => 0,
        'compensation_type' => 'class',
        'compensation_class' => UndoAction::class,
        'status' => CompensationStatus::Pending,
        'attempts' => 0,
    ]);

    CompensationRun::query()->whereKey($compensation->id)->update(['updated_at' => now()->subDay()]);
    $stale = CompensationRun::query()->findOrFail($compensation->id)->updated_at;

    expect($recorder->startCompensation($compensation->fresh()))->toBeTrue()
        ->and(CompensationRun::query()->findOrFail($compensation->id)->updated_at->isAfter($stale))->toBeTrue();

    $claimed = CompensationRun::query()->findOrFail($compensation->id);
    CompensationRun::query()->whereKey($compensation->id)->update(['updated_at' => now()->subDay()]);

    expect($recorder->completeCompensation($claimed, 'undone'))->toBeTrue()
        ->and(CompensationRun::query()->findOrFail($compensation->id)->updated_at->isAfter($stale))->toBeTrue();
});
