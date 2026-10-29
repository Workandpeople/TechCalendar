<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class DossierLotReference
{
    public static function value(Dossier $dossier): ?string
    {
        return self::select(Dossier::query()->whereKey($dossier->id)->select('dossiers.id'))->value('listing_lot');
    }

    public static function select(Builder $query): Builder
    {
        $lot = self::linked()->orderByDesc('lot_appointments.id')->select('lots.name')->limit(1);

        return $query->selectRaw("COALESCE(NULLIF(TRIM(dossiers.legacy_lot_name), ''), (".$lot->toSql().')) AS listing_lot', $lot->getBindings());
    }

    public static function filter(Builder $query, string $term): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term)).'%';
        $query->where(fn ($matching) => $matching
            ->whereRaw("dossiers.legacy_lot_name LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereExists(self::linked()->selectRaw('1')->whereRaw("lots.name LIKE ? ESCAPE '!'", [$pattern])));
    }

    private static function linked(): QueryBuilder
    {
        return DB::table('lot_appointments')->join('lots', 'lots.id', '=', 'lot_appointments.lot_id')
            ->join('appointments', 'appointments.id', '=', 'lot_appointments.appointment_id')
            ->join('dossier_operations', 'dossier_operations.id', '=', 'appointments.dossier_operation_id')
            ->whereColumn('dossier_operations.dossier_id', 'dossiers.id');
    }
}
