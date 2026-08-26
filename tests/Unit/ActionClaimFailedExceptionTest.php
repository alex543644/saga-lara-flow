<?php

use DiscoveryUkraine\SagaLaraFlow\Exceptions\ActionClaimFailedException;

it('builds a clear message for a failed synchronous action claim', function () {
    $exception = ActionClaimFailedException::forAction('App\\Actions\\ChargeCard', 2);

    expect($exception->getMessage())
        ->toContain('App\\Actions\\ChargeCard')
        ->toContain('sequence 2')
        ->toContain('broken invariant');
});

it('builds a clear message for a failed synchronous compensation claim', function () {
    $exception = ActionClaimFailedException::forCompensation('App\\Actions\\RefundCard', 1);

    expect($exception->getMessage())
        ->toContain('App\\Actions\\RefundCard')
        ->toContain('sequence 1')
        ->toContain('broken invariant');
});
