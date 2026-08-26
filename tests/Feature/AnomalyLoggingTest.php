<?php

use DiscoveryUkraine\SagaLaraFlow\Contracts\FlowRepository;
use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\ActionRun;
use DiscoveryUkraine\SagaLaraFlow\Runtime\ActionRecorder;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\MakeValueAction;
use DiscoveryUkraine\SagaLaraFlow\Tests\Fixtures\OneActionWorkflow;

/**
 * Every path AnomalyLog records — a claim lost to whoever already owns the row, an
 * outcome write rejected because the row changed hands, a batch closed early by a
 * duplicate — deliberately does not fail the job. The log line is therefore the only
 * evidence they happened, and the only way to find out afterwards why a step ran
 * twice, why a result went missing, or why a run woke one time too many.
 *
 * Asserted against a real log file rather than a mocked logger, so what is checked
 * is what an operator would actually be able to grep for.
 */
function anomalyLogPath(): string
{
    $path = sys_get_temp_dir().'/saga-anomaly-'.uniqid().'.log';

    logToFile($path);

    return $path;
}

function claimedRow(): ActionRun
{
    $run = app(FlowRepository::class)->create([
        'workflow_class' => OneActionWorkflow::class,
        'status' => FlowStatus::Waiting,
        'arguments' => [],
    ]);

    return ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Completed,
        'attempts' => 1,
    ]);
}

it('logs a lost claim with enough context to find the step again', function () {
    $path = anomalyLogPath();

    $action = claimedRow();

    expect(app(ActionRecorder::class)->startAction($action))->toBeFalse();

    $log = file_get_contents($path);

    // Monolog JSON-encodes the context, so the class name appears with its
    // backslashes escaped.
    expect($log)->toContain('claim_lost')
        ->and($log)->toContain($action->id)
        ->and($log)->toContain('MakeValueAction');
});

it('logs a rejected outcome write', function () {
    $path = anomalyLogPath();

    $run = app(FlowRepository::class)->create([
        'workflow_class' => OneActionWorkflow::class,
        'status' => FlowStatus::Waiting,
        'arguments' => [],
    ]);

    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Running,
        'attempts' => 1,
    ]);

    // Somebody else claimed the row after this executor did.
    ActionRun::query()->whereKey($action->id)->update(['attempts' => 2]);

    expect(app(ActionRecorder::class)->completeAction($action, ['label' => 'stale']))->toBeFalse();

    expect(file_get_contents($path))->toContain('outcome_rejected');
});

it('writes nothing when anomaly logging is switched off', function () {
    $path = anomalyLogPath();
    config()->set('saga-lara-flow.logging.anomaly_level', null);

    expect(app(ActionRecorder::class)->startAction(claimedRow()))->toBeFalse();

    expect(file_exists($path))->toBeFalse();
});

it('honours the configured level', function () {
    $path = anomalyLogPath();
    config()->set('saga-lara-flow.logging.anomaly_level', 'warning');

    expect(app(ActionRecorder::class)->startAction(claimedRow()))->toBeFalse();

    expect(file_get_contents($path))->toContain('WARNING');
});

// --- A broken logger must not be what fails the job ---
//
// These paths exist precisely so a normal at-least-once anomaly does not fail
// anything. If the log line could throw, a misconfigured logger would turn every such
// anomaly into a failed job — and worse, an exception escaping a rejected outcome
// write reaches RunActionJob::failed(), which writes queue bookkeeping into a row this
// worker no longer owns. So anomaly logging is best-effort in both directions: the
// level is validated before it reaches Monolog, and anything the write itself throws
// is swallowed.
//
// A channel name that does not exist is deliberately not among the cases: Laravel's
// LogManager already falls back to an emergency logger rather than throwing.

it('still abandons the claim quietly when the configured level is nonsense', function () {
    $path = anomalyLogPath();
    config()->set('saga-lara-flow.logging.anomaly_level', 'shout');

    expect(app(ActionRecorder::class)->startAction(claimedRow()))->toBeFalse();

    // An unusable level is treated as "off" rather than handed to Monolog, which
    // rejects it with an InvalidArgumentException.
    expect(file_exists($path))->toBeFalse();
});

it('still rejects an outcome quietly when the log channel cannot be written to', function () {
    $run = app(FlowRepository::class)->create([
        'workflow_class' => OneActionWorkflow::class,
        'status' => FlowStatus::Waiting,
        'arguments' => [],
    ]);

    $action = ActionRun::create([
        'flow_run_id' => $run->id,
        'sequence' => 0,
        'action_class' => MakeValueAction::class,
        'status' => ActionStatus::Running,
        'attempts' => 1,
    ]);

    // Somebody else claimed the row after this executor did.
    ActionRun::query()->whereKey($action->id)->update(['attempts' => 2]);

    // A path no process can open: the handler throws when the line is written, which
    // is the shape of every "the log backend is down" failure.
    logToFile('/proc/self/no/such/place/anomaly.log');

    expect(app(ActionRecorder::class)->completeAction($action, ['label' => 'stale']))->toBeFalse()
        ->and($action->fresh()->status)->toBe(ActionStatus::Running);
});
