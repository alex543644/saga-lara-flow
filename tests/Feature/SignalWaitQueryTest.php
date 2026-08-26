<?php

use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Facades\SagaFlow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\FlakyPaymentAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\RetryOnSignalWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\SignalOnlyWorkflow;

beforeEach(function () {
    // Delivery would otherwise queue a resume job that settles the park before the
    // assertions can look at it.
    config()->set('saga-lara-flow.signals.wake_workflow_on_signal', false);
    FlakyPaymentAction::reset(failures: 99);
});

function parkedRun(): string
{
    return SagaFlow::create(RetryOnSignalWorkflow::class)->withArguments('order-1')->runSync()->id;
}

it('finds every run whose wait for a signal is still open', function () {
    $awaiting = SagaFlow::create(SignalOnlyWorkflow::class)->runSync();
    $parked = parkedRun();

    expect($awaiting->status)->toBe(FlowStatus::Waiting)
        ->and(SagaFlow::query()->whereAwaitingSignal('go')->get()->pluck('id')->all())
        ->toBe([$awaiting->id])
        ->and(SagaFlow::query()->whereAwaitingSignal('balance-refilled')->get()->pluck('id')->all())
        ->toBe([$parked])
        ->and(SagaFlow::query()->whereAwaitingSignal()->get()->pluck('id')->all())
        ->toEqualCanonicalizing([$awaiting->id, $parked]);
});

it('tells a parked step apart from an explicit await on the same run set', function () {
    // The awaitSignal run is what the filter has to leave out.
    SagaFlow::create(SignalOnlyWorkflow::class)->runSync();
    $parked = parkedRun();

    expect(SagaFlow::query()->whereAwaitingRetrySignal('balance-refilled')->get()->pluck('id')->all())
        ->toBe([$parked])
        ->and(SagaFlow::query()->whereAwaitingRetrySignal()->get()->pluck('id')->all())
        ->toBe([$parked]);
});

it('carries the failure snapshot the parked step was queried for', function () {
    $parked = parkedRun();

    $step = SagaFlow::query()
        ->whereAwaitingRetrySignal('balance-refilled')
        ->first()
        ?->actions()
        ->where('retry_signal', 'balance-refilled')
        ->first();

    expect($step?->exception['message'] ?? null)->toBe('insufficient balance')
        ->and($step?->flow_run_id)->toBe($parked);
});

it('still finds a step whose signal arrived but whose resume never did', function () {
    $parked = parkedRun();

    SagaFlow::loadFlow($parked)->signalRetry('balance-refilled');

    // Delivery marks the wait Received; the step stays parked until replay resumes
    // the run. A resume that never arrives leaves the run visible only here.
    expect(SagaFlow::query()->whereAwaitingSignal('balance-refilled')->count())->toBe(0)
        ->and(SagaFlow::query()->whereAwaitingRetrySignal('balance-refilled')->get()->pluck('id')->all())
        ->toBe([$parked]);
});

it('stops matching a run that has finished', function () {
    $parked = parkedRun();

    SagaFlow::loadFlow($parked)->cancel();

    // Both filters read the rows a run holds, and a terminal run holds neither an open
    // wait nor a parked step — so neither can hand an operator a run that would refuse
    // the signal they were about to send.
    expect(SagaFlow::findRun($parked)->status)->toBe(FlowStatus::Cancelled)
        ->and(SagaFlow::query()->whereAwaitingRetrySignal('balance-refilled')->count())->toBe(0)
        ->and(SagaFlow::query()->whereAwaitingSignal('balance-refilled')->count())->toBe(0);
});
