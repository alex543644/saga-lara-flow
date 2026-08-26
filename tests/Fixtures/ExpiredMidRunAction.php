<?php

namespace DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures;

use DiscoveryUkraine\SagaLaraFlow\Action;
use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;

/**
 * Action whose row the monitor expires while it is still running.
 *
 * The monitor is a separate process sweeping the same rows a sync run creates, so
 * this race reaches inline execution too. The write below is what
 * ActionRecorder::expireAction() performs, reproduced in one process — and unlike a
 * rival claim it leaves `attempts` alone, which is why the outcome fence needs its
 * status condition and not just the attempt counter.
 *
 * The step executing right now is the only Running row there is, so it needs no
 * argument to find itself.
 */
final class ExpiredMidRunAction extends Action
{
    public function handle(): string
    {
        ActionRun::query()
            ->where('status', ActionStatus::Running)
            ->update([
                'status' => ActionStatus::Expired,
                'exception' => json_encode(['message' => 'deadline passed']),
                'finished_at' => now(),
            ]);

        return 'too late';
    }
}
