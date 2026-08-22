<?php

namespace DiscoveryUkraine\SagaLaraFlow\Contracts;

use DateTimeInterface;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowSignal;

/**
 * @internal This contract is an implementation seam for the package's own
 * runtime, not a public extension point. Methods may be added to it in a minor
 * release; swap behaviour through config('saga-lara-flow.models.*') instead.
 */
interface SignalRepository
{
    /**
     * The signal wait-signal recorded at this (flow_run_id, wait_sequence) ordinal,
     * if any. Identifies a signal occupying a sequence for replay and history-contract.
     */
    public function find(string $flowRunId, int $sequence): ?FlowSignal;

    /**
     * The earliest delivered-but-unconsumed signal with this name (FIFO by ULID).
     * Floating signals carry no wait_sequence until an awaitSignal consumes them.
     */
    public function earliestPending(string $flowRunId, string $name): ?FlowSignal;

    /**
     * The earliest delivered-but-unconsumed signal with this name that arrived at or
     * after $since (FIFO by ULID; a null $since means "any"). Lets the retry seam catch
     * a signal delivered between a step's failure and the replay that parks it, while
     * ignoring older floating signals that belong to an earlier wait.
     */
    public function earliestPendingSince(string $flowRunId, string $name, ?DateTimeInterface $since): ?FlowSignal;

    /**
     * The earliest open wait-signal with this name (FIFO by ULID), used by signal
     * delivery to fulfil a parked awaitSignal.
     */
    public function earliestWaiting(string $flowRunId, string $name): ?FlowSignal;

    /**
     * The MOST RECENT wait-signal recorded at this (flow_run_id, wait_sequence)
     * ordinal. A retried step parks at the same ordinal once per retry cycle, so
     * several rows can share it, and only the newest describes the current wait.
     */
    public function latestForSequence(string $flowRunId, int $sequence): ?FlowSignal;

    /**
     * Open wait-signals (status Waiting) whose timeout_at deadline has passed,
     * oldest first, capped at $limit. Used by the monitor to time signal waits out.
     *
     * @return iterable<int, FlowSignal>
     */
    public function dueForTimeout(int $limit): iterable;
}
