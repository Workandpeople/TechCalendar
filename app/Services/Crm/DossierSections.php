<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use Illuminate\Database\Eloquent\Builder;

final class DossierSections
{
    public const LABELS = ['corse' => 'Corse', 'domtom' => 'DOM-TOM', 'sav' => 'SAV'];

    public static function department(Builder $query, string $code): void
    {
        if (in_array($code, ['2A', '2B'], true)) {
            $query->where('postal_code', '>=', $code === '2A' ? '20000' : '20200')
                ->where('postal_code', '<', $code === '2A' ? '20200' : '21000');
        } else {
            $query->where('postal_code', 'like', $code.'%');
        }
    }

    public static function filter(Builder $query, string $section): void
    {
        match ($section) {
            'corse' => $query->where('postal_code', 'like', '20%'),
            'domtom' => $query->where(fn ($q) => $q->where('postal_code', 'like', '97%')->orWhere('postal_code', 'like', '98%')),
            'sav' => $query->whereHas('operations', fn ($operations) => $operations->where(fn ($q) => $q
                ->where('legacy_service_name', 'like', 'SAV%')->orWhereHas('service', fn ($service) => $service
                ->where('name', 'like', 'SAV%')->orWhere('type', 'SAV')))),
            default => null,
        };
    }

    public static function badges(Dossier $dossier): array
    {
        $badges = [];
        if (str_starts_with((string) $dossier->postal_code, '20')) {
            $badges[] = 'Corse';
        } elseif (preg_match('/^9[78]/', (string) $dossier->postal_code)) {
            $badges[] = 'DOM-TOM';
        }
        if ($dossier->operations->contains(fn ($op) => preg_match('/^SAV/i', $op->service?->name ?: $op->legacy_service_name ?: '')
            || $op->service?->type === 'SAV')) {
            $badges[] = 'SAV';
        }

        return $badges;
    }
}
