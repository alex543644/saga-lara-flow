<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;

/**
 * An ActionRun pointed at a table that does not exist, so any query against it
 * fails. Swapped in through config('saga-lara-flow.models.action_run') to make the
 * settlement of a finished run fail for real, without a mock.
 */
final class UnwritableActionRun extends ActionRun
{
    protected $table = 'saga_action_runs_that_do_not_exist';
}
