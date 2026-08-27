<?php

use DiscoveryUkraine\SagaLaraFlow\Contracts\FlowRepository;
use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\RunMode;
use DiscoveryUkraine\SagaLaraFlow\Enums\SignalStatus;
use DiscoveryUkraine\SagaLaraFlow\Facades\SagaFlow;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionRecorder;
use DiscoveryUkraine\SagaLaraFlow\Runtime\FlowExecutor;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\CompensationLog;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\FlakyPaymentAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\OneActionWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\ParentCancelPolicyWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\ParentFailPolicyWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\RetryOnSignalWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\SignalOnlyWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\TwoStepWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\UnwritableActionRun;
use Illuminate\Database\QueryException;

/**
 * A run that reaches a terminal state settles the work it leaves behind: steps that
 * never reached an outcome of their own become Cancelled, and wait-signals nothing
 * will resolve become Cancelled too. Without it a finished run keeps rows that read
 * as live — which lies to every operator surface and to every query a host builds
 * over action_runs.
 *
 * The batches those leftover rows used to starve are covered by
 * TerminalBatchExclusionTest.
 */
beforeEach(function () {
    CompensationLog::reset();
    FlakyPaymentAction::reset();

    config()->set('saga-lara-flow.queue.after_commit', false);
});

/**
 * A run parked on a step whose job has not run: the action row is Pending, which is
 * exactly what a cancel has to settle.
 */
function runHoldingPendingStep(): FlowRun
{
    useDatabaseQueue();

    $run = app(FlowRepository::class)->create([
        'workflow_class' => OneActionWorkflow::class,
        'status' => FlowStatus::Pending,
        'arguments' => [],
    ]);

    $driven = app(FlowExecutor::class)->drive($run, RunMode::Queued);

    expect($driven->status)->toBe(FlowStatus::Waiting)
        ->and($driven->actions()->first()->status)->toBe(ActionStatus::Pending);

    return $driven;
}

/**
 * A run parked by retryOnSignal(): a completed compensatable step at sequence 0 and
 * a step at sequence 1 sitting in awaiting_retry behind an open wait.
 */
function runHoldingParkedStep(): FlowRun
{
    FlakyPaymentAction::reset(failures: 99);

    config()->set('saga-lara-flow.signals.wake_workflow_on_signal', false);

    $run = SagaFlow::create(RetryOnSignalWorkflow::class)->withArguments('order-settle')->runSync();

    expect($run->status)->toBe(FlowStatus::Waiting);

    return $run;
}

/**
 * @return array<int, ActionStatus>
 */
function stepStatuses(string $flowRunId): array
{
    return SagaFlow::findRun($flowRunId)
        ->actions()
        ->orderBy('sequence')
        ->get()
        ->pluck('status')
        ->all();
}

it('settles a pending step when the run is cancelled', function () {
    $run = runHoldingPendingStep();

    SagaFlow::loadFlow($run->id)->cancel('operator');

    expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Cancelled)
        ->and(stepStatuses($run->id))->toBe([ActionStatus::Cancelled]);
});

it('settles a parked step and its open wait, keeping the step that completed', function () {
    $run = runHoldingParkedStep();

    SagaFlow::loadFlow($run->id)->cancel('operator');

    $cancelled = SagaFlow::findRun($run->id);

    // Sequence 0 ran and succeeded; a cancelled run must still show that, or its
    // compensation history stops making sense.
    expect($cancelled->status)->toBe(FlowStatus::Cancelled)
        ->and(stepStatuses($run->id))->toBe([ActionStatus::Completed, ActionStatus::Cancelled])
        ->and($cancelled->signals->pluck('status')->all())->toBe([SignalStatus::Cancelled]);
});

it('keeps the retry policy readable on a settled step', function () {
    $run = runHoldingParkedStep();

    $parked = $run->actions()->where('sequence', 1)->first();

    SagaFlow::loadFlow($run->id)->cancel('operator');

    $settled = SagaFlow::findRun($run->id)->actions()->where('sequence', 1)->first();

    // Only the status is rewritten. finished_at carries the moment the last attempt
    // failed, and overwriting it would erase when the step last really ran; the moment
    // of the closure is on the run itself.
    expect($settled->status)->toBe(ActionStatus::Cancelled)
        ->and($settled->retry_signal)->toBe('balance-refilled')
        ->and($settled->exception['message'] ?? null)->toBe('insufficient balance')
        ->and($settled->finished_at?->toDateTimeString())->toBe($parked->finished_at?->toDateTimeString())
        ->and($settled->finished_at)->not->toBeNull();
});

