<?php

namespace DiscoveryUkraine\SagaLaraFlow\Contracts;

use DiscoveryUkraine\SagaLaraFlow\Enums\FlowStatus;
use DiscoveryUkraine\SagaLaraFlow\Exceptions\InvalidTransitionException;
use DiscoveryUkraine\SagaLaraFlow\Models\FlowRun;

/**
 * @internal This contract is an implementation seam for the package's own
 * runtime, not a public extension point. Methods may be added to it in a minor
 * release; swap behaviour through config('saga-lara-flow.models.*') instead.
 */
interface StateMachine
{
    /**
     * Transition a flow run to the given status, persisting the change.
     *
     * @throws InvalidTransitionException
     */
    public function transition(FlowRun $run, FlowStatus $to): FlowRun;

    public function canTransition(FlowStatus $from, FlowStatus $to): bool;
}
