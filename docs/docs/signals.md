---
id: signals
title: Signals
sidebar_position: 7
---

# Signals

Signals let external code push data or decisions into a running workflow. Inside `handle()`,
`awaitSignal()` suspends the workflow until the named signal arrives, then returns its payload:

```php
public function handle(): void
{
    $decision = $this->awaitSignal('approval'); // suspends until delivered

    if (($decision['approved'] ?? false) === true) {
        $this->action(Publish::class)->run();
    }
}
```

A signal delivered *before* the workflow awaits it is consumed inline without suspending.

## Timeouts

The fluent form adds a deadline that turns an unanswered wait into a catchable exception:

```php
use DiscoveryUkraine\SagaLaraFlow\Exceptions\AwaitSignalTimeoutException;

try {
    $decision = $this->signal('approval')
        ->timeoutAfter(now()->addDay())
        ->wait();
} catch (AwaitSignalTimeoutException $e) {
    $this->action(AutoReject::class)->run();
}
```

`awaitSignal($name, $timeout)` accepts the timeout as an optional second argument as well.

:::warning A deadline does not enforce itself

The package has no durable timers. A deadline is a value stored on the wait; something has to
*notice* that it passed. That something is the expiration sweep — either the scheduled
`saga-flow:monitor` command or the opt-in queue-looping listener. Until a sweep runs, the wait stays
open and `AwaitSignalTimeoutException` is never thrown, however long the deadline has been past.

If you set no deadline and no `monitor.expiration.defaults.signal`, the wait is unbounded by design.

See [Expiration & monitoring](./expiration-and-monitoring.md) for how to drive the sweep, and
[Testing](./testing.md#testing-deadlines) for driving it from a test.
:::

## Delivering a signal

From anywhere in your app, deliver via the flow handle:

```php
use DiscoveryUkraine\SagaLaraFlow\Facades\SagaFlow;

SagaFlow::loadFlow($runId)->signal('approval', ['approved' => true]);
```

`signal()` throws `CannotSignalTerminalFlowException` if the run has already finished. Use the safe
variant to no-op instead:

```php
$delivered = SagaFlow::loadFlow($runId)->signalIfRunning('approval', ['approved' => true]);
// $delivered === false on a terminal run
```

`signalIfRunning()` means *"unless the run has already finished"* — it delivers to **any
non-terminal run**, not only a `Running` one.

### `signal()` vs `signalRetry()`

Two different seams, two delivery methods. Mixing them throws.

| | `signal()` / `signalIfRunning()` | `signalRetry()` / `signalRetryIfRunning()` |
|---|---|---|
| **Use when** | The workflow is (or will be) on `awaitSignal('name')` | A step used `retryOnSignal('name')` and you want to wake / early-deliver that policy |
| **Payload** | Yes — returned from `awaitSignal()` | No — wake only; the action re-runs with its original arguments |
| **Name** | Required | Optional: omit to wake whatever is `awaiting_retry`; pass a name to target a declared policy |
| **Wrong family** | `CannotSignalRetryException` if `$name` is a `retryOnSignal()` policy on the run | `InvalidRetrySignalException` if `$name` is not a retry policy on the run |
| **CLI** | `saga-flow:signal {run} {name} [--payload=]` | `saga-flow:signal-retry {run} [name]` |

```php
// ordinary await
SagaFlow::loadFlow($runId)->signal('approval', ['approved' => true]);

// retry-on-signal wake (not signal('balance-refilled'))
SagaFlow::loadFlow($runId)->signalRetry('balance-refilled');
SagaFlow::loadFlow($runId)->signalRetry(); // any awaiting_retry step
```

Bulk wake for a set of parked runs (signal names may differ or be unknown):

```php
SagaFlow::query()
    ->whereAwaitingRetrySignal()
    ->signalable()
    ->whereId(...$runIds)
    ->handles()
    ->each(fn ($handle) => $handle->signalRetry());
```

See [Bulk wake without knowing the signal names](./retry-on-signal.md#bulk-wake-without-knowing-the-signal-names).

Full retry behaviour: [Retry on signal](./retry-on-signal.md).

### Finding the run to signal

Often you do not have the `$runId` on hand — you know the workflow and a tag. Query for it:

```php
SagaFlow::query()
    ->whereWorkflow(ProvisionCompanyWorkflow::class)
    ->whereTag('company', $companyId)
    ->signalable()            // Pending, Running, or Waiting — NOT running()
    ->handles()
    ->first()
    ?->signal('owner-synced');
```

Use `signalable()` (alias `active()`), **not** `running()`. A signal is accepted by any
non-terminal run — `Pending`, `Running`, or `Waiting` — and a flow parked on `awaitSignal()` sits in
**`Waiting`**, not `Running`. Filtering by `running()` would silently miss exactly the run you are
trying to wake.

## Reviving a failed step

That is `signalRetry()`, not `signal()` — see [`signal()` vs `signalRetry()`](#signal-vs-signalretry)
above and [Retry on signal](./retry-on-signal.md).

You can also deliver from the CLI — see [Artisan commands](./artisan-commands.md):

```bash
php artisan saga-flow:signal {run} approval --payload='{"approved":true}'
php artisan saga-flow:signal-retry {run} balance-refilled
```
