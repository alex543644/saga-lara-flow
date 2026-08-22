<?php

namespace DiscoveryUkraine\SagaLaraFlow\Contracts;

use DiscoveryUkraine\SagaLaraFlow\Models\FlowChild;
use Illuminate\Database\Eloquent\Collection;

/**
 * @internal This contract is an implementation seam for the package's own
 * runtime, not a public extension point. Methods may be added to it in a minor
 * release; swap behaviour through config('saga-lara-flow.models.*') instead.
 */
interface FlowChildRepository
{
    /**
     * The child link recorded at a parent's (parent_flow_run_id, sequence) ordinal.
     */
    public function find(string $parentFlowRunId, int $sequence): ?FlowChild;

    /**
     * Child links of a parent whose child run is not yet terminal.
     *
     * @return Collection<int, FlowChild>
     */
    public function active(string $parentFlowRunId): Collection;
}
