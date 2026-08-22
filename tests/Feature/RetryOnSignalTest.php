<?php

use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowEventType;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\RunMode;
use DiscoveryUkraine\SagaLaraFlow\Enums\SignalStatus;
use DiscoveryUkraine\SagaLaraFlow\Facades\SagaFlow;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\FlowExecutor;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\CompensationLog;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\FlakyPaymentAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\RetryOnSignalWorkflow;

/**
 * Sync-mode coverage for retryOnSignal(). Waking the run on delivery is switched
 * off here so the signal does not queue a ResumeWorkflowJob: these tests drive the
 * run again explicitly in RunMode::Sync, which keeps the inline path under test
 * instead of silently falling through to the queued one (that is covered separately).
 */
beforeEach(function () {
    FlakyPaymentAction::reset();
    CompensationLog::reset();

    config()->set('saga-lara-flow.signals.wake_workflow_on_signal', false);
});

/**
 * Deliver the retry signal and replay the run inline, the way a resumed job would.
 */
function refillAndDrive(FlowRun $run): FlowRun
{
    SagaFlow::loadFlow($run->id)->signal('balance-refilled');

    return app(FlowExecutor::class)->drive(SagaFlow::findRun($run->id), RunMode::Sync);
}

it('parks a failed step on its signal instead of failing the flow', function () {
    FlakyPaymentAction::reset(failures: 99);

    $run = SagaFlow::create(RetryOnSignalWorkflow::class)->withArguments('order-1')->runSync();

    expect($run->status)->toBe(FlowStatus::Waiting);

    $actions = $run->actions()->orderBy('sequence')->get();

    // Only the two steps reached so far exist: parking does not consume a sequence
    // of its own, and the downstream step has not been scheduled.
    expect($actions)->toHaveCount(2)
        ->and($actions[1]->sequence)->toBe(1)
        ->and($actions[1]->status)->toBe(ActionStatus::AwaitingRetry)
        ->and($actions[1]->retry_signal)->toBe('balance-refilled')
        ->and($actions[1]->retry_signal_attempts)->toBe(0)
        ->and($actions[1]->exception['message'] ?? null)->toBe('insufficient balance');

    $marker = $run->signals()->first();

    expect($run->signals()->get())->toHaveCount(1)
        ->and($marker->status)->toBe(SignalStatus::Waiting)
        ->and($marker->name)->toBe('balance-refilled')
        ->and($marker->wait_sequence)->toBe(1);

    // A parked step is not terminal, so nothing has rolled back.
    expect(CompensationLog::all())->toBe([])
        ->and($run->events()->pluck('type')->all())->toContain(FlowEventType::ActionAwaitingRetry);
});

it('retries only the parked step when the signal arrives', function () {
    FlakyPaymentAction::reset(failures: 1);

    $run = SagaFlow::create(RetryOnSignalWorkflow::class)->withArguments('order-2')->runSync();

    expect($run->status)->toBe(FlowStatus::Waiting);

    $final = refillAndDrive($run);

    expect($final->status)->toBe(FlowStatus::Completed)
        ->and($final->result['charged'] ?? null)->toBe(['charged' => 'order-2', 'calls' => 2]);

    $actions = $final->actions()->orderBy('sequence')->get();

    expect($actions)->toHaveCount(3)
        ->and($actions[1]->status)->toBe(ActionStatus::Completed)
        ->and($actions[1]->sequence)->toBe(1)
        ->and($actions[1]->retry_signal_attempts)->toBe(1)
        // The step before it was not re-executed, and the step after it still sits
        // at the sequence it would have had without any retry.
        ->and($actions[0]->attempts)->toBe(1)
        ->and($actions[2]->sequence)->toBe(2)
        ->and($actions[2]->result)->toBe(['label' => 'shipped']);

    $marker = $final->signals()->first();

    expect($final->signals()->get())->toHaveCount(1)
        ->and($marker->status)->toBe(SignalStatus::Consumed)
        ->and($marker->wait_sequence)->toBe(1);

    expect(CompensationLog::all())->toBe([])
        ->and($final->events()->pluck('type')->all())->toContain(FlowEventType::ActionRetried);
});

it('parks again when the retried step fails once more', function () {
    FlakyPaymentAction::reset(failures: 99);

    $run = SagaFlow::create(RetryOnSignalWorkflow::class)->withArguments('order-3')->runSync();

    $again = refillAndDrive($run);

    expect($again->status)->toBe(FlowStatus::Waiting)
        ->and(FlakyPaymentAction::$calls)->toBe(2);

    $step = $again->actions()->where('sequence', 1)->first();

    expect($step->status)->toBe(ActionStatus::AwaitingRetry)
        ->and($step->retry_signal_attempts)->toBe(1);

    // One marker per retry cycle, all at the step's own ordinal: the spent one is
    // consumed history, the fresh one is the wait we are parked on now.
    $markers = $again->signals()->orderBy('id')->get();

    expect($markers)->toHaveCount(2)
        ->and($markers[0]->status)->toBe(SignalStatus::Consumed)
        ->and($markers[1]->status)->toBe(SignalStatus::Waiting)
        ->and($markers->pluck('wait_sequence')->all())->toBe([1, 1]);

    expect(CompensationLog::all())->toBe([]);
});

it('succeeds on the third attempt and carries the saga on', function () {
    FlakyPaymentAction::reset(failures: 2);

    $run = SagaFlow::create(RetryOnSignalWorkflow::class)->withArguments('order-4')->runSync();

    $run = refillAndDrive($run);

    expect($run->status)->toBe(FlowStatus::Waiting);

    $final = refillAndDrive($run);

    expect($final->status)->toBe(FlowStatus::Completed)
        ->and($final->result['charged']['calls'] ?? null)->toBe(3);

    $step = $final->actions()->where('sequence', 1)->first();

    expect($step->status)->toBe(ActionStatus::Completed)
        ->and($step->retry_signal_attempts)->toBe(2);

    expect($final->actions()->count())->toBe(3)
        ->and(CompensationLog::all())->toBe([]);
});

it('fails normally when the failure falls outside only', function () {
    FlakyPaymentAction::reset(failures: 99);

    $run = SagaFlow::create(RetryOnSignalWorkflow::class)
        ->withArguments('order-5', null, [LogicException::class])
        ->runSync();

    expect($run->status)->toBe(FlowStatus::Failed)
        ->and(FlakyPaymentAction::$calls)->toBe(1);

    $step = $run->actions()->where('sequence', 1)->first();

    expect($step->status)->toBe(ActionStatus::Failed)
        ->and($run->signals()->count())->toBe(0);

    // The completed step before it rolled back, exactly as it would have without
    // any retry policy in play.
    expect(CompensationLog::all())->toBe(['undo:created']);
});
