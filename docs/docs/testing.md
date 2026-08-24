---
id: testing
title: Testing your workflows
sidebar_position: 22
---

# Testing your workflows

## Synchronous assertions

For a workflow that doesn't need to suspend, `runSync()` drives every step in-process and lets you
assert the final state directly:

```php
$run = SagaFlow::create(CheckoutWorkflow::class)
    ->withArguments('order-1')
    ->runSync();

expect($run->status)->toBe(FlowStatus::Completed)
    ->and($run->result)->toBe(['charge' => 'ch_order-1']);
```

## Queued paths need a real queue

The **queued** paths (suspension, resume, queued actions, parallel blocks) must run against a real
database queue driven with `queue:work --stop-when-empty`. The `sync` driver bypasses the
suspend/replay machinery and will not exercise the engine faithfully.

A typical pattern: set the queue connection to `database`, dispatch with `->run()`, then drain the
queue before asserting.

```php
config()->set('queue.default', 'database');

$run = SagaFlow::create(CheckoutWorkflow::class)->withArguments('order-1')->run();

Artisan::call('queue:work', ['--stop-when-empty' => true]);

expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Completed);
```

The package's own suite (`tests/`) is a working reference — see `tests/Helpers.php` for the
`useDatabaseQueue()` / `drainQueue()` helpers it uses to drive queued flows deterministically.

## Testing deadlines

Deadlines — `timeoutAfter()`, `expiresAt()`, `#[FlowTimeout]`, the `monitor.expiration.defaults` —
are enforced by the [expiration sweep](./expiration-and-monitoring.md), not by a timer. Draining the
queue does not advance them, so a test has to run the sweep itself:

```php
use Illuminate\Support\Facades\Artisan;

$run = SagaFlow::create(ApprovalWorkflow::class)->run();
drainQueue();

// Parked on awaitSignal('approval', now()->addHour()).
expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Waiting);

$this->travel(2)->hours();

Artisan::call('saga-flow:monitor'); // flips the overdue wait to timed_out and wakes the run
drainQueue();                       // the resumed run replays and sees the timeout

expect(SagaFlow::findRun($run->id)->status)->toBe(FlowStatus::Completed);
```

Two steps are easy to miss. **`travel()` alone changes nothing** — moving the clock does not make
anything look at the deadline. And **the sweep only marks the wait**; the run is resumed as a queued
job, so a second drain is needed before the workflow has actually replayed and reacted.

`Artisan::call('saga-flow:monitor')` and `app(FlowMonitor::class)->sweep()` are equivalent here; the
command is the more faithful of the two because it is what production runs.

If you would rather not call the sweep explicitly, enable
`monitor.queue_looping.enabled` in the test environment and `queue:work` will sweep on its idle
loop. Set it before the application boots — the listener is registered in the service provider's
`boot()`, so flipping the config inside the test body is too late.

## Ageing timestamps

Instead of travelling, you can age a row's timestamps directly. This is what the repair doctor
reads: its grace period is measured from `updated_at` on a run and from `created_at` on a step, so
ageing both covers either.

```php
FlowRun::query()->whereKey($run->id)->update([
    'created_at' => now()->subDay(),
    'updated_at' => now()->subDay(),
]);
```

## Running the package's own tests

```bash
composer test        # Pest
composer analyse     # PHPStan (larastan, level 5)
composer lint        # Pint + PHPStan
```

The suite runs with random order and fails on risky/warning-producing tests, so keep tests
isolated and deterministic.
