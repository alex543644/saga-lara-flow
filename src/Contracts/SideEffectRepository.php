<?php

namespace DiscoveryUkraine\SagaLaraFlow\Contracts;

use DiscoveryUkraine\SagaLaraFlow\Models\SideEffect;

/**
 * @internal This contract is an implementation seam for the package's own
 * runtime, not a public extension point. Methods may be added to it in a minor
 * release; swap behaviour through config('saga-lara-flow.models.*') instead.
 */
interface SideEffectRepository
{
    public function find(string $flowRunId, int $sequence): ?SideEffect;
}
