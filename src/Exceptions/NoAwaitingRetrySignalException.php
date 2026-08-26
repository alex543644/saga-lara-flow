<?php

namespace DiscoveryUkraine\SagaLaraFlow\Exceptions;

use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;

class NoAwaitingRetrySignalException extends FlowException
{
    public static function for(FlowRun $flowRun): self
    {
        return new self(
            "Flow run [{$flowRun->id}] has no step awaiting a retry signal."
        );
    }
}
