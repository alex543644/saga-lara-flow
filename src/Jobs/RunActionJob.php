<?php

namespace DiscoveryUkraine\SagaLaraFlow\Jobs;

use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Middleware\LockMiddlewareFactory;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionDispatcher;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executes a single scheduled action step, then resumes its workflow so the
 * drive loop can replay and move on. Carries the action's native $tries/$timeout
 * so Laravel's queue retry semantics apply. On final failure it still resumes
 * the workflow, so the failure surfaces as a business error on replay.
 *
 * $retryGeneration is the signal-gated retry cycle this job was sent for, so a job
 * from an earlier cycle can recognise itself as stale. A payload written before the
 * field existed carries null and is read as cycle 0. It is a plain declared property
 * rather than a promoted one on purpose: such a payload is unserialized without the
 * constructor, and only a class-level default keeps the typed property initialized.
 */
class RunActionJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public ?int $retryGeneration = null;

    public function __construct(
        public string $actionRunId,
        public string $actionClass,
        ?int $retryGeneration = null,
    ) {
        $this->retryGeneration = $retryGeneration;

        $defaults = get_class_vars($this->actionClass);

        $this->tries = isset($defaults['tries']) ? (int) $defaults['tries'] : 1;
        $this->timeout = isset($defaults['timeout']) ? (int) $defaults['timeout'] : 0;
    }

    /**
     * @throws Throwable
     */
    public function handle(ActionDispatcher $dispatcher): void
    {
        $action = $this->resolveAction();

        if ($action === null) {
            return;
        }

        // Left over from an earlier retry cycle: the row has moved on, so running it
        // now would execute the step a second time without a signal. The workflow is
        // still resumed — this job may be the only wake left if the pass that rewound
        // the row died before sending the live one.
        if ($action->retry_signal_attempts !== ($this->retryGeneration ?? 0)) {
            $this->resumeWorkflow($action);

            return;
        }

        // Skip a step already settled out of band: Completed on an earlier attempt,
        // Expired by the monitor (a late job must not resurrect an expired step), or
        // parked on a retry signal (only the seam may restart it, and only once the
        // signal lands).
        if (! in_array(
            $action->status,
            [ActionStatus::Completed, ActionStatus::Expired, ActionStatus::AwaitingRetry],
            true,
        )) {
            $dispatcher->execute($action);
        }

        $this->resumeWorkflow($action);
    }

    public function failed(Throwable $exception): void
    {
        $action = $this->resolveAction();

        if ($action === null) {
            return;
        }

        // An earlier cycle's outcome says nothing about the cycle the row is on now,
        // so it must not settle anything — but resume for the same reason as handle().
        if ($action->retry_signal_attempts !== ($this->retryGeneration ?? 0)) {
            $this->resumeWorkflow($action);

            return;
        }

        // Record the queue giving up authoritatively, so the retry seam never has to
        // guess it from the action's current $tries.
        app(ActionRecorder::class)->markQueueAttemptsExhausted($action);

        // Optional step exhausted its retries: record it as OptionalFailed so the
        // workflow resolves the fallback instead of failing on replay. A step with a
        // retry policy is exempt: only the seam knows its only-filter and its budget,
        // so it decides on replay whether this failure is final at all.
        if ($action->retry_signal === null
            && $action->continue_on_failure
            && $action->status === ActionStatus::Failed) {
            app(ActionRecorder::class)->optionalFail($action);
        }

        $this->resumeWorkflow($action);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return app(LockMiddlewareFactory::class)->actionMiddleware($this->actionRunId);
    }

    private function resumeWorkflow(ActionRun $action): void
    {
        $flowRun = $action->flowRun;

        $job = ResumeWorkflowJob::dispatch($action->flow_run_id);

        if ($flowRun->connection !== null) {
            $job->onConnection($flowRun->connection);
        }

        if ($flowRun->queue !== null) {
            $job->onQueue($flowRun->queue);
        }

        if (config('saga-lara-flow.queue.after_commit')) {
            $job->afterCommit();
        }
    }

    private function resolveAction(): ?ActionRun
    {
        /** @var class-string<ActionRun> $model */
        $model = config('saga-lara-flow.models.action_run');

        return $model::query()->find($this->actionRunId);
    }
}
