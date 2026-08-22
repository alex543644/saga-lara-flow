---
id: events
title: Events
sidebar_position: 21
---

# Events

The engine mirrors its `flow_events` log onto Laravel events you can listen to. Register listeners
the usual way (an event subscriber, `Event::listen`, or a listener class).

```php
use DiscoveryUkraine\SagaLaraFlow\Events\FlowFailed;
use Illuminate\Support\Facades\Event;

Event::listen(FlowFailed::class, function (FlowFailed $event): void {
    report($event->flowRun->workflow_class.' failed: '.$event->flowRun->id);
});
```

:::tip The right place to report failures
`FlowFailed` is the recommended hook for **cross-cutting** failure handling (alerting, reporting,
metrics). It fires exactly once on the terminal transition — on both the direct-fail and the
fail-after-compensation paths, and regardless of whether the run was sync or queued — so you catch
every failed run in one place without wrapping `handle()`. Reserve `try/catch` inside `handle()` for
**local** branching within a single workflow (see [Actions › Handling failure](./actions.md)).
:::

## Available events

Flow lifecycle: `FlowStarted`, `FlowCompleted`, `FlowFailed`, `FlowWaiting`, `FlowResumed`,
`FlowRewoken`, `FlowCancelled`, `FlowExpired`.

Actions: `ActionStarted`, `ActionCompleted`, `ActionFailed`, `ActionRedispatched`,
`OptionalActionFailed`, `ActionAwaitingRetry`, `ActionRetried` (the last two cover
[retry on signal](./retry-on-signal.md)).

Compensations: `CompensationStarted`, `CompensationCompleted`, `CompensationFailed`.

Child workflows: `ChildWorkflowStarted`, `ChildWorkflowCompleted`, `ChildWorkflowFailed`,
`ChildWorkflowCancelled`.

Signals & side effects: `FlowSignalReceived`, `FlowSignalConsumed`, `SideEffectRecorded`,
`SideEffectReused`.

(See `src/Events` for the full list.)

:::warning Listeners must be queued, or must not throw
These events are dispatched from inside the engine's replay. A synchronous listener that throws
interrupts the replay at that point, and the engine reads the exception as a business failure — it
can fail and compensate a run that was doing fine. Mark your listeners `ShouldQueue`, or make sure
they cannot throw.
:::

## Cancellation reason

`FlowCancelled` carries an optional `?string $reason`, populated when you cancel through the handle:

```php
SagaFlow::loadFlow($runId)->cancel('superseded by a newer order');
```

The reason is recorded on the `flow.cancelled` event metadata (no schema change) and passed to the
`FlowCancelled` Laravel event:

```php
Event::listen(FlowCancelled::class, function (FlowCancelled $event): void {
    logger()->info('cancelled', ['id' => $event->flowRun->id, 'reason' => $event->reason]);
});
```
