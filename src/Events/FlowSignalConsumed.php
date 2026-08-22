<?php

namespace DiscoveryUkraine\SagaLaraFlow\Events;

use DiscoveryUkraine\SagaLaraFlow\Models\FlowSignal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched after the surrounding transaction commits, so a listener never reacts to
 * a consumption the database rolled back.
 */
final readonly class FlowSignalConsumed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public FlowSignal $signal,
    ) {}
}