it('leaves a step that already reached an outcome of its own alone', function (ActionStatus $settled) {
    $run = runHoldingPendingStep();

    ActionRun::query()
        ->where('flow_run_id', $run->id)
        ->update(['status' => $settled]);

    SagaFlow::loadFlow($run->id)->cancel('operator');

    expect(stepStatuses($run->id))->toBe([$settled]);
})->with([
    ActionStatus::Completed,
    ActionStatus::Failed,
    ActionStatus::OptionalFailed,
    ActionStatus::Expired,
]);

it('settles the steps of a run cancelled through compensate()', function () {
    $run = runHoldingParkedStep();

    SagaFlow::loadFlow($run->id)->compensate();

    // Rolling back first changes nothing about the settlement: compensation undoes
    // steps that succeeded, and the parked one never did.
    expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Cancelled)
        ->and(stepStatuses($run->id))->toBe([ActionStatus::Completed, ActionStatus::Cancelled])
        ->and(CompensationLog::all())->toBe(['undo:created']);
});

it('settles the steps of an expired run', function () {
    $run = runHoldingPendingStep();

    $run->expires_at = now()->subMinute();
    $run->save();

    app(FlowExecutor::class)->expireRun($run);

    // The step is Cancelled, not Expired: Expired on a step means the monitor enforced
    // that step's own deadline, and this one never had one.
    expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Expired)
        ->and(stepStatuses($run->id))->toBe([ActionStatus::Cancelled]);
});

it('settles the steps and waits of a child closed under the Cancel policy', function () {
    $run = SagaFlow::create(ParentCancelPolicyWorkflow::class)->runSync();

    expect($run->status)->toBe(FlowStatus::Waiting);

    SagaFlow::loadFlow($run->id)->cancel();

    $child = $run->children()->first()->child->fresh();

    expect($child->status)->toBe(FlowStatus::Cancelled)
        ->and(stepStatuses($child->id))->toBe([ActionStatus::Completed])
        ->and($child->signals->pluck('status')->all())->toBe([SignalStatus::Cancelled]);
});

it('settles the steps and waits of a child closed under the Fail policy', function () {
    $run = SagaFlow::create(ParentFailPolicyWorkflow::class)->runSync();

    expect($run->status)->toBe(FlowStatus::Waiting);

    SagaFlow::loadFlow($run->id)->compensate();

    $child = $run->children()->first()->child->fresh();

    // The child lands in Failed rather than Cancelled, and its open wait is settled
    // all the same: the wait ended with the run, whatever the run's own outcome.
    expect($child->status)->toBe(FlowStatus::Failed)
        ->and($child->signals->pluck('status')->all())->toBe([SignalStatus::Cancelled]);
});

it('leaves a run that completed with nothing to settle', function () {
    $run = SagaFlow::create(TwoStepWorkflow::class)->withArguments('order-complete')->runSync();

    // Replay suspends on the first step that has not finished, so a run cannot reach
    // Completed while holding one. Nothing is settled here, and that is the point.
    expect($run->status)->toBe(FlowStatus::Completed)
        ->and(stepStatuses($run->id))->toBe([ActionStatus::Completed, ActionStatus::Completed]);
});

it('rejects the outcome of a worker that finishes after the run was cancelled', function () {
    $path = sys_get_temp_dir().'/saga-settle-'.uniqid().'.log';

    logToFile($path);

    $run = runHoldingPendingStep();
    $step = $run->actions()->first();

    expect(app(ActionRecorder::class)->startAction($step))->toBeTrue();

    SagaFlow::loadFlow($run->id)->cancel('operator');

    // Settling a Running step fences the worker still holding it, exactly the way the
    // monitor's expiry does: the outcome is refused rather than written over a run
    // that has already finished, and the refusal is logged.
    expect(app(ActionRecorder::class)->completeAction($step, 'too late'))->toBeFalse()
        ->and(stepStatuses($run->id))->toBe([ActionStatus::Cancelled])
        ->and(file_get_contents($path))->toContain('outcome_rejected');
});

