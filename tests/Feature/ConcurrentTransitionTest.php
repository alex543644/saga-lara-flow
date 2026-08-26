<?php

use DiscoveryUkraine\SagaLaraFlow\Contracts\StateMachine;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\RunMode;
use DiscoveryUkraine\SagaLaraFlow\Enums\SignalStatus;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\ConcurrentFlowTransitionException;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\InvalidTransitionException;
use DiscoveryUkraine\SagaLaraFlow\Facades\SagaFlow;
use DiscoveryUkraine\SagaLaraFlow\FlowHandle;
use DiscoveryUkraine\SagaLaraFlow\Jobs\CancelChildWorkflowJob;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\FlowExecutor;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\SignalOnlyWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\StealsRunCompensation;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\StolenRollbackWorkflow;

/**
 * Nothing serialises an operator against a worker: the queue's per-run lock covers
 * jobs, not the CLI and not the monitor's inline sweep, and each side holds its own
 * FlowRun instance. A transition therefore writes only if the row still holds the
 * status its caller read, and the loser is told rather than silently overwritten.
 *
 * Both sides are staged from one process here — two independently loaded instances,
 * one of which moves the row — so the interleaving is exact instead of hoped for.
 */
beforeEach(function () {
    StealsRunCompensation::reset();
});

function staleAndFresh(): array
{
    $run = SagaFlow::create(SignalOnlyWorkflow::class)->runSync();

    expect($run->status)->toBe(FlowStatus::Waiting);

    // What a worker and an operator each hold: the same row, read twice.
    return [SagaFlow::findRun($run->id), SagaFlow::findRun($run->id)];
}

it('refuses to write a run that has moved on since it was read', function () {
    [$stale, $current] = staleAndFresh();

    SagaFlow::loadFlow($current->id)->cancel('operator');

    expect(fn () => app(StateMachine::class)->transition($stale, FlowStatus::Running))
        ->toThrow(ConcurrentFlowTransitionException::class);

    // The cancellation stands whole: its own status, and the step and wait it settled.
    $after = SagaFlow::findRun($stale->id);

    expect($after->status)->toBe(FlowStatus::Cancelled)
        ->and($after->signals->pluck('status')->all())->toBe([SignalStatus::Cancelled]);
});

it('names the status that actually holds the row', function () {
    [$stale, $current] = staleAndFresh();

    SagaFlow::loadFlow($current->id)->cancel('operator');

    expect(fn () => app(StateMachine::class)->transition($stale, FlowStatus::Running))
        ->toThrow(
            ConcurrentFlowTransitionException::class,
            "Cannot transition flow run [{$stale->id}] from [waiting] to [running]: the row is now [cancelled].",
        );
});

it('checks the row even when the transition would not change the status', function () {
    [$stale, $current] = staleAndFresh();

    // A stale instance whose status already equals the target used to return early,
    // before anything looked at the database — the one case a stale reader slipped
    // through unchecked.
    app(StateMachine::class)->transition($stale, FlowStatus::Running);

    SagaFlow::loadFlow($current->id)->cancel('operator');

    expect(fn () => app(StateMachine::class)->transition($stale, FlowStatus::Running))
        ->toThrow(ConcurrentFlowTransitionException::class);
});

it('logs a refused transition with all three statuses', function () {
    $path = sys_get_temp_dir().'/saga-transition-'.uniqid().'.log';

    logToFile($path);

    [$stale, $current] = staleAndFresh();

    SagaFlow::loadFlow($current->id)->cancel('operator');

    try {
        app(StateMachine::class)->transition($stale, FlowStatus::Running);
    } catch (ConcurrentFlowTransitionException) {
        // The log line is what an operator has afterwards; the exception is not.
    }

    expect(file_get_contents($path))
        ->toContain('transition_lost')
        ->toContain($stale->id)
        ->toContain('"observed":"waiting"')
        ->toContain('"intended":"running"')
        ->toContain('"actual":"cancelled"');
});

it('lets the operator know their cancellation did not happen', function () {
    [$stale, $current] = staleAndFresh();

    app(StateMachine::class)->transition($current, FlowStatus::Running);

    // FlowHandle deliberately does not absorb this: a cancel that did not take effect
    // is the one outcome an operator must not be left to infer.
    expect(fn () => new FlowHandle($stale)->cancel('operator'))
        ->toThrow(ConcurrentFlowTransitionException::class);

    expect(SagaFlow::findRun($stale->id)->status)->toBe(FlowStatus::Running);
});

it('stops a drive over a run someone else took, without failing it', function () {
    [$stale, $current] = staleAndFresh();

    SagaFlow::loadFlow($current->id)->cancel('operator');

    $driven = app(FlowExecutor::class)->drive($stale, RunMode::Sync);

    // The engine absorbs it: no exception for the job to retry, no compensation, and
    // the run handed back in the state the winner left it.
    expect($driven->status)->toBe(FlowStatus::Cancelled)
        ->and(SagaFlow::findRun($stale->id)->status)->toBe(FlowStatus::Cancelled);
});

