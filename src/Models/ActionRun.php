<?php

namespace DiscoveryUkraine\SagaLaraFlow\Models;

use DiscoveryUkraine\SagaLaraFlow\Enums\ActionStatus;
use DiscoveryUkraine\SagaLaraFlow\Models\Concerns\UsesSagaFlowConnection;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $flow_run_id
 * @property int $sequence
 * @property string $action_class
 * @property ?string $action_name
 * @property ActionStatus $status
 * @property bool $continue_on_failure
 * @property bool $has_compensation
 * @property ?string $retry_signal
 * @property int $retry_signal_attempts
 * @property ?int $retry_signal_max_attempts
 * @property ?int $parallel_group
 * @property ?array<int|string, mixed> $arguments
 * @property mixed $result
 * @property ?array<int|string, mixed> $exception
 * @property int $attempts
 * @property bool $queue_attempts_exhausted
 * @property ?Carbon $started_at
 * @property ?int $reclaim_stale_after_seconds
 * @property ?Carbon $reclaim_stale_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $expires_at
 * @property int $repair_attempts
 * @property ?Carbon $repair_available_at
 * @property-read FlowRun $flowRun
 */
class ActionRun extends Model
{
    use HasUlids;
    use UsesSagaFlowConnection;

    protected string $baseTable = 'action_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'status' => ActionStatus::class,
            'continue_on_failure' => 'boolean',
            'has_compensation' => 'boolean',
            'retry_signal_attempts' => 'integer',
            'retry_signal_max_attempts' => 'integer',
            'parallel_group' => 'integer',
            'arguments' => 'array',
            'result' => 'array',
            'exception' => 'array',
            'attempts' => 'integer',
            'queue_attempts_exhausted' => 'boolean',
            'started_at' => 'datetime',
            'reclaim_stale_after_seconds' => 'integer',
            'reclaim_stale_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
            'repair_attempts' => 'integer',
            'repair_available_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function whereRetrySignal(Builder $query, ?string $name = null): void
    {
        $query->where(function (Builder $query) use ($name) {
            if ($name === null) {
                $query->whereNotNull('retry_signal');

                return;
            }

            $query->where('retry_signal', '=', $name);
        });
    }

    #[Scope]
    protected function whereAwaitingRetrySignal(Builder $query, ?string $name = null): void
    {
        $query->where('status', ActionStatus::AwaitingRetry)
            ->whereRetrySignal($name);
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(config('saga-lara-flow.models.flow_run'), 'flow_run_id');
    }
}
