<?php

use DiscoveryUkraine\SagaLaraFlow\Contracts\FlowRepository;
use DiscoveryUkraine\SagaLaraFlow\Contracts\Serializer;
use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\ParallelFailurePolicy;
use DiscoveryUkraine\SagaLaraFlow\Jobs\RunParallelActionJob;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionDispatcher;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\MakeValueAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\ParallelEchoWorkflow;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * A parallel block is woken exactly once, by its batch's finally callback. That is
 * safe only while the batch cannot finish before its own jobs do — and an
 * at-least-once queue can break that: a duplicate delivery that loses its claim
 * returns quietly and drives the pending count to zero while the live worker is still
 * executing. The callback fires then, the join replays against a step still Running
 * and parks the run, and Laravel will not fire the callback again — its condition is
 * (pendingJobs - failedJobs) === 0, and the next decrement takes the count to -1.
 *
 * The live worker's own completion is therefore the last chance anyone has to notice.
 */
function closedBatchId(): string
{
    $batch = Bus::batch([])->name('parallel:test')->allowFailures()->dispatch();

    app(BatchRepository::class)->markAsFinished($batch->id);

    return $batch->id;
}

function parallelStep(): ActionRun
{
    $run = app(FlowRepository::class)->create([
        'workflow_class' => ParallelEchoWorkflow::class,
        'status' => FlowStatus::Waiting,
        'arguments' => [],
    ]);

    return ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Pending,
        'parallel_group' => 1,
        'attempts' => 0,
        'arguments' => app(Serializer::class)->serialize(['value']),
    ]);
}

function queuedResumeCount(): int
{
    return DB::connection('testing')->table('jobs')
        ->where('payload', 'like', '%ResumeWorkflowJob%')
        ->count();
}

it('wakes the run itself when the batch was already closed by someone else', function () {
    useDatabaseQueue();

    $action = parallelStep();

    $job = new RunParallelActionJob($action->id, MakeValueAction::class, ParallelFailurePolicy::FailFast);
    $job->withBatchId(closedBatchId());

    $job->handle(app(ActionDispatcher::class));

    // The step really did run and record its result — this is not a lost claim.
    expect($action->fresh()->status)->toBe(ActionStatus::Completed)
        // Without the extra wake this result would sit here with nothing left to
        // notice it: the join already replayed and parked the run.
        ->and(queuedResumeCount())->toBe(1);
});

it('does not wake the run while the batch is still open', function () {
    useDatabaseQueue();

    $action = parallelStep();

    $batch = Bus::batch([])->name('parallel:test')->allowFailures()->dispatch();

    // Not marked finished: the ordinary case, where this job's own completion is what
    // will close the batch and ResumeParallelBlock is the single wake.
    $job = new RunParallelActionJob($action->id, MakeValueAction::class, ParallelFailurePolicy::FailFast);
    $job->withBatchId($batch->id);

    $job->handle(app(ActionDispatcher::class));

    expect($action->fresh()->status)->toBe(ActionStatus::Completed)
        ->and(queuedResumeCount())->toBe(0);
});

it('does not wake the run when this worker never had the claim', function () {
    useDatabaseQueue();

    $action = parallelStep();

    // Somebody else already completed the step; this delivery loses the claim and has
    // no result of its own for anyone to miss.
    $action->status = ActionStatus::Completed;
    $action->save();

    $job = new RunParallelActionJob($action->id, MakeValueAction::class, ParallelFailurePolicy::FailFast);
    $job->withBatchId(closedBatchId());

    $job->handle(app(ActionDispatcher::class));

    expect(queuedResumeCount())->toBe(0);
});

it('records the early close in the anomaly log', function () {
    useDatabaseQueue();

    $path = sys_get_temp_dir().'/saga-early-batch-'.uniqid().'.log';
    logToFile($path);

    $action = parallelStep();

    $job = new RunParallelActionJob($action->id, MakeValueAction::class, ParallelFailurePolicy::FailFast);
    $job->withBatchId(closedBatchId());

    $job->handle(app(ActionDispatcher::class));

    // The only signature this class of failure leaves. A steady stream of it means the
    // queue is redelivering faster than the work completes, or locks are off.
    expect(file_get_contents($path))->toContain('batch_finished_early')
        ->and(file_get_contents($path))->toContain($action->id);
});
