<?php

use DiscoveryUkraine\SagaLaraFlow\Contracts\FlowRepository;
use DiscoveryUkraine\SagaLaraFlow\Data\CompensationDefinition;
use DiscoveryUkraine\SagaLaraFlow\Enums\CompensationStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\CompensationRun;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\CompensationEntry;
use DiscoveryUkraine\SagaLaraFlow\Runtime\SagaRunner;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\OneActionWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\UndoAction;

/**
 * A compensation whose worker died leaves its row non-terminal. Nothing used to
 * notice: the level's outcome was read by looking for Failed rows only, so the
 * rollback carried on and finalized, and the run ended up looking exactly like one
 * that had unwound cleanly — while one step was never undone and no record of that
 * existed anywhere.
 *
 * Unwinding further on top of a step that may still stand is precisely what the Stop
 * policy exists to prevent, so an unfinished compensation now halts the rollback on
 * the same terms a failed one does, and is reported the same way.
 */
function rollbackRun(): FlowRun
{
    return app(FlowRepository::class)->create([
        'workflow_class' => OneActionWorkflow::class,
        'status' => FlowStatus::Running,
        'arguments' => [],
    ]);
}

function strandedCompensation(FlowRun $run, bool $continueOnFailure = false): CompensationRun
{
    return CompensationRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'compensation_type' => 'class',
        'compensation_class' => UndoAction::class,
        'status' => CompensationStatus::Running,
        'continue_on_failure' => $continueOnFailure,
        'attempts' => 1,
    ]);
}

it('halts a Stop-policy rollback when a compensation never reached a terminal state', function () {
    $run = rollbackRun();
    $stuck = strandedCompensation($run);

    $remaining = [[new CompensationEntry(
        actionRunId: 'unused',
        sequence: 1,
        definition: CompensationDefinition::forClass(UndoAction::class, ['later']),
    )]];

    app(SagaRunner::class)->advance($run->id, $remaining, null, FlowStatus::Failed, [$stuck->id]);

    // The next level must never have been dispatched: its compensation row would
    // have been registered by dispatchLevel() before the batch went out.
    expect($run->fresh()->compensations()->count())->toBe(1)
        ->and($run->fresh()->status)->toBe(FlowStatus::Failed);
});

it('records the unfinished compensation as the run\'s secondary cause', function () {
    $run = rollbackRun();
    $stuck = strandedCompensation($run);

    app(SagaRunner::class)->advance($run->id, [], null, FlowStatus::Failed, [$stuck->id]);

    $exception = $run->fresh()->exception;

    expect($exception['compensation']['message'] ?? null)
        ->toContain('had not finished when its rollback level ended')
        ->and($exception['compensation']['message'])->toContain('running');
});

it('lets a Continue-policy rollback carry on past an unfinished compensation', function () {
    useDatabaseQueue();

    $run = rollbackRun();
    $stuck = strandedCompensation($run, continueOnFailure: true);

    $remaining = [[new CompensationEntry(
        actionRunId: 'unused',
        sequence: 1,
        definition: CompensationDefinition::forClass(UndoAction::class, ['later']),
    )]];

    app(SagaRunner::class)->advance($run->id, $remaining, null, FlowStatus::Failed, [$stuck->id]);

    // Continue means the level's outcome does not stop the unwind, so the next
    // level's compensation row was registered and dispatched.
    expect($run->fresh()->compensations()->count())->toBe(2);
});

it('still prefers a real failure over an unfinished compensation as the cause', function () {
    $run = rollbackRun();

    $failed = CompensationRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'compensation_type' => 'class',
        'compensation_class' => UndoAction::class,
        'status' => CompensationStatus::Failed,
        'exception' => ['message' => 'undo blew up'],
        'continue_on_failure' => false,
        'attempts' => 1,
    ]);

    strandedCompensation($run)->update(['sequence' => 1]);

    app(SagaRunner::class)->advance($run->id, [], null, FlowStatus::Failed, [$failed->id]);

    // A failure carries its own cause, which is the more useful thing to surface.
    expect($run->fresh()->exception['compensation']['message'] ?? null)->toContain('failed')
        ->and($run->fresh()->exception['compensation']['cause']['message'] ?? null)->toBe('undo blew up');
});