it('stops an expiry over a run someone else took, without ending the sweep', function () {
    [$stale, $current] = staleAndFresh();

    FlowRun::query()->whereKey($stale->id)->update(['expires_at' => now()->subMinute()]);

    SagaFlow::loadFlow($current->id)->cancel('operator');

    // The sweep reaches expireRun() directly rather than through drive(), one run at a
    // time, holding whatever it read before this cancel landed. A throw here would end
    // the whole pass over a single run that is no longer the sweep's to expire.
    $expired = app(FlowExecutor::class)->expireRun($stale);

    expect($expired->status)->toBe(FlowStatus::Cancelled)
        ->and(SagaFlow::findRun($stale->id)->status)->toBe(FlowStatus::Cancelled);
});

it('still refuses a transition the state graph forbids', function () {
    [$stale] = staleAndFresh();

    // The two refusals stay distinct: this one is illegal by the graph and repeating
    // it cannot help, unlike a transition that merely lost a race.
    SagaFlow::loadFlow($stale->id)->cancel('operator');

    $cancelled = SagaFlow::findRun($stale->id);

    expect(fn () => app(StateMachine::class)->transition($cancelled, FlowStatus::Running))
        ->toThrow(InvalidTransitionException::class);
});

it('leaves the instance it refused exactly as it found it', function () {
    [$stale, $current] = staleAndFresh();

    SagaFlow::loadFlow($current->id)->cancel('operator');

    try {
        app(StateMachine::class)->transition($stale, FlowStatus::Cancelled);
    } catch (ConcurrentFlowTransitionException) {
        // The instance is mutated before the row is asked to confirm anything. A caller
        // still holds it afterwards, and one reading a terminal status off a transition
        // the database refused would be reading a run that does not exist.
    }

    expect($stale->status)->toBe(FlowStatus::Waiting)
        ->and($stale->finished_at)->toBeNull()
        ->and($stale->cancelled_at)->toBeNull()
        ->and($stale->isDirty())->toBeFalse();
});

it('keeps changes the caller made before asking for a refused transition', function () {
    [$stale, $current] = staleAndFresh();

    SagaFlow::loadFlow($current->id)->cancel('operator');

    // completeFlow() writes the result onto the instance and lets the transition carry
    // it, so restoring the instance must put back what the caller had, not what the row
    // last held.
    $stale->result = 'carried';

    try {
        app(StateMachine::class)->transition($stale, FlowStatus::Completed);
    } catch (ConcurrentFlowTransitionException) {
        // Asserted below.
    }

    expect($stale->result)->toBe('carried')
        ->and($stale->status)->toBe(FlowStatus::Waiting)
        ->and($stale->isDirty())->toBeTrue();
});

it('tells an operator their manual rollback did not land', function () {
    $run = SagaFlow::create(StolenRollbackWorkflow::class)->runSync();

    expect($run->status)->toBe(FlowStatus::Waiting);

    // The compensation moves the run while the rollback that started it is still
    // running. Only the caller knows whether losing the landing matters, so the
    // rollback raises and the two engine seams that own a landing answer for
    // themselves — drive() for a failure it started, advance() for a queued
    // continuation with nobody to tell.
    expect(fn () => SagaFlow::loadFlow($run->id)->compensate())
        ->toThrow(ConcurrentFlowTransitionException::class);

    expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Failed);
});

it('ends a child close cleanly when the child is taken mid-rollback', function () {
    $child = SagaFlow::create(StolenRollbackWorkflow::class)->runSync();

    // Closing a child under a Cancel policy is engine-owned work on a queue, and its
    // rollback lands the child itself. The per-run lock covers jobs, so an operator
    // reaching the child directly is a race this job has to end quietly rather than
    // fail and retry over.
    dispatch_sync(new CancelChildWorkflowJob($child->id, FlowStatus::Cancelled, true));

    expect(SagaFlow::findRun($child->id)->status)->toBe(FlowStatus::Failed);
});

it('retries a child close it lost while the child is still live', function () {
    $child = SagaFlow::create(StolenRollbackWorkflow::class)->runSync();

    StealsRunCompensation::$leaveAs = FlowStatus::Waiting;

    // Losing the child to a move that leaves it live is not a closed child. This job is
    // the only thing applying the parent's close policy, so ending quietly here would
    // strand a running child under a terminal parent with nothing left to close it.
    expect(fn () => dispatch_sync(new CancelChildWorkflowJob($child->id, FlowStatus::Cancelled, true)))
        ->toThrow(ConcurrentFlowTransitionException::class);

    expect(SagaFlow::findRun($child->id)->status)->toBe(FlowStatus::Waiting);
});

it('leaves an ordinary transition alone', function () {
    [, $current] = staleAndFresh();

    app(StateMachine::class)->transition($current, FlowStatus::Running);

    expect(SagaFlow::findRun($current->id)->status)->toBe(FlowStatus::Running)
        ->and($current->status)->toBe(FlowStatus::Running)
        ->and($current->isDirty())->toBeFalse();
});
