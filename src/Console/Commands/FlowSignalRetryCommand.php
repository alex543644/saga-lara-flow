<?php

namespace DiscoveryUkraine\SagaLaraFlow\Console\Commands;

use DiscoveryUkraine\SagaLaraFlow\Exceptions\CannotSignalTerminalFlowException;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\FlowNotFoundException;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\InvalidRetrySignalException;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\NoAwaitingRetrySignalException;
use DiscoveryUkraine\SagaLaraFlow\FlowManager;
use Illuminate\Console\Command;

/**
 * Delivers a retryOnSignal() wake to a run. Optional {name} targets a declared
 * retry policy; omit it to wake whatever step is awaiting_retry.
 */
class FlowSignalRetryCommand extends Command
{
    protected $signature = 'saga-flow:signal-retry
        {run : The flow run id}
        {name? : The retry signal name (omit to wake any awaiting_retry step)}';

    protected $description = 'Deliver a retry-on-signal wake to a saga flow run.';

    public function handle(FlowManager $manager): int
    {
        try {
            $handle = $manager->loadFlow((string) $this->argument('run'));
        } catch (FlowNotFoundException) {
            $this->error("Flow run [{$this->argument('run')}] not found.");

            return self::FAILURE;
        }

        $name = $this->argument('name');
        $name = is_string($name) && $name !== '' ? $name : null;

        try {
            $handle->signalRetry($name);
        } catch (CannotSignalTerminalFlowException) {
            $this->warn("Flow run [{$handle->id()}] is terminal; retry signal not delivered.");

            return self::SUCCESS;
        } catch (NoAwaitingRetrySignalException|InvalidRetrySignalException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $label = $name ?? 'awaiting retry';
        $this->info("Retry signal [$label] delivered to flow run [{$handle->id()}].");

        return self::SUCCESS;
    }
}
