<?php

use DiscoveryUkraine\SagaLaraFlow\Contracts\ActionRunRepository;
use DiscoveryUkraine\SagaLaraFlow\Contracts\FlowRepository;
use DiscoveryUkraine\SagaLaraFlow\Contracts\SignalRepository;
use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\RunMode;
use DiscoveryUkraine\SagaLaraFlow\Enums\SignalStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowSignal;
use DiscoveryUkraine\SagaLaraFlow\Runtime\FlowDoctor;
use DiscoveryUkraine\SagaLaraFlow\Runtime\FlowExecutor;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\MakeValueAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\OneActionWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * No sweep may take work that belongs to a run which has already finished. Terminal
 * settlement leaves only rows written before it existed, so these tests stage them
 * straight into the database — the engine can no longer produce one.
 *
 * They matter because every sweep rejects such a row *after* spending a slot on it and
 * *before* any counter holds it off: the doctor returns ahead of its throttle, and the
 * monitor has no counter at all. Ordered oldest-first and never changing, one sits at
 * the head of every batch for ever, starving the sweep of live candidates.
 */
function runWith(FlowStatus $status): FlowRun
{
    return app(FlowRepository::class)->create([
        'workflow_class' => OneActionWorkflow::class,
        'status' => $status,
        'arguments' => [],
    ]);
}

/**
 * A step that outlived the run it belongs to. Written directly, the way a row that
 * predates the settlement looks.
 *
 * @param  array<string, mixed>  $attributes
 */
function strandedStep(FlowStatus $runStatus, ActionStatus $stepStatus, array $attributes = []): ActionRun
{
    $step = ActionRun::create([
        'flow_run_id' => runWith($runStatus)->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => $stepStatus,
        'attempts' => 0,
        ...$attributes,
    ]);

    ActionRun::query()->whereKey($step->id)->update(['created_at' => now()->subMinutes(10)]);

    return $step->fresh();
}

/**
 * A wait-signal that outlived the run it belongs to, with its deadline already past.
 */
function strandedWait(FlowStatus $runStatus): FlowSignal
{
    return FlowSignal::create([
        'flow_run_id' => runWith($runStatus)->id,
        'name' => 'approval',
        'status' => SignalStatus::Waiting,
        'wait_sequence' => 0,
        'timeout_at' => now()->subMinute(),
    ]);
}

it('does not offer the doctor a lost action whose run has finished', function () {
    $stranded = strandedStep(FlowStatus::Cancelled, ActionStatus::Pending);
    $live = strandedStep(FlowStatus::Waiting, ActionStatus::Pending);

    $due = collect(app(ActionRunRepository::class)->dueForRepair(100, 60, 10))->pluck('id')->all();

    expect($due)->toBe([$live->id])
        ->and($due)->not->toContain($stranded->id);
});

it('does not offer the doctor a stale running action whose run has finished', function () {
    $attributes = ['reclaim_stale_at' => now()->subMinute()];

    $stranded = strandedStep(FlowStatus::Expired, ActionStatus::Running, $attributes);
    $live = strandedStep(FlowStatus::Running, ActionStatus::Running, $attributes);

    $due = collect(app(ActionRunRepository::class)->dueForStaleRunningRepair(100, 10))->pluck('id')->all();

    expect($due)->toBe([$live->id])
        ->and($due)->not->toContain($stranded->id);
});

it('does not offer the monitor an overdue action whose run has finished', function () {
    $attributes = ['expires_at' => now()->subMinute()];

    $stranded = strandedStep(FlowStatus::Failed, ActionStatus::Pending, $attributes);
    $live = strandedStep(FlowStatus::Waiting, ActionStatus::Pending, $attributes);

    $due = collect(app(ActionRunRepository::class)->dueForExpiration(100))->pluck('id')->all();

    expect($due)->toBe([$live->id])
        ->and($due)->not->toContain($stranded->id);
});

it('does not offer the monitor an overdue wait whose run has finished', function () {
    $stranded = strandedWait(FlowStatus::Cancelled);
    $live = strandedWait(FlowStatus::Waiting);

    $due = collect(app(SignalRepository::class)->dueForTimeout(100))->pluck('id')->all();

    expect($due)->toBe([$live->id])
        ->and($due)->not->toContain($stranded->id);
});

it('repairs a genuinely stuck action a batch of stranded ones would have crowded out', function () {
    useDatabaseQueue();

    config()->set('saga-lara-flow.repair.enabled', true);
    config()->set('saga-lara-flow.repair.batch_size', 2);

    // Older than the stuck step below, so an unfiltered batch — ordered oldest-first
    // and capped at two — would be filled entirely by these and never reach it.
    foreach (range(1, 3) as $ignored) {
        strandedStep(FlowStatus::Cancelled, ActionStatus::Pending);
    }

    $run = app(FlowExecutor::class)->drive(runWith(FlowStatus::Pending), RunMode::Queued);

    DB::connection('testing')->table('jobs')->delete();

    $stuck = $run->actions()->first();

    ActionRun::query()->whereKey($stuck->id)->update(['created_at' => now()->subMinutes(2)]);

    expect(app(FlowDoctor::class)->repair()->redispatchedActions)->toBe(1)
        ->and($stuck->fresh()->repair_attempts)->toBe(1);
});
