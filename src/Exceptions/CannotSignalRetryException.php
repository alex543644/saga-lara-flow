<?php

namespace DiscoveryUkraine\SagaLaraFlow\Exceptions;

use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;

/**
 * Raised when signal() / signalIfRunning() is used with a name that is a
 * retryOnSignal() policy on this run. Deliver those with signalRetry().
 */
class CannotSignalRetryException extends FlowException
{
    public static function for(FlowRun $flowRun, string $name): self
    {
        return new self(
            "Flow run [{$flowRun->id}] treats [{$name}] as a retry signal; use signalRetry() instead of signal()."
        );
    }
}
