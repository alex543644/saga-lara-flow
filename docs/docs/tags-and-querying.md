---
id: tags-and-querying
title: Tags & querying
sidebar_position: 13
---

# Tags & querying

## Tagging runs

Attach searchable key/value tags at creation, declaratively, from inside the workflow, or from
outside through a `FlowHandle`:

```php
SagaFlow::create(CheckoutWorkflow::class)
    ->withTags(['tenant' => 'acme', 'channel' => 'web'])
    ->run();
```

```php
// declaratively on the workflow class (repeatable)
#[Tag('orders')]
#[Tag('team', 'checkout')]
class CheckoutWorkflow extends Workflow { /* ... */ }
```

```php
// from inside handle()
$this->tag('priority', 'high');

// or several at once
$this->tags([
    'priority' => 'high',
    'attempt' => 2,      // int values are cast to string
    'orders' => null,    // a tag with no value
]);
```

```php
// from outside, on a loaded handle — same updateOrCreate semantics
SagaFlow::loadFlow($runId)
    ->tag('payment-failed')
    ->withTags(['attempt' => 2, 'orders' => null]);
```

Explicit tags passed to `withTags()` override attribute tags with the same key. The batch writer on
a handle is `withTags()`, not `tags()`, because `FlowHandle::tags()` is already the read accessor
and `CreateWorkflowBuilder` already uses `withTags()` for the same write.

Re-tagging an existing key overwrites its value rather than adding a second tag — the database
enforces one row per `(flow_run_id, key)`. Both `tag()` and `tags()` / `withTags()` are idempotent
across replays — safe to call unconditionally at the top of `handle()`.

:::caution Outside tags vs workflow tags
Tags are not history: they carry no sequence and are never consulted during replay. A workflow
calling `$this->tag('x', ...)` in `handle()` re-runs that write on **every replay**, so a value a
host application set through `FlowHandle::tag('x', ...)` is overwritten the next time the run
resumes. Tag keys written from outside should not collide with keys the workflow writes itself.
:::

## Querying runs

`SagaFlow::query()` returns a fluent, type-safe `FlowQuery` over flow runs:

```php
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;

$stuck = SagaFlow::query()
    ->whereWorkflow(CheckoutWorkflow::class)
    ->whereTag('tenant', 'acme')
    ->waiting()
    ->before(now()->subHour())
    ->get(); // Collection<FlowRun>
```

### Filters

- `whereTag(string $key, ?string $value = null)`
- `whereStatus(FlowStatus ...$statuses)` and shortcuts `running()`, `waiting()`, `completed()`, `failed()`
- `active()` (alias `signalable()`) — runs that can still receive a signal: `Pending`, `Running`,
  or `Waiting`. Use this to find a run to deliver a signal to: a flow parked on `awaitSignal()` is
  `Waiting`, not `Running`, so `running()` would miss it.
- `whereWorkflow(string $workflowClass)`
- `whereAwaitingSignal(?string $name = null)` — runs with an open wait for a signal, whichever seam
  opened it (`awaitSignal()` or `retryOnSignal()`). A null `$name` matches any.
- `whereAwaitingRetrySignal(?string $signal = null)` — runs holding a step parked by
  `retryOnSignal()` (`action_runs` in `awaiting_retry`). A null `$signal` matches any. This is a
  **subset** of `whereAwaitingSignal()` for the same name: every retry park also writes a Waiting
  row in `flow_signals`, so the two states are indistinguishable from signals alone.
- `before(DateTimeInterface)` / `after(DateTimeInterface)` (both filter `created_at`)

```php
// everything blocked on this name, planned waits included
SagaFlow::query()->whereAwaitingSignal('approval')->get();

// only steps that failed and parked
SagaFlow::query()->whereAwaitingRetrySignal('balance-refilled')->handles();
```

| | `awaitSignal('approval')` | `retryOnSignal('balance-refilled')` |
|---|---|---|
| `flow_runs.status` | `waiting` | `waiting` |
| row in `flow_signals` | `approval / waiting` | `balance-refilled / waiting` |
| row in `action_runs` at that wait | none | `awaiting_retry`, `retry_signal` set |
| failure snapshot for operators | none | `exception: {class, message, code}` |

### Terminals

- `get(): Collection<FlowRun>`
- `first(): ?FlowRun`
- `count(): int`
- `paginate(int $perPage = 15): LengthAwarePaginator`
- `handles(): Collection<FlowHandle>` — hydrate matched runs as operable handles
- `builder(): Builder<FlowRun>` — escape hatch to the raw Eloquent builder for ordering/limits

```php
$handles = SagaFlow::query()->running()->handles();
$page    = SagaFlow::query()->failed()->paginate(25);
$latest  = SagaFlow::query()->builder()->latest()->limit(10)->get();
```
