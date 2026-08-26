<?php

use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\NoAwaitingRetrySignalException;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;

it('builds a clear message naming the run', function () {
    $run = new FlowRun;
    $run->id = '01J000000000000000000RUNID';
    $run->status = FlowStatus::Waiting;

    $exception = NoAwaitingRetrySignalException::for($run);

    expect($exception->getMessage())
        ->toContain('01J000000000000000000RUNID')
        ->toContain('no step awaiting a retry signal');
});
