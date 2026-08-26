---
id: retry-on-signal
title: Retry on signal
sidebar_position: 8
---

# Retry on signal

A step that fails hard takes the whole run with it: the saga rolls back and the work that already
succeeded is undone. That is the right default for a bug, but the wrong one for a step that failed
because *the world was not ready yet* — a card declined for insufficient funds, a downstream
service still provisioning, an approval not yet granted.

`retryOnSignal()` parks such a step instead of failing it. The run waits, nothing rolls back, and
when the named signal arrives **only that step runs again**:

```php
public function handle(string $orderId): void
{
    $this->action(CreateOrder::class, $orderId)
        ->compensateWith(CancelOrder::class, $orderId)
        ->run();

    $this->action(ChargeCard::class, $orderId)
        ->compensateWith(RefundCard::class, $orderId)
        ->retryOnSignal('balance-refilled')
        ->run();

    $this->action(ShipOrder::class, $orderId)->run();
}
```

If `ChargeCard` exhausts its `$tries`, the run goes `Waiting` and the step goes `awaiting_retry`.
`CreateOrder` stays completed and un-compensated. Delivering `balance-refilled` re-runs `ChargeCard`
alone; if it succeeds, the workflow continues to `ShipOrder` as if nothing had happened.

## The full signature

```php
->retryOnSignal(
    string $signal,
    ?int $maxRetries = null,   // null = unbounded
    ?int $waitSeconds = null,  // null = monitor.expiration.defaults.signal
    ?array $only = null,       // null = park on any exception
)
```

- **`$signal`** — the signal name to wait for. An ordinary signal: deliver it exactly the way you
  deliver any other (see [Signals](./signals.md)).
- **`$maxRetries`** — how many signal-gated retry cycles this step may spend. `null` falls back to
  `actions.retry_on_signal.max_retries` in the config, and then to unbounded.
- **`$waitSeconds`** — how long *one* wait may last before the monitor gives up on it. A duration,
  not a moment, because the wait repeats and a `now()->addDay()` would be recomputed on every
  replay.
- **`$only`** — a list of exception classes that may trigger a park. Subclasses count. Anything else
  fails the step normally, so a `TypeError` never parks a saga for a day.

`maxRetries` and `waitSeconds` must be zero or greater — `null`, not a negative number, is how you
say "no limit". A negative value raises an `InvalidArgumentException` rather than reaching the
database, where it would be an error on MySQL and a step that silently never parks elsewhere. The
same applies to the configured `actions.retry_on_signal.max_retries`.

```php
$this->action(ChargeCard::class, $orderId)
    ->compensateWith(RefundCard::class, $orderId)
    ->retryOnSignal(
        'balance-refilled',
        maxRetries: 3,
        waitSeconds: 86400,
        only: [InsufficientBalanceException::class],
    )
    ->run();
```

## Where it sits among the other failure layers

Failure handling stacks, and `retryOnSignal()` sits in the middle:

1. **Laravel's queue retries** (`public int $tries` on the action) run first. The step parks only
   once the queue has given up on it.
2. **`retryOnSignal()`** parks and waits. Every arriving signal spends one cycle and re-runs the
   step, which starts its `$tries` over.
3. **`continueOnFailure()`** — see [Optional actions](./optional-actions.md) — takes over only after
   the retry budget is spent or the wait times out. An optional step with a retry policy waits
   first and falls back to its fallback value second.
4. **Hard failure and compensation** — the ordinary path from
   [Sagas & compensations](./sagas-and-compensation.md).

When the policy gives up, the step fails exactly as it would have without any retry policy: the same
`ActionFailedException`, carrying the message of the **last** attempt, and the same rollback. The
seam is transparent on the way out — there is no new exception class to catch.

## What ends the waiting

A step stops waiting when any one of these happens:

- **The signal arrives.** One cycle is spent, the step runs again.
- **The budget runs out.** `retry_signal_attempts` reaches `maxRetries` → hard failure.
- **The wait times out.** The monitor flips the wait to `timed_out` → hard failure.
- **The run expires** on its own `expires_at`, or is cancelled.

:::info A timed-out wait ends the policy for good
The wait deadline bounds *the waiting*, not one wait out of several. Once a wait has timed out, the
step fails even if budget is left — otherwise a signal that is never coming would hand the step a
fresh deadline on every replay and the run would never finish.
:::

## The budget is read from the row, not from your code

