---
id: sagas-and-compensation
title: Sagas & compensations
sidebar_position: 6
---

# Sagas & compensations

The Saga pattern trades distributed transactions for **compensating actions**: each step registers
how to undo itself, and on failure the engine rolls completed steps back in **reverse order**.

## Action-level compensation

The primary style attaches an undo to each step:

```php
public function handle(string $orderId): void
{
    $this->action(ChargeCard::class, $orderId)
        ->compensateWith(RefundCard::class, $orderId)
        ->run();

    $this->action(ReserveStock::class, $orderId)
        ->compensateWith(ReleaseStock::class, $orderId)
        ->run();

    // If this throws, ReleaseStock then RefundCard run automatically.
    $this->action(ShipOrder::class, $orderId)->run();
}
```

Compensation can also be a closure:

```php
$this->action(MakeReservation::class, $id)
    ->compensateWith(fn () => Reservation::release($id))
    ->run();
```

## Grouped sagas

`saga()` expresses a compensation boundary explicitly and exposes group-level policies:

```php
use DiscoveryUkraine\SagaLaraFlow\Enums\CompensationFailurePolicy;

$this->saga()
    ->onCompensationFailure(CompensationFailurePolicy::Continue)
    ->compensateInParallel()
    ->step(ChargeCard::class, $orderId)->compensateWith(RefundCard::class, $orderId)
    ->step(ReserveStock::class, $orderId)->compensateWith(ReleaseStock::class, $orderId)
    ->run();
```

- `compensateInParallel()` runs the group's undos concurrently (a single rollback level: together via
  `Bus::batch` when queued, sequentially under `runSync`).
- `compensateStepOnSelfFailure()` also compensates a step that *itself* failed (for non-atomic
  actions that may leave partial effects) — such compensations must be idempotent.

## Failure policies

`CompensationFailurePolicy`:

- `Stop` (default) — halt the rollback on the first compensation that does not complete.
- `Continue` — keep rolling back even if one undo does not complete.

Precedence is **action > group > config** (`sagas.default_compensation_failure_policy`). If a
compensation itself fails under `Stop`, a `CompensationFailedException` surfaces.

"Does not complete" covers more than a throw. A compensation whose worker was killed, or whose job
never arrived, is left `Pending` or `Running` when its level finishes, and `Stop` halts the rollback
for that too: unwinding further on top of a step that may still stand is what the policy exists to
prevent. The run records it under `flow_run.exception['compensation']` as a
`CompensationUnfinishedException` rather than a `CompensationFailedException` — there is no cause to
report, since it never got far enough to have one. A rollback therefore never finalizes looking
clean while one step was silently left undone.

The exception states what was observed: the compensation *had not finished when its rollback level
ended*. Usually it never does. It can also mean an at-least-once queue closed the batch a moment
before the live worker recorded its success — see
[Reclaim & recovery](./reclaim-and-recovery.md) for that race and how to spot it in your logs.

To have such a compensation retried rather than only reported, enable `sagas.reclaim.stale_running`
— see [Reclaim & recovery](./reclaim-and-recovery.md).

## Manual compensation

You can trigger a rollback from outside the workflow through the handle:

```php
SagaFlow::loadFlow($runId)->compensate(); // roll back completed steps, then cancel
```

## Postponing a rollback

Not every failure deserves a rollback. When a step failed only because the world was not ready — a
declined card, a service still provisioning — `retryOnSignal()` parks that step and waits instead of
compensating, and re-runs it alone when the signal arrives. Compensation happens only if the retry
policy eventually gives up. See [Retry on signal](./retry-on-signal.md).
