# Upgrading

## From 1.0.x to 1.1.0

> ### ⚠️ Run `php artisan migrate` immediately after upgrading
>
> This is **not** a "the new feature stays unavailable until you migrate" situation. Every action the
> engine schedules — with or without a retry policy — writes the columns added by this release. An
> application that upgrades the package without running its migrations will break **ordinary workflow
> execution** with an unknown-column error on the very next action it schedules.
>
> ```bash
> composer update discovery-ukraine/saga-lara-flow
> php artisan migrate
> ```
>
> Deploy the two together. If your deploy runs migrations after the new code is already serving
> traffic, expect failures in that window.

### What's new

`retryOnSignal()` on an action (and on `saga()->step()`) parks a failed step until a named signal arrives, then re-runs
that step alone instead of failing the run and compensating it. See the
[Retry on signal](https://sagalaraflow.dev/retry-on-signal) guide.

Everything in this release is additive for workflows that never call `retryOnSignal()`. The three notes below are the
only places where existing behaviour changed.

### 1. The new migration

`add_retry_on_signal_to_action_runs` adds four nullable/defaulted columns to `action_runs`:
`retry_signal`, `retry_signal_attempts`, `retry_signal_max_attempts`, and
`queue_attempts_exhausted`. It runs from the package like the initial migration — do not
`vendor:publish` it.

### 2. `SignalRepository` gained two methods

If you bound your own implementation of `DiscoveryUkraine\SagaLaraFlow\Contracts\SignalRepository`, it must now also
implement:

```php
public function earliestPendingSince(string $flowRunId, string $name, ?DateTimeInterface $since): ?FlowSignal;

public function latestForSequence(string $flowRunId, int $sequence): ?FlowSignal;
```

The simplest fix is to extend `Repositories\EloquentSignalRepository` rather than implement the interface from scratch.

This is a minor release rather than a major one because the repository contracts are **not a public extension point**.
They are now marked `@internal`, and the supported way to change persistence behavior is
`config('saga-lara-flow.models.*')`, which is unaffected. Methods may be added to these contracts in future minor
releases on the same terms.

### 3. Two signal transitions no longer raise Eloquent model events

Delivering a signal into an open wait, and timing a wait out, are now written as single conditional
`UPDATE` statements instead of `save()` calls. That is the only form that is atomic on every supported driver, and it is
what keeps a delivery and a timeout from both claiming the same wait.

The consequence: an **observer** registered on a swapped-in `models.flow_signal` no longer sees
`updating`/`updated` for those two transitions (nor for the new "wait superseded" transition). If you rely on model
events for auditing or multi-tenancy bookkeeping, move that listener onto the package's own events —
`FlowSignalReceived`, `FlowSignalConsumed` — or read the `flow_events` log, both of which still record every transition.

### Recommended while you are here

- **Turn `repair.enabled` on.** The doctor is what recovers a step whose queue job was lost to a dying process. It is
  off by default and does nothing until you opt in.
- **Check your package-event listeners.** A synchronous listener that throws interrupts the engine's replay and can fail
  a healthy run. Mark them `ShouldQueue`, or make sure they cannot throw.
