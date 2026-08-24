<?php

use DiscoveryUkraine\SagaLaraFlow\Enums\FlowEventType;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\SignalStatus;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\AwaitSignalTimeoutException;
use DiscoveryUkraine\SagaLaraFlow\Facades\SagaFlow;
use DiscoveryUkraine\SagaLaraFlow\Runtime\FlowMonitor;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\CompensationLog;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\FutureSignalTimeoutWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\SignalTimeoutCaughtWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\SignalTimeoutWorkflow;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => CompensationLog::reset());

it('times a stuck signal out and fails the flow when the timeout is uncaught', function () {
    useDatabaseQueue();

    $run = SagaFlow::create(SignalTimeoutWorkflow::class)->run();
    drainQueue();

    $run = SagaFlow::findRun($run->id);

    expect($run->status)->toBe(FlowStatus::Waiting);

    $marker = $run->signals()->first();

    expect($marker->status)->toBe(SignalStatus::Waiting)
        ->and($marker->timeout_at)->not->toBeNull();

    $report = app(FlowMonitor::class)->sweep();

    expect($report['signals'])->toBe(1);

    // Resume + compensation run as queued jobs.
    drainQueue();

    $final = SagaFlow::findRun($run->id);

    expect($final->status)->toBe(FlowStatus::Failed)
        ->and($final->exception['class'] ?? null)->toBe(AwaitSignalTimeoutException::class)
        ->and(CompensationLog::all())->toContain('undo:a')
        ->and($final->signals()->first()->status)->toBe(SignalStatus::TimedOut)
        ->and($final->events()->where('type', FlowEventType::SignalTimedOut->value)->count())->toBe(1);
});

it('lets the workflow catch a signal timeout and carry on', function () {
    useDatabaseQueue();

    $run = SagaFlow::create(SignalTimeoutCaughtWorkflow::class)->run();
    drainQueue();

    expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Waiting);

    app(FlowMonitor::class)->sweep();
    drainQueue();

    $final = SagaFlow::findRun($run->id);

    expect($final->status)->toBe(FlowStatus::Completed)
        ->and($final->result)->toBe(['outcome' => 'timed-out'])
        ->and($final->signals()->first()->status)->toBe(SignalStatus::TimedOut);
});

/**
 * A deadline is a stored value, not a timer: nothing notices it until the sweep runs.
 * Moving the clock past it and draining the queue must therefore change nothing — this
 * is the behaviour reported in issue #17, and it is by design.
 */
it('leaves an overdue wait open until the sweep runs', function () {
    useDatabaseQueue();

    $run = SagaFlow::create(FutureSignalTimeoutWorkflow::class)->run();
    drainQueue();

    expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Waiting);

    $this->travel(10)->days();
    drainQueue();

    $stalled = SagaFlow::findRun($run->id);

    expect($stalled->status)->toBe(FlowStatus::Waiting)
        ->and($stalled->signals()->first()->status)->toBe(SignalStatus::Waiting);

    // The sweep marks the wait; the run resumes as a queued job, so it takes a
    // second drain before the workflow has replayed and seen the timeout.
    Artisan::call('saga-flow:monitor');
    drainQueue();

    $final = SagaFlow::findRun($run->id);

    expect($final->status)->toBe(FlowStatus::Completed)
        ->and($final->result)->toBe(['value' => false])
        ->and($final->signals()->first()->status)->toBe(SignalStatus::TimedOut);
});

/**
 * The sweep is the only writer of "this deadline passed", so a delivery that beats it
 * is honoured even though the deadline is already behind us. Documented in
 * expiration-and-monitoring.md as the price of keeping that writer single.
 */
it('accepts a signal delivered after its deadline but before the sweep', function () {
    useDatabaseQueue();

    $run = SagaFlow::create(FutureSignalTimeoutWorkflow::class)->run();
    drainQueue();

    $this->travel(10)->days();

    SagaFlow::loadFlow($run->id)->signal('approval', ['late' => true]);
    drainQueue();

    $final = SagaFlow::findRun($run->id);

    expect($final->status)->toBe(FlowStatus::Completed)
        ->and($final->result)->toBe(['value' => true])
        ->and($final->signals()->first()->status)->toBe(SignalStatus::Consumed);
});
