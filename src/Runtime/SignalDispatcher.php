<?php

namespace DiscoveryUkraine\SagaLaraFlow\Runtime;

use DiscoveryUkraine\SagaLaraFlow\Contracts\SignalRepository;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\CannotSignalTerminalFlowException;
use DiscoveryUkraine\SagaLaraFlow\Jobs\ResumeWorkflowJob;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowSignal;

/**
 * Delivers an external signal into a run and wakes it. If the run is parked on a
 * matching awaitSignal, the open wait-signal is fulfilled in place; otherwise the
 * signal is stored as a floating Received row for a future awaitSignal to consume
 * (FIFO). Delivery runs outside the queue and holds no lock, so filling a signal is
 * a conditional write that falls back to the floating row when it loses.
 * Terminal runs reject signals.
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
        if ($flowRun->isTerminal()) {
            throw CannotSignalTerminalFlowException::for($flowRun);
        }

        $waitingSignal = $this->repository->earliestWaiting($flowRun->id, $name);

        $signal = $waitingSignal === null
            ? null
            : $this->recorder->fulfilWaitingSignal($waitingSignal, $payload);

        // No open wait-signal, or the one we found was claimed by a retry seam while
        // we were writing: keep the delivery as a floating Received row rather than
        // attaching it to a spent signal, where nothing would look for it again.
        $signal ??= $this->recorder->storeReceivedSignal($flowRun, $name, $payload);

        if (config('saga-lara-flow.signals.wake_workflow_on_signal')) {
            $this->wake($flowRun);
        }

        return $signal;
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
}
