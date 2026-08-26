<?php

use DiscoveryUkraine\SagaLaraFlow\Contracts\FlowRepository;
use DiscoveryUkraine\SagaLaraFlow\Contracts\Serializer;
use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\CompensationStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\StepExecution;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionCompleted;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionFailed;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionStarted;
use DiscoveryUkraine\SagaLaraFlow\Events\CompensationCompleted;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\FlowExpiredException;
use DiscoveryUkraine\SagaLaraFlow\Facades\SagaFlow;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use DiscoveryUkraine\SagaLaraFlow\Models\CompensationRun;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionDispatcher;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionRecorder;
use DiscoveryUkraine\SagaLaraFlow\Runtime\CompensationRecorder;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\ExpiredMidRunWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\MakeValueAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\OneActionWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\OptionalExpiredMidRunWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\ReclaimedMidRunAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\UndoAction;
use Illuminate\Support\Facades\Event;

/**
 * The claim decides who may START a step; these tests cover who may FINISH one.
 *
 * Reclaim deliberately allows a second worker to take over a row whose first worker
 * may be slow rather than dead — the queue driver can redeliver a job while the
 * original attempt is still alive, and no engine can prevent that. What it can
 * prevent is the superseded executor writing its outcome over the live one's, which
 * would record the opposite result and send the workflow down the wrong branch.
 * `attempts` is incremented by the claim and nothing else, so it identifies the
 * claim that an outcome write must still belong to.
 */
function fencedRun(): FlowRun
{
    return app(FlowRepository::class)->create([
        'workflow_class' => OneActionWorkflow::class,
        'status' => FlowStatus::Waiting,
        'arguments' => [],
    ]);
}

/**
 * A row already claimed once, with its reclaim deadline in the past so a second
 * claim is legal — the state reclaim exists to recover from.
 */
function reclaimableAction(FlowRun $run, string $actionClass = MakeValueAction::class): ActionRun
{
    return ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => $actionClass,
        'status' => ActionStatus::Pending,
        'reclaim_stale_after_seconds' => 900,
        'attempts' => 0,
    ]);
}

it('refuses an outcome write from an executor whose row was reclaimed', function () {
    $run = fencedRun();
    $recorder = app(ActionRecorder::class);

    $workerA = reclaimableAction($run);

    expect($recorder->startAction($workerA))->toBeTrue()
        ->and($workerA->attempts)->toBe(1);

    // The reclaim window passes and worker B takes the row over.
    ActionRun::query()->whereKey($workerA->id)->update(['reclaim_stale_at' => now()->subMinute()]);

    $workerB = ActionRun::query()->findOrFail($workerA->id);

    expect($recorder->startAction($workerB))->toBeTrue()
        ->and($workerB->attempts)->toBe(2);

    // Worker A finally finishes. Its result belongs to a claim that is over.
    Event::fake([ActionCompleted::class]);

    expect($recorder->completeAction($workerA, ['label' => 'stale']))->toBeFalse()
        ->and($workerA->fresh()->status)->toBe(ActionStatus::Running)
        ->and($workerA->fresh()->result)->toBeNull();

    Event::assertNotDispatched(ActionCompleted::class);
});

it('never lets a superseded failure overwrite the live worker\'s success', function () {
    $run = fencedRun();
    $recorder = app(ActionRecorder::class);

    $workerA = reclaimableAction($run);
    $recorder->startAction($workerA);

    ActionRun::query()->whereKey($workerA->id)->update(['reclaim_stale_at' => now()->subMinute()]);

    $workerB = ActionRun::query()->findOrFail($workerA->id);
    $recorder->startAction($workerB);

    // B is the current owner and succeeds.
    expect($recorder->completeAction($workerB, ['label' => 'real']))->toBeTrue();

    // A, still alive but superseded, then throws. Without fencing this would demote
    // a completed step to Failed and roll the saga back over a step that succeeded.
    Event::fake([ActionFailed::class]);

    expect($recorder->failAction($workerA, new RuntimeException('zombie')))->toBeFalse()
        ->and($workerA->fresh()->status)->toBe(ActionStatus::Completed)
        ->and($workerA->fresh()->result)->toBe(['label' => 'real'])
        ->and($workerA->fresh()->exception)->toBeNull();

    Event::assertNotDispatched(ActionFailed::class);
});

it('lets the current owner record its outcome normally', function () {
    $run = fencedRun();
    $recorder = app(ActionRecorder::class);

    $action = reclaimableAction($run);

    expect($recorder->startAction($action))->toBeTrue()
        ->and($recorder->completeAction($action, ['label' => 'ok']))->toBeTrue()
        ->and($action->fresh()->status)->toBe(ActionStatus::Completed)
        ->and($action->fresh()->result)->toBe(['label' => 'ok']);
});

it('reports a reclaimed row as not executed rather than throwing, when the step succeeded', function () {
    $run = fencedRun();

    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => ReclaimedMidRunAction::class,
        'status' => ActionStatus::Pending,
        'attempts' => 0,
    ]);

    $action->arguments = app(Serializer::class)
        ->serialize([$action->id, false]);
    $action->save();

    Event::fake([ActionCompleted::class]);

    // Superseded, not ClaimLost: this executor did own the row and did run the step —
    // only its result was refused.
    expect(app(ActionDispatcher::class)->execute($action))->toBe(StepExecution::Superseded)
        ->and($action->fresh()->status)->toBe(ActionStatus::Running);

    Event::assertNotDispatched(ActionCompleted::class);
});

