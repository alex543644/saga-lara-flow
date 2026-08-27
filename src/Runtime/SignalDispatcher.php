<?php

namespace DiscoveryUkraine\SagaLaraFlow\Runtime;

use DiscoveryUkraine\SagaLaraFlow\Contracts\SignalRepository;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\CannotSignalTerminalFlowException;
use DiscoveryUkraine\SagaLaraFlow\Jobs\ResumeWorkflowJob;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowSignal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Delivers an external signal into a run and wakes it. If the run is parked on a
 * matching awaitSignal, the open wait-signal is fulfilled in place; otherwise the
 * signal is stored as a floating Received row for a future awaitSignal to consume
 * (FIFO).
 *
 * The run must still be signalable at write time: deliver() asserts that with a
 * conditional UPDATE on flow_runs (same portable pattern as claiming a wait-signal)
 * inside a transaction that also writes the signal row, so a stale in-memory
 * FlowRun cannot store a wake on a run that has already finished. Filling a wait
 * marker remains a conditional write that falls back to a floating row when it
 * loses. Wake is dispatched after the transaction commits.
 */
readonly class SignalDispatcher
{
    public function __construct(
        private SignalRepository $repository,
        private SignalRecorder $recorder,
    ) {}

    /**
     * @param  array<int|string, mixed>  $payload
     *
     * @throws CannotSignalTerminalFlowException
     */
    public function deliver(FlowRun $flowRun, string $name, array $payload): FlowSignal
    {
        $signal = $this->connection()->transaction(function () use ($flowRun, $name, $payload): FlowSignal {
            $this->assertCanAcceptSignal($flowRun);

            $waitingSignal = $this->repository->earliestWaiting($flowRun->id, $name);

            $signal = $waitingSignal === null
                ? null
                : $this->recorder->fulfilWaitingSignal($waitingSignal, $payload);

            // No open wait-signal, or the one we found was claimed by a retry seam while
            // we were writing: keep the delivery as a floating Received row rather than
            // attaching it to a spent signal, where nothing would look for it again.
            return $signal ?? $this->recorder->storeReceivedSignal($flowRun, $name, $payload);
        });

        if (config('saga-lara-flow.signals.wake_workflow_on_signal')) {
            $this->wake($flowRun);
        }

        return $signal;
    }

    /**
     * Assert the run is still Pending/Running/Waiting in the database. The
     * conditional UPDATE is the atomic check (and row lock until commit); the
     * updated_at touch is only so the UPDATE has a column to write.
     *
     * @throws CannotSignalTerminalFlowException
     */
    private function assertCanAcceptSignal(FlowRun $flowRun): void
    {
        $signalable = array_map(
            static fn (FlowStatus $status): string => $status->value,
            FlowStatus::signalable(),
        );

        $attributes = $flowRun->newInstance()
            ->forceFill(['updated_at' => Carbon::now()])
            ->getAttributes();

        $alive = $flowRun->newQuery()
            ->whereKey($flowRun->getKey())
            ->whereIn('status', $signalable)
            ->update($attributes) === 1;

        $flowRun->refresh();

        if ($alive) {
            return;
        }

        throw CannotSignalTerminalFlowException::for($flowRun);
    }

    private function wake(FlowRun $flowRun): void
    {
        $job = ResumeWorkflowJob::dispatch($flowRun->id);

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

    private function connection(): ConnectionInterface
    {
        return DB::connection(config('saga-lara-flow.database.connection') ?: null);
    }
}
