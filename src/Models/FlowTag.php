<?php

namespace DiscoveryUkraine\SagaLaraFlow\Models;

use DiscoveryUkraine\SagaLaraFlow\Casts\AsTagValue;
use DiscoveryUkraine\SagaLaraFlow\Models\Concerns\UsesSagaFlowConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $flow_run_id
 * @property string $key
 * @property ?string $value
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read FlowRun $flowRun
 */
class FlowTag extends Model
{
    use UsesSagaFlowConnection;

    protected string $baseTable = 'flow_tags';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => AsTagValue::class,
        ];
    }

    /**
     * Shape a key => value map for upsert(). Upsert bypasses Eloquent casts, so
     * this applies the value cast explicitly.
     *
     * @param  array<string, string|int|null>  $tags
     * @return list<array{key: string, value: ?string}>
     */
    public static function attributesForUpsert(array $tags): array
    {
        $model = new static;
        $rows = [];

        foreach ($tags as $key => $value) {
            $model->setAttribute('value', $value);

            $rows[] = [
                'key' => (string) $key,
                'value' => $model->getAttributes()['value'] ?? null,
            ];
        }

        return $rows;
    }

    public function flowRun(): BelongsTo
    {
        return $this->belongsTo(config('saga-lara-flow.models.flow_run'), 'flow_run_id');
    }
}
