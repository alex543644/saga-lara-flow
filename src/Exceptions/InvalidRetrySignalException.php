<?php

namespace DiscoveryUkraine\SagaLaraFlow\Exceptions;

use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;

/**
 * Raised when signalRetry() is given a name that is not a retryOnSignal()
 * policy on this run. Ordinary awaits use signal().
 */
class InvalidRetrySignalException extends FlowException
{
    public static function for(FlowRun $flowRun, string $name): self
    {
        return new self(
            "Flow run [{$flowRun->id}] has no retryOnSignal() policy named [{$name}]; use signal() for ordinary awaits."
        );
    }
}
