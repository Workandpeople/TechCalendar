<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'service_id',
    'source',
    'external_type',
    'external_name',
    'normalized_external_type',
    'normalized_external_name',
])]
class ExternalServiceAlias extends Model
{
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public static function normalizeValue(?string $value): string
    {
        $normalized = Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/u', ' ')
            ->squish();

        return (string) $normalized;
    }
}
