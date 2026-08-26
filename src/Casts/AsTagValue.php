<?php

namespace DiscoveryUkraine\SagaLaraFlow\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalises a tag value on the way in, so an int and its string twin land on the
 * same row instead of racing for the key — for any writer, including one reaching
 * the model directly.
 */
final class AsTagValue implements CastsInboundAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
