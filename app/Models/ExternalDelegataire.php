<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    'portal_categories',
    'capcee_enabled',
    'report_download_naming',
])]
class ExternalDelegataire extends Model
{
    public const KINDS = ['delegataire' => 'Délégataire', 'client' => 'Client'];

    public const REPORT_DOWNLOAD_STANDARD = 'standard';

    public const REPORT_DOWNLOAD_OUTCOME_PREFIX = 'outcome_prefix';

    public static function reportDownloadNamingOptions(): array
    {
        return [
            self::REPORT_DOWNLOAD_STANDARD => 'Nommage habituel',
            self::REPORT_DOWNLOAD_OUTCOME_PREFIX => 'Avis (S / NS / NV) - référence - bénéficiaire',
        ];
    }

    public function accounts(): HasMany
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
            'capcee_enabled' => 'boolean',
            'payload' => 'array',
            'portal_categories' => 'array',
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