it('keeps the outcome of a worker that finished before the run was cancelled', function () {
    $run = runHoldingPendingStep();
    $step = $run->actions()->first();

    expect(app(ActionRecorder::class)->startAction($step))->toBeTrue()
        ->and(app(ActionRecorder::class)->completeAction($step, 'in time'))->toBeTrue();

    SagaFlow::loadFlow($run->id)->cancel('operator');

    // The other side of the same race: the step earned its outcome before the run
    // ended, and the settlement passes over it rather than erasing what it returned.
    expect(stepStatuses($run->id))->toBe([ActionStatus::Completed])
        ->and(SagaFlow::findRun($run->id)->actions()->first()->result)->toBe('in time');
});

it('leaves the run where it was when settling it fails', function () {
    $run = runHoldingParkedStep();

    // A model pointed at a table that does not exist: the settlement fails for real,
    // the way a lost connection or a rejected update would.
    config()->set('saga-lara-flow.models.action_run', UnwritableActionRun::class);

    expect(fn () => SagaFlow::loadFlow($run->id)->cancel('operator'))->toThrow(QueryException::class);

    config()->set('saga-lara-flow.models.action_run', ActionRun::class);

    // The run row goes down with the settlement. A run left terminal but unsettled
    // would read as finished to every surface while its steps stayed live.
    expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Waiting)
        ->and(stepStatuses($run->id))->toBe([ActionStatus::Completed, ActionStatus::AwaitingRetry])
        ->and(SagaFlow::findRun($run->id)->signals->pluck('status')->all())->toBe([SignalStatus::Waiting]);
});

it('leaves the handle that failed to settle able to try again', function () {
    $run = runHoldingParkedStep();
    $handle = SagaFlow::loadFlow($run->id);

    config()->set('saga-lara-flow.models.action_run', UnwritableActionRun::class);

    expect(fn () => $handle->cancel('operator'))->toThrow(QueryException::class);

    // The rollback reaches the caller's instance too. A model still carrying the
    // cancellation the database refused would report a terminal run to the operator
    // holding it, and cancel() would turn their retry into CannotCancelTerminalFlow.
    expect($handle->status())->toBe(FlowStatus::Waiting);

    config()->set('saga-lara-flow.models.action_run', ActionRun::class);

    $handle->cancel('operator');

    expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Cancelled)
        ->and(stepStatuses($run->id))->toBe([ActionStatus::Completed, ActionStatus::Cancelled]);
});

it('pins that a delivered signal nobody consumed is left as history', function () {
    $run = SagaFlow::create(SignalOnlyWorkflow::class)->runSync();

    config()->set('saga-lara-flow.signals.wake_workflow_on_signal', false);

    SagaFlow::loadFlow($run->id)->signal('unrelated');

    SagaFlow::loadFlow($run->id)->cancel();

    $signals = SagaFlow::findRun($run->id)->signals->keyBy('name');

    // The wait is live state and is settled; the delivered row records that a signal
    // arrived and nobody used it, which stays true after the run ends.
    expect($signals['go']->status)->toBe(SignalStatus::Cancelled)
        ->and($signals['unrelated']->status)->toBe(SignalStatus::Received);
});

it('pins that a wait already marked received is left as history', function () {
    config()->set('saga-lara-flow.signals.wake_workflow_on_signal', false);

    $run = SagaFlow::create(SignalOnlyWorkflow::class)->runSync();

    SagaFlow::loadFlow($run->id)->signal('go');

    expect(SagaFlow::findRun($run->id)->signals->first()->status)->toBe(SignalStatus::Received);

    SagaFlow::loadFlow($run->id)->cancel();

    // Delivery marks the wait Received before any replay consumes it. Rewriting that
    // would erase the fact that the signal did arrive.
    expect(SagaFlow::findRun($run->id)->signals->first()->status)->toBe(SignalStatus::Received);
});
