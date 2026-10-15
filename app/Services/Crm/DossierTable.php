<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DossierTable
{
    public const COLUMNS = [
        'lot' => 'Numéro de lot', 'created_at' => 'Création', 'modified_at' => 'Modifié le',
        'appointment_at' => 'Date RDV', 'client' => 'Client', 'planning_user' => 'Suivi planning', 'address' => 'Adresse',
        'company' => 'Société', 'phone' => 'Téléphone', 'service' => 'Prestation', 'technician' => 'Technicien',
    ];

    private const COLUMN_WEIGHTS = [
        'lot' => 8, 'created_at' => 8.5, 'modified_at' => 12, 'appointment_at' => 8,
        'client' => 12, 'planning_user' => 13, 'address' => 14, 'company' => 8, 'phone' => 8, 'service' => 11, 'technician' => 8,
    ];

    public function columns(array $filters): array
    {
        $columns = self::COLUMNS;
        if (! ($filters['all_columns'] ?? false)) {
            if (($filters['status'] ?? null) !== 'visited') {
                unset($columns['planning_user']);
            }
            if (in_array($filters['status'] ?? null, ['pending', 'cancelled'], true)) {
                unset($columns['appointment_at'], $columns['technician']);
            }
            if (in_array($filters['status'] ?? null, ['invoiced', 'cancelled'], true)) {
                unset($columns['phone']);
            }
        }

        return $columns;
    }

    public function columnShares(array $filters): array
    {
        $weights = array_intersect_key(self::COLUMN_WEIGHTS, $this->columns($filters));
        $total = array_sum($weights);

        return array_map(fn ($weight) => $weight / $total, $weights);
    }

    public function apply(Builder $query, array $filters): Builder
    {
        $appointment = DB::table('appointments')->join('dossier_operations', 'dossier_operations.id', '=', 'appointments.dossier_operation_id')
            ->whereColumn('dossier_operations.dossier_id', 'dossiers.id')
            ->where(fn ($q) => $q->whereNull('appointments.deleted_at')->orWhere('appointments.external_source', 'coffrac_archive'));
        if (filled($filters['service_id'] ?? null)) {
            $appointment->where('dossier_operations.service_id', $filters['service_id']);
        }
        if (filled($filters['status'] ?? null) && $filters['status'] !== 'unknown') {
            $appointment->whereIn('dossier_operations.legacy_status', DossierStatusCatalog::STATUSES[$filters['status']]['values']);
        }
        // Prefer a current booking over historical copies, then the next booking or the most recent past visit.
        $future = 'appointments.starts_at >= ? AND appointments.status = ?';
        $bindings = [now()->format('Y-m-d H:i:s'), Appointment::STATUS_SCHEDULED];
        $appointment->orderByRaw('CASE WHEN appointments.deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw("CASE WHEN $future THEN 0 ELSE 1 END", $bindings)
            ->orderByRaw("CASE WHEN $future THEN appointments.starts_at END ASC", $bindings)
            ->orderByDesc('appointments.starts_at')->orderByDesc('appointments.id')->limit(1);

        $lot = DB::table('lot_appointments')->join('lots', 'lots.id', '=', 'lot_appointments.lot_id')
            ->join('appointments', 'appointments.id', '=', 'lot_appointments.appointment_id')
            ->join('dossier_operations', 'dossier_operations.id', '=', 'appointments.dossier_operation_id')
            ->whereColumn('dossier_operations.dossier_id', 'dossiers.id')
            ->orderByDesc('lot_appointments.id')->select('lots.name')->limit(1);
        $query->select('dossiers.*')
            ->selectSub((clone $appointment)->select('appointments.id'), 'listing_appointment_id')
            ->selectRaw("COALESCE(NULLIF(dossiers.legacy_lot_name, ''), (".$lot->toSql().')) AS listing_lot', $lot->getBindings())
            ->withMax('activities', 'created_at');

        $sort = $filters['sort'] ?? null;
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        switch ($sort) {
            case 'lot': $query->orderBy('listing_lot', $direction);
                break;
            case 'created_at': $query->orderBy('dossiers.created_at', $direction);
                break;
            case 'modified_at':
                $query->orderByRaw('CASE WHEN activities_max_created_at > dossiers.updated_at OR dossiers.updated_at IS NULL THEN activities_max_created_at ELSE dossiers.updated_at END '.$direction);
                break;
            case 'appointment_at': $query->orderBy((clone $appointment)->select('appointments.starts_at'), $direction);
                break;
            case 'client':
                $name = $this->concat("COALESCE(dossiers.first_name, '')", "' '", "COALESCE(dossiers.last_name, '')");
                $query->orderByRaw("COALESCE(NULLIF(dossiers.company_name, ''), NULLIF(TRIM($name), ''), NULLIF(dossiers.legacy_label, ''), dossiers.reference) $direction");
                break;
            case 'address':
                $query->orderBy('dossiers.address', $direction)->orderBy('dossiers.postal_code', $direction)->orderBy('dossiers.city', $direction);
                break;
            case 'company':
                $query->orderBy(DB::table('external_delegataires')->selectRaw("COALESCE(NULLIF(company_name, ''), name)")
                    ->whereColumn('id', 'dossiers.delegataire_id')->limit(1), $direction);
                break;
            case 'phone': $query->orderBy('dossiers.phone', $direction);
                break;
            case 'service':
                $services = DB::table('dossier_operations')->leftJoin('services', 'services.id', '=', 'dossier_operations.service_id')
                    ->whereColumn('dossier_operations.dossier_id', 'dossiers.id');
                $query->orderBy($services->selectRaw('MIN(COALESCE(services.name, dossier_operations.legacy_service_name))'), $direction);
                break;
            case 'technician':
                $tech = (clone $appointment)->leftJoin('users', 'users.id', '=', 'appointments.technician_id');
                $query->orderBy($tech->selectRaw($this->concat("COALESCE(users.last_name, '')", "' '", "COALESCE(users.first_name, '')")), $direction);
                break;
            case 'planning_user':
                $query->orderBy(DB::table('users')->whereColumn('users.id', 'dossiers.planning_user_id')
                    ->selectRaw($this->concat("COALESCE(users.last_name, '')", "' '", "COALESCE(users.first_name, '')")), $direction);
                break;
        }

        return $query->orderByDesc('dossiers.id');
    }

    public function modification(Dossier $dossier): array
    {
        $activity = $dossier->latestActivity;
        $date = $dossier->updated_at ?? $dossier->created_at;
        if ($activity && (! $date || $activity->created_at?->gte($date))) {
            $date = $activity->created_at;
            $label = trim(html_entity_decode(strip_tags($activity->body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (in_array($activity->kind, ['comment', 'legacy_comment'], true)) {
                $label = 'Commentaire ajouté';
            } elseif ($activity->kind === 'updated' && array_is_list($activity->changed_fields ?? [])) {
                $labels = ['address' => 'adresse', 'postal_code' => 'code postal', 'city' => 'ville', 'phone' => 'téléphone',
                    'company_name' => 'client', 'first_name' => 'prénom', 'last_name' => 'nom', 'email' => 'email',
                    'delegataire_id' => 'société', 'installer_name' => 'installateur', 'reference' => 'référence', 'site_name' => 'site'];
                $changed = collect($activity->changed_fields)->map(fn ($field) => $labels[$field] ?? 'informations du dossier')->unique()->implode(', ');
                if ($changed !== '') {
                    $label = 'Modification : '.$changed;
                }
            }

            return ['date' => $date, 'label' => Str::limit($label, 180) ?: 'Dossier modifié'];
        }

        return ['date' => $date, 'label' => $dossier->created_at?->equalTo($date) ? 'Création du dossier' : 'Informations du dossier mises à jour'];
    }

    private function concat(string ...$parts): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? '('.implode(' || ', $parts).')' : 'CONCAT('.implode(', ', $parts).')';
    }
}
