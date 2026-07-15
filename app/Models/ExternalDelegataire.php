<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source',
    'external_id',
    'name',
    'company_name',
    'email',
    'phone',
    'is_active',
    'payload',
    'last_synced_at',
])]
class ExternalDelegataire extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function scopeSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
