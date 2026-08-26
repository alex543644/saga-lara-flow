<?php

use DiscoveryUkraine\SagaLaraFlow\Contracts\FlowRepository;
use DiscoveryUkraine\SagaLaraFlow\Data\CompensationDefinition;
use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\CompensationStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\StepExecution;
use DiscoveryUkraine\SagaLaraFlow\Events\ActionStarted;
use DiscoveryUkraine\SagaLaraFlow\Events\CompensationStarted;
use DiscoveryUkraine\SagaLaraFlow\Events\CompensationStepStarted;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use DiscoveryUkraine\SagaLaraFlow\Models\CompensationRun;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionDispatcher;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionRecorder;
use DiscoveryUkraine\SagaLaraFlow\Runtime\CompensationExecutor;
use DiscoveryUkraine\SagaLaraFlow\Runtime\CompensationRecorder;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\CompensationLog;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\MakeValueAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\OneActionWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\UndoAction;
use Illuminate\Support\Facades\Event;

/**
 * Issue #13: RunActionJob (and RunCompensationJob) had no atomic claim between
 * reading a step and executing it. These tests exercise ActionRecorder::startAction()
 * and CompensationRecorder::startCompensation() directly — the same style already
 * used in RetryOnSignalQueuedTest for SignalRecorder's compare-and-swap — rather than
 * spinning up real concurrency, to force each race deterministically.
 */
function stagedFlowRun(): FlowRun
{
    return app(FlowRepository::class)->create([
        'workflow_class' => OneActionWorkflow::class,
        'status' => FlowStatus::Waiting,
        'arguments' => [],
    ]);
}

it('fails to claim an action whose retry cycle has moved on since the job was dispatched', function () {
    $run = stagedFlowRun();

    // The row has already been rewound to cycle 1 (e.g. a signal arrived and
    // retryAction() ran) by the time this call happens.
    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Failed,
        'retry_signal_attempts' => 1,
        'attempts' => 1,
    ]);

    // A stale job, dispatched for cycle 0 before the rewind, tries to claim it.
    $claimed = app(ActionRecorder::class)->startAction($action, expectedRetryGeneration: 0);

    expect($claimed)->toBeFalse()
        ->and($action->fresh()->status)->toBe(ActionStatus::Failed)
        ->and($action->fresh()->attempts)->toBe(1)
        ->and($action->fresh()->retry_signal_attempts)->toBe(1);
});

it('claims an action normally when the retry cycle still matches what the job was dispatched for', function () {
    $run = stagedFlowRun();

    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Pending,
        'retry_signal_attempts' => 1,
        'attempts' => 0,
    ]);

    $claimed = app(ActionRecorder::class)->startAction($action, expectedRetryGeneration: 1);

    expect($claimed)->toBeTrue()
        ->and($action->fresh()->status)->toBe(ActionStatus::Running)
        ->and($action->fresh()->attempts)->toBe(1);
});

it('fails to claim an action the monitor has already marked Expired', function () {
    $run = stagedFlowRun();

    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Expired,
        'attempts' => 1,
    ]);

    $claimed = app(ActionDispatcher::class)->execute($action);

    expect($claimed)->toBe(StepExecution::ClaimLost)
        ->and($action->fresh()->status)->toBe(ActionStatus::Expired)
        ->and($action->fresh()->attempts)->toBe(1);
});

it('still lets a job reclaim a Failed row within the same cycle for Laravel\'s own native $tries', function () {
    // Failed is not terminal here: it is what the row shows between two of the
    // action's own native attempts. Laravel redelivers the very same job (same
    // generation) until $tries is exhausted, and each redelivery must still be able
    // to claim the row — this must NOT regress back to treating Failed as off-limits.
    $run = stagedFlowRun();

    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Failed,
        'retry_signal_attempts' => 0,
        'attempts' => 1,
    ]);

    $claimed = app(ActionRecorder::class)->startAction($action, expectedRetryGeneration: 0);

    expect($claimed)->toBeTrue()
        ->and($action->fresh()->status)->toBe(ActionStatus::Running)
        ->and($action->fresh()->attempts)->toBe(2);
});

it('never fires ActionStarted or records action.started when the claim fails', function () {
    $run = stagedFlowRun();

    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Completed,
        'attempts' => 1,
    ]);

    Event::fake();

    expect(app(ActionRecorder::class)->startAction($action))->toBeFalse();

    Event::assertNotDispatched(ActionStarted::class);
    expect($run->events()->where('type', 'action.started')->count())->toBe(0);
});

// The sync-mode throw in ActionDispatcher::runInline() / ActionBuilder::startRetriedStep()
// (ActionClaimFailedException — see tests/Unit/ActionClaimFailedExceptionTest.php for the
// exception's own message) guards a branch that is unreachable through the public API by
// construction: both callers claim a row they just scheduled or just rewound to Pending in
// the very same call, so nothing else can race it. Provable by inspection rather than by a
// test that would need to fabricate an otherwise-impossible database state.

// --- Compensations: the identical bug in CompensationRecorder::startCompensation() ---

it('fails to claim a compensation already settled (Completed) elsewhere', function () {
    $run = stagedFlowRun();

    $compensation = CompensationRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'compensation_type' => 'class',
        'compensation_class' => UndoAction::class,
        'status' => CompensationStatus::Completed,
        'arguments' => ['x'],
    ]);

    $claimed = app(CompensationRecorder::class)->startCompensation($compensation);

    expect($claimed)->toBeFalse()
        ->and($compensation->fresh()->status)->toBe(CompensationStatus::Completed);
});

it('claims a Pending compensation and fires CompensationStepStarted, distinct from the once-per-run CompensationStarted', function () {
    $run = stagedFlowRun();

    $compensation = CompensationRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'compensation_type' => 'class',
        'compensation_class' => UndoAction::class,
        'status' => CompensationStatus::Pending,
        'arguments' => ['x'],
    ]);

    Event::fake();

    $claimed = app(CompensationRecorder::class)->startCompensation($compensation);

    expect($claimed)->toBeTrue()
        ->and($compensation->fresh()->status)->toBe(CompensationStatus::Running)
        ->and($run->events()->where('type', 'compensation.step_started')->count())->toBe(1);

    Event::assertDispatched(CompensationStepStarted::class);
    Event::assertNotDispatched(CompensationStarted::class);
});

it('runs the compensation body through CompensationExecutor only when the claim succeeds', function () {
    CompensationLog::reset();

    $run = stagedFlowRun();

    $completed = CompensationRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'compensation_type' => 'class',
        'compensation_class' => UndoAction::class,
        'status' => CompensationStatus::Completed,
        'arguments' => ['x'],
    ]);

    $definition = CompensationDefinition::forClass(UndoAction::class, ['x']);

    $ran = app(CompensationExecutor::class)->execute($completed, $definition);

    expect($ran)->toBe(StepExecution::ClaimLost)
        ->and(CompensationLog::all())->toBe([]);
});
