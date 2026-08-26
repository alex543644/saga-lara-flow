<?php

use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Facades\SagaFlow;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowTag;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\TaggingReplayWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\TaggingWorkflow;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\TestWorkflow;
use Illuminate\Database\QueryException;

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

    // Same semantics as the workflow-side trait: an int arrives as a string, a null
    // records a tag with no value, and re-tagging a key overwrites its row.
    expect($handle->tags()->pluck('value', 'key')->all())
        ->toEqualCanonicalizing([
            'payment-failed' => null,
            'attempt' => '2',
            'orders' => null,
        ]);

    $handle->tag('attempt', 3);

    expect($handle->run()->tags()->where('key', 'attempt')->count())->toBe(1)
        ->and($handle->tags()->firstWhere('key', 'attempt')->value)->toBe('3');
});

it('shows a tag written after the reader had already loaded', function () {
    $handle = SagaFlow::loadFlow(SagaFlow::create(TestWorkflow::class)->runSync()->id);

    expect($handle->tags())->toBeEmpty();

    $handle->tag('payment-failed');

    expect($handle->tags()->pluck('key')->all())->toBe(['payment-failed']);
});

it('lets the database refuse a second row for a tag key', function () {
    $run = SagaFlow::create(TestWorkflow::class)->runSync();

    SagaFlow::loadFlow($run->id)->tag('stage', 'charged');

    // What makes "one row per key" hold when two writers race past each other's
    // updateOrCreate lookup: the insert itself is refused.
    expect(fn () => FlowTag::create([
        'flow_run_id' => $run->id,
        'key' => 'stage',
        'value' => 'shipped',
    ]))->toThrow(QueryException::class);
});
