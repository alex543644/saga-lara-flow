# Upgrading

## From 1.1.x to 1.2.0

> ### ⚠️ Run `php artisan migrate` immediately after upgrading
>
> Every action and compensation the engine schedules writes the columns added by this release. Deploy
> the migration together with the code, the same as every other release.

### What's new

`ActionRecorder::startAction()` and `CompensationRecorder::startCompensation()` now claim their row
atomically instead of writing to it unconditionally, closing a check-then-act race where a stale
job (a superseded retry cycle, a row the monitor just expired, or a job the doctor sent on top of a
live one) could execute a step a second time. See [Queues, locks & idempotency](https://sagalaraflow.dev/queues-locks-idempotency)
for the full picture, and the new **reclaim** section below for the opt-in half.

A companion mechanism, `actions.reclaim.stale_running` / `sagas.reclaim.stale_running`, lets a
`Running` row be claimed again once its reclaim window has passed — recognising a worker that died
mid-execution. Off by default; enable it globally or per action/compensation
(`reclaimStaleAfter()`, `enableStaleReclaim()`, and their `...Compensation...` mirrors on
`ActionBuilder`/`SagaStepBuilder`).

### 1. A `Running` row is no longer picked up by a redelivered job

This is the one change to be deliberate about, and it applies **whether or not you enable reclaim**.

The claim accepts a row that is `Pending` or `Failed`. A row that is already `Running` is accepted
only once its reclaim window has passed — and with reclaim off, the default, there is no window, so
it is never accepted. Previously any redelivered job would simply execute such a row again.

What that buys you: the engine's own machinery can no longer produce a duplicate execution. A job
left over from a superseded retry cycle, a job racing a row the monitor just expired, and a job the
doctor dispatched on top of one still running are all now refused. (What no engine can prevent is
the queue driver's own at-least-once delivery — SQS visibility timeouts, `retry_after` on
Redis/database — redelivering a job while the first attempt is still alive. That is the documented
baseline your action code already has to tolerate.)

What it costs you: if a worker is killed mid-execution — an evicted pod, the OOM killer, a `SIGKILL`
deploy — its row stays `Running`, and with everything at its default nothing brings it back. Replay
treats a `Running` step as still in flight and parks the run, and `saga-flow:kick` does the same, so
the run waits indefinitely. Choose one of the two recoveries if that matters to you:

- **`reclaim`** — the row becomes claimable again after its window, and the step **runs again**.
  Recovery of the work itself. See the [reclaim guide](https://sagalaraflow.dev/reclaim-and-recovery).
- **The monitor** — set `monitor.expiration.defaults.action` (or `->expiresAt()`) and schedule
  `saga-flow:monitor`. The step is marked `Expired` and the run **fails as a business error**
  instead of hanging. Recovery of the run, not of the work.

They are complementary: reclaim retries, the monitor gives up. Neither runs unless you turn it on.

### 2. The new migration

`add_reclaim_stale_running_columns` adds `reclaim_stale_after_seconds` (the configured window) and
`reclaim_stale_at` (the absolute deadline the claim derives from it) to both `action_runs` and
`compensation_runs`, plus an `attempts` counter on `compensation_runs` mirroring the one
`action_runs` already had. It runs from the package like every other migration — do not
`vendor:publish` it.

### 3. Six transitions no longer raise Eloquent model events

`startAction()` / `startCompensation()` and the four outcome writes
(`completeAction()`, `failAction()`, `completeCompensation()`, `failCompensation()`) are now
conditional `UPDATE` statements, the same shape (and for the same reason — the only form every
supported driver enforces atomically) as the signal transitions changed in 1.1.0.

The consequence, exactly as documented then: an **observer** registered on a swapped-in
`models.action_run` or `models.compensation_run` no longer sees `updating`/`updated` for these
transitions. Nothing is lost, though — each still records its own package event and `flow_events`
entry whenever it actually writes: `ActionStarted` (`action.started`, unchanged), `ActionCompleted`,
`ActionFailed`, `CompensationCompleted`, `CompensationFailed`, and the new `CompensationStepStarted`
(`compensation.step_started` — distinct from `CompensationStarted`, which marks the whole rollback
beginning once per run, not once per compensation). Listen to those, or read `flow_events`, instead
of Eloquent hooks.

### 4. A recorded outcome can no longer be overwritten by a superseded worker

Enabling reclaim lets a second worker take over a row whose first worker may be slow rather than
dead, so both can reach the end of the same step. The outcome writes are therefore fenced against
the claim that produced them (via `attempts`, which only the claim increments): an executor that has
been superseded updates no rows, raises no event, and its job does not fail. In practice this means
a **recorded success is never demoted** — a straggler that fails after the live worker succeeded can
no longer flip the step to `Failed` and send the saga rolling back over work that actually went
through.

The fence has a second condition alongside `attempts`: the row must still be `Running`. That guards
against a settlement that never claimed the row at all — the monitor expiring an overdue step does
not touch `attempts`. `ActionRecorder::expireAction()` is conditional for the same reason, from the
other side: a step that completes just before the sweep reaches it is not demoted to `Expired`. It
now returns `bool`, and the monitor counts and wakes only for an expiry it actually won.

Every quiet path — a lost claim, a rejected outcome, a batch closed early — is logged, since nothing
else records them. See `logging.anomaly_level` in item 7.

### 5. `ActionRunRepository` gained one method

If you bound your own implementation of `DiscoveryUkraine\SagaLaraFlow\Contracts\ActionRunRepository`, it must now also
implement:

```php
public function dueForStaleRunningRepair(int $limit, int $maxAttempts): iterable;
```

As with the 1.1.0 note on `SignalRepository`, the simplest fix is to extend
`Repositories\EloquentActionRunRepository` rather than implement the interface from scratch — it
remains `@internal` and not a public extension point.

### 6. `RunCompensationJob` now takes its own queue lock

Compensations previously had no `WithoutOverlapping` protection at all (actions and parallel steps
always did). A new `locks.compensation_ttl_seconds` (default 900, independent of
`locks.action_ttl_seconds`) governs it; set `locks.enabled = false` to keep locking off entirely, as
before.

**If you published the config file before this release**, add the key to your `locks` array —
package config is merged only at the top level, so your own `locks` array is taken as it stands and
the new key is not filled in for you. Nothing breaks if you forget: a missing value inherits
`action_ttl_seconds`, and a zero or missing TTL can never produce a lock without an expiry.

### 7. New: `logging`

```php
'logging' => [
    'anomaly_level' => env('SAGA_LARA_FLOW_ANOMALY_LOG_LEVEL', 'info'),
    'channel' => env('SAGA_LARA_FLOW_LOG_CHANNEL'),
],
```

The first logging the package has ever done. `Runtime\AnomalyLog` is a second journal beside the
`flow_events` business history, and it covers exactly the things that are otherwise untraceable:

- `claim_lost` — a claim lost to whoever already owned the row.
- `outcome_rejected` — an outcome write refused because the row had changed hands.
- `batch_finished_early` — a parallel step completed after a duplicate delivery had already closed
  its batch.

All three are normal under at-least-once delivery and none of them fails a job, so without this
there would be nothing to investigate afterwards. Each line carries the run id, row id, sequence and
class. Set `anomaly_level` to `null` to silence it, or to `warning` if you want these surfaced by
your alerting. Writing the line is best-effort: an unusable level is treated as off, and a logger
that throws is swallowed rather than allowed to fail a job that was deliberately giving up quietly.

### 8. An unfinished compensation now stops a rollback

A compensation left `Pending` or `Running` when its level finishes — its worker died, or its job
never arrived — is treated the way a failed one is: under the `Stop` policy it halts the rollback,
and either way it is recorded as the run's secondary cause under `flow_run.exception['compensation']`.
Previously only `Failed` rows were looked for, so a rollback could finalize with a step silently
never undone and no trace of it anywhere. A compensation registered with the `Continue` policy still
does not stop the unwind.

### 9. Three scheduling methods take an `ActionSchedule`

The step's options used to be passed as a long tail of optional arguments. They are now carried by
`Data\ActionSchedule`, a readonly object with the same names as before:

```php
use DiscoveryUkraine\SagaLaraFlow\Data\ActionSchedule;

// before
$dispatcher->dispatch($run, $sequence, ChargeCard::class, [$orderId], true, false, $expiresAt);

// after
$dispatcher->dispatch($run, $sequence, new ActionSchedule(
    actionClass: ChargeCard::class,
    arguments: [$orderId],
    hasCompensation: true,
    expiresAt: $expiresAt,
));
```

Affected: `ActionDispatcher::dispatch()`, `ActionDispatcher::runInline()`, and
`ActionRecorder::scheduleAction()`. Nothing in the workflow API changes — this only matters if your
code calls those runtime classes directly.

### 10. `execute()` reports how far a step got

`ActionDispatcher::execute()`, `CompensationExecutor::execute()` and `ActionDispatcher::runInline()`
return `Enums\StepExecution` instead of `void`:

- **`Executed`** — the row was claimed, the step ran, and its outcome was recorded.
- **`ClaimLost`** — the row was already owned or no longer claimable; nothing ran.
- **`Superseded`** — the step ran, but the row changed hands before its outcome could be written.

The two failing cases look the same from a queued job, which returns quietly either way; call
`->settled()` if that is all you need. They differ where no competitor is supposed to exist: a
freshly created row that cannot be claimed is a broken invariant and still raises
`ActionClaimFailedException`, while being superseded afterwards is an ordinary race — the monitor and
the doctor act on the rows a sync run creates from their own processes — and replay resolves it from
whatever the row ended up holding.

### 11. Two more migrations: wait indexes, then one row per tag key

They ship as separate files because only the second one deletes anything. MySQL runs neither inside
a transaction, so both are written to be repeatable: whatever point a run dies at, running it again
carries on from there.

**`index_signal_waits`** supports the two new `FlowQuery` filters. `flow_signals` gains
`(status, name, flow_run_id)` and `action_runs` gains `(status, retry_signal, flow_run_id)`; the
shipped indexes on both tables lead with `flow_run_id`, which cannot serve a lookup by signal name
across runs.

**`unique_flow_tag_keys`** narrows `flow_tags` uniqueness from `(flow_run_id, key, value)` to
`(flow_run_id, key)`, the pair every tag writer matches on. The wider unique let two concurrent
first writes for one key insert two rows with different values.

> #### ⚠️ Rows are deleted if you have such duplicates
>
> For each `(flow_run_id, key)` the row with the highest `updated_at` is kept, the highest `id`
> breaking a tie, and the rest are removed. `flow_tags` timestamps are second-precision, so two
> writes inside one second fall back to insertion order. The rollback restores the old constraint
> but cannot bring deleted rows back.
>
> **Pause writes to `flow_tags` while this runs.** A write landing on an already-duplicated key
> between the winner being chosen and the losers being deleted is lost without a trace, and running
> the migration again will not bring it back. Keys that are not already duplicated are never touched.

Count the duplicates first — the table carries your configured `database.table_prefix`, `saga_` by
default:

```sql
-- MySQL
select flow_run_id, `key`, count(*) from saga_flow_tags group by flow_run_id, `key` having count(*) > 1;

-- PostgreSQL, SQLite
select flow_run_id, "key", count(*) from saga_flow_tags group by flow_run_id, "key" having count(*) > 1;
```

The narrower unique goes on before the wider one comes off, so the table is never left without one.
A tag written for a brand-new key while the migration sits between the cleanup and the constraint
can still make it fail; run it again.

If the count comes back empty there is nothing to lose and nothing to pause for — the migration adds
the constraint and stops.

Tag writing is otherwise unchanged: one value per key per run, last write wins. The int-to-string
normalisation now also sits on `Models\FlowTag` as a cast, so a `models.flow_tag` replacement should
extend that model rather than reimplement it.

### Recommended while you are here

- **Decide how a killed worker should be recovered** — see item 1. Leaving both reclaim and the
  monitor off means such a run waits forever, which is safe but needs a human.
- Read the [reclaim guide](https://sagalaraflow.dev/reclaim-and-recovery) before enabling reclaim in
  production: it changes which `Running` rows a redelivered job, or the doctor, may re-execute.
- If you already turned `repair.enabled` on, note that its new R3 rule
  (`repair.redispatch_stale_running_actions`, default `true`) acts on any row carrying a reclaim
  window — including one enabled per step via `reclaimStaleAfter()` while
  `actions.reclaim.stale_running.enabled` is globally `false`, since a per-step override outranks the
  global switch everywhere else too.

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
