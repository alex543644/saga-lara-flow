<?php

namespace DiscoveryUkraine\SagaLaraFlow\Events;

use DiscoveryUkraine\SagaLaraFlow\Models\CompensationRun;

/**
 * Fired when one compensation row is claimed and begins running. Distinct from
 * CompensationStarted, which marks the whole rollback beginning for a run (once per
 * run, carrying the FlowRun) — this fires once per compensation row, carrying the
 * CompensationRun itself.
 */
final readonly class CompensationStepStarted
{
    public function __construct(
        public CompensationRun $compensationRun,
    ) {}
}
