<?php

namespace DiscoveryUkraine\SagaLaraFlow\Events;

use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched when a parked step starts another signal-gated retry cycle: one unit of
 * its budget is spent and the same (flow_run_id, sequence) ordinal is about to run
 * again. Exactly one fires per cycle, and retry_signal_attempts already counts it.
 *
 * Dispatched after the surrounding transaction commits, so a listener never reacts
 * to a retry the database rolled back.
 */
final readonly class ActionRetried implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ActionRun $actionRun,
    ) {}
}