it('swallows the exception of a reclaimed row rather than failing the job', function () {
    $run = fencedRun();

    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => ReclaimedMidRunAction::class,
        'status' => ActionStatus::Pending,
        'attempts' => 0,
    ]);

    $action->arguments = app(Serializer::class)
        ->serialize([$action->id, true]);
    $action->save();

    // Rethrowing would fail a job whose work was already discarded, and
    // RunActionJob::failed() would then write queue bookkeeping into a row owned by
    // somebody else.
    expect(app(ActionDispatcher::class)->execute($action))->toBe(StepExecution::Superseded)
        ->and($action->fresh()->status)->toBe(ActionStatus::Running)
        ->and($action->fresh()->exception)->toBeNull();
});

it('fences a compensation outcome the same way', function () {
    $run = fencedRun();
    $recorder = app(CompensationRecorder::class);

    $workerA = CompensationRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'compensation_type' => 'class',
        'compensation_class' => UndoAction::class,
        'status' => CompensationStatus::Pending,
        'reclaim_stale_after_seconds' => 900,
        'attempts' => 0,
    ]);

    expect($recorder->startCompensation($workerA))->toBeTrue()
        ->and($workerA->attempts)->toBe(1);

    CompensationRun::query()->whereKey($workerA->id)->update(['reclaim_stale_at' => now()->subMinute()]);

    $workerB = CompensationRun::query()->findOrFail($workerA->id);

    expect($recorder->startCompensation($workerB))->toBeTrue()
        ->and($workerB->attempts)->toBe(2);

    Event::fake([CompensationCompleted::class]);

    expect($recorder->completeCompensation($workerA, 'stale'))->toBeFalse()
        ->and($workerA->fresh()->status)->toBe(CompensationStatus::Running);

    Event::assertNotDispatched(CompensationCompleted::class);
});

// --- The other rival: a settlement that never claimed the row ---
//
// The monitor expires an overdue step without ever claiming it, so it does not touch
// `attempts` and the fence alone cannot see it. Both orders of that race have to be
// covered: the sweep landing first, and the worker landing first.

it('refuses an outcome write onto a row the monitor already expired', function () {
    $run = fencedRun();
    $recorder = app(ActionRecorder::class);

    $action = reclaimableAction($run);
    $recorder->startAction($action);

    // The sweep settles the overdue step while this worker is still executing. It
    // claims nothing, so `attempts` is untouched and still matches the worker's.
    expect($recorder->expireAction($action->fresh(), ['message' => 'deadline']))->toBeTrue();

    Event::fake([ActionCompleted::class]);

    expect($recorder->completeAction($action, ['label' => 'late']))->toBeFalse()
        ->and($action->fresh()->status)->toBe(ActionStatus::Expired)
        ->and($action->fresh()->result)->toBeNull();

    Event::assertNotDispatched(ActionCompleted::class);
});

it('refuses to expire a step that finished just before the sweep reached it', function () {
    $run = fencedRun();
    $recorder = app(ActionRecorder::class);

    $action = reclaimableAction($run);
    $recorder->startAction($action);

    // What the sweep read a moment ago: still Running, deadline passed.
    $sweepView = ActionRun::query()->findOrFail($action->id);

    expect($recorder->completeAction($action, ['label' => 'just in time']))->toBeTrue();

    // Demoting this to Expired would fail a run over work that actually succeeded.
    expect($recorder->expireAction($sweepView, ['message' => 'deadline']))->toBeFalse()
        ->and($action->fresh()->status)->toBe(ActionStatus::Completed)
        ->and($action->fresh()->result)->toBe(['label' => 'just in time'])
        ->and($action->fresh()->exception)->toBeNull();
});

// --- Being superseded is not the same as never having the row ---
//
// Sync execution has no competitor between scheduling a step and starting it, so a
// lost claim there is a broken invariant and says so loudly. Losing the row *after*
// the work ran is a different thing entirely — the monitor and the doctor sweep the
// rows a sync run creates from their own processes — and replay resolves it.

it('lets replay resolve a sync step the monitor expired mid-run', function () {
    $run = SagaFlow::create(ExpiredMidRunWorkflow::class)->runSync();

    // Not ActionClaimFailedException: the run fails on the expiry the monitor
    // recorded, exactly as it would had the sweep landed a moment earlier.
    expect($run->status)->toBe(FlowStatus::Failed)
        ->and($run->exception['class'])->toBe(FlowExpiredException::class)
        ->and($run->actions()->first()->status)->toBe(ActionStatus::Expired);
});

it('returns the fallback when an optional sync step is expired mid-run', function () {
    $run = SagaFlow::create(OptionalExpiredMidRunWorkflow::class)->runSync();

    // The whole point of distinguishing the two: an optional step resolves its
    // fallback on replay, which a thrown invariant error would have skipped.
    // A scalar run result is stored wrapped (FlowExecutor::normalizeResult()).
    expect($run->status)->toBe(FlowStatus::Completed)
        ->and($run->result)->toBe(['value' => 'fallback']);
});

// --- The claim and its records are one unit ---

it('releases the row when a listener throws while the claim is being recorded', function () {
    $run = fencedRun();

    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Pending,
        'attempts' => 0,
    ]);

    Event::listen(ActionStarted::class, function (): void {
        throw new RuntimeException('listener boom');
    });

    // Without the enclosing transaction the UPDATE would already be committed here,
    // leaving a Running row that nothing ever executes and — with reclaim off, the
    // default — nothing can ever pick up again.
    expect(fn () => app(ActionRecorder::class)->startAction($action))
        ->toThrow(RuntimeException::class, 'listener boom');

    expect($action->fresh()->status)->toBe(ActionStatus::Pending)
        ->and($action->fresh()->attempts)->toBe(0)
        ->and($action->fresh()->started_at)->toBeNull();
});
