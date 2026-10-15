<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source',
    'kind',
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
    public const KINDS = ['delegataire' => 'Délégataire', 'client' => 'Client'];

    public function accounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class, 'external_client_id');
    }

    public function scopeAssignable(Builder $query): Builder
    {
        return $query->when(! config('crm.v2_enabled'), fn ($query) => $query->where('source', 'coffrac'));
    }

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
