<?php

use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Facades\SagaFlow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\TaggingReplayWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\TaggingWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\TestWorkflow;

it('attaches several tags at once from inside the workflow', function () {
    $run = SagaFlow::create(TaggingWorkflow::class)->runSync();

    expect($run->status)->toBe(FlowStatus::Completed)
        ->and($run->tags()->pluck('value', 'key')->all())
        ->toEqualCanonicalizing([
            // 'priority' was re-tagged by a later tags() call — last write wins.
            'priority' => 'high',
            // An int value is cast to string on the way in.
            'attempt' => '1',
            // A null value records a tag with no value.
            'orders' => null,
            // Set through the single-key tag() call.
            'tenant' => 'acme',
        ]);
});

it('never duplicates a repeated tag key', function () {
    $run = SagaFlow::create(TaggingWorkflow::class)->runSync();

    // 'priority' is written twice by the workflow, but updateOrCreate matches on
    // (flow_run_id, key), so it stays a single row.
    expect($run->tags()->where('key', 'priority')->count())->toBe(1)
        ->and($run->tags()->count())->toBe(4);
});

it('keeps bulk tagging idempotent across replays', function () {
    useDatabaseQueue();

    // The action suspends the run, so handle() — and the tags() call before it —
    // is replayed on the resume pass.
    $run = SagaFlow::create(TaggingReplayWorkflow::class)->run();

    drainQueue();

    $run = SagaFlow::findRun($run->id);

    expect($run->status)->toBe(FlowStatus::Completed)
        ->and($run->tags()->count())->toBe(2)
        ->and($run->tags()->pluck('value', 'key')->all())
        ->toEqualCanonicalizing(['stage' => 'done', 'tenant' => 'acme']);
});

it('tags a run from outside the workflow through FlowHandle', function () {
    $run = SagaFlow::create(TestWorkflow::class)->runSync();

    $handle = SagaFlow::loadFlow($run->id)
        ->tag('payment-failed')
        ->withTags(['attempt' => 2, 'orders' => null]);

    // Same updateOrCreate semantics as the workflow trait: int cast to string,
    // null value allowed, re-tagging a key overwrites rather than duplicating.
    expect($handle->run()->tags()->pluck('value', 'key')->all())
        ->toEqualCanonicalizing([
            'payment-failed' => null,
            'attempt' => '2',
            'orders' => null,
        ]);

    $handle->tag('attempt', 3);

    expect($handle->run()->fresh()->tags()->where('key', 'attempt')->count())->toBe(1)
        ->and($handle->run()->fresh()->tags()->where('key', 'attempt')->value('value'))->toBe('3');
});