A step's ceiling is written to `action_runs.retry_signal_max_attempts` when the step is scheduled,
and every later replay reads it from there. Changing `maxRetries:` in the workflow — or the config
default — does **not** move the ceiling under a run that is already parked. Runs started after the
deploy get the new value.

That is deliberate: the budget belongs to the run, and a redeploy must not silently extend or cut
short a wait that is already in flight.

## Inside a saga group

`saga()->step()` mirrors the method, and it means exactly the same thing:

```php
$this->saga()
    ->step(CreateOrder::class, $orderId)->compensateWith(CancelOrder::class, $orderId)
    ->step(ChargeCard::class, $orderId)
        ->compensateWith(RefundCard::class, $orderId)
        ->retryOnSignal('balance-refilled', maxRetries: 3)
    ->step(ShipOrder::class, $orderId)
    ->run();
```

## Delivering the signal

Nothing special — it is an ordinary signal:

```php
SagaFlow::loadFlow($runId)->signal('balance-refilled');
```

```bash
php artisan saga-flow:signal 01JABCDEF... balance-refilled
```

Usually you do not have the run id at hand. Query for it with `whereAwaitingRetrySignal()`, and
filter with `signalable()` (a parked run is `Waiting`, never `Running`):

```php
SagaFlow::query()
    ->whereWorkflow(CheckoutWorkflow::class)
    ->whereTag('customer', $customerId)
    ->whereAwaitingRetrySignal('balance-refilled')
    ->signalable()
    ->handles()
    ->first()
    ?->signal('balance-refilled');
```

`whereAwaitingSignal()` is the wider filter: it also matches a run parked by `awaitSignal()` on that
name. See [Tags & querying](./tags-and-querying.md#waits-and-parked-steps).

The signal's **payload is not passed to the action**. Action arguments must stay identical across
replays, so the retried step runs with the arguments it was given originally; the payload is stored
on the signal row for auditing. If the retry needs new data, read it inside the action.

## Observing parked runs

`saga-flow:list` annotates a parked run with the signal it is waiting for, and `saga-flow:show`
gains a **Retry** column showing the signal, the spent budget, and the current deadline:

```
Seq  Status          Action       Attempts  Retry                                  Finished
1    completed       CreateOrder  1         —                                      ...
2    awaiting_retry  ChargeCard   3         balance-refilled 1/3 until 2026-08-24…  ...
3    pending         ShipOrder    0         —
```

Two events cover the lifecycle — see [Events](./events.md):

- **`ActionAwaitingRetry`** — fires once per park, carrying the step and the signal name.
- **`ActionRetried`** — fires once per cycle, when the step is about to run again.

Both are dispatched after the surrounding transaction commits, so a listener never reacts to a retry
the database rolled back.

## Determinism

A retry does **not** consume a new sequence. The step keeps its `(flow_run_id, sequence)` ordinal
and reuses the same `action_runs` row for every cycle, and waiting consumes no ordinal at all. That
means downstream steps land on identical ordinals whether the step retried zero times or ten, and
`handle()` replays exactly as it did before — see [Determinism rules](./determinism-rules.md).

The counters are two different things: `attempts` keeps counting *every* execution cumulatively
(including queue retries), while `retry_signal_attempts` counts only the signal-gated cycles.

## Things worth knowing

- **Don't wrap a step in your own `DB::transaction()`.** The engine suspends by throwing, so an
  application transaction around `->run()` would roll the suspension's bookkeeping back. This is true
  of every suspending seam in the package, not only this one.
- **Package event listeners must be queued (`ShouldQueue`) or must not throw.** A throwing listener
  on `ActionRetried` or `FlowSignalConsumed` interrupts the replay mid-way, and the engine reads that
  as a business failure.
- **Turn `repair.enabled` on in production.** If a process dies between committing a retry and
  dispatching its job, the step sits `Pending` with no job behind it. The doctor re-dispatches it —
  but it is off by default. See [Expiration & monitoring](./expiration-and-monitoring.md).
- **A signal delivered in the same second the step failed counts for that failure.** The engine
  matches a floating signal to the attempt with second resolution, so a signal that landed just
  *before* the failure in the same second is treated as arriving after it. The cost is bounded: at
  most one extra cycle, and the budget still applies.
- **The three wait-signal transitions raise no Eloquent model events.** Delivery into an open wait,
  closing a superseded wait, and timing a wait out are written as single conditional `UPDATE`s —
  the only form that is atomic on every supported driver. An observer registered on a swapped-in
  `models.flow_signal` will not see them. Every transition is still recorded in `flow_events`, and
  where one exists, in the package's own Laravel event.
