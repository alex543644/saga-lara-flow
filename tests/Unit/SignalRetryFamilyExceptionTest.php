<?php

use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\CannotSignalRetryException;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\InvalidRetrySignalException;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;

it('names the run and the retry signal when signal() is misused', function () {
    $run = new FlowRun;
    $run->id = '01J000000000000000000RUNID';
    $run->status = FlowStatus::Waiting;

    $exception = CannotSignalRetryException::for($run, 'balance-refilled');

    expect($exception->getMessage())
        ->toContain('01J000000000000000000RUNID')
        ->toContain('balance-refilled')
        ->toContain('signalRetry()');
});

it('names the run and the unknown signal when signalRetry() is misused', function () {
    $run = new FlowRun;
    $run->id = '01J000000000000000000RUNID';
    $run->status = FlowStatus::Waiting;

    $exception = InvalidRetrySignalException::for($run, 'approval');

    expect($exception->getMessage())
        ->toContain('01J000000000000000000RUNID')
        ->toContain('approval')
        ->toContain('signal()');
});
