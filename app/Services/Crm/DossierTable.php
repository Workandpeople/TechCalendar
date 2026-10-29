<?php

namespace App\Services\Crm;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Services\ImportedAddressCleaner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DossierTable
{
    private ?array $legacyStatuses = null;

    public const COLUMNS = [
        'lot' => 'Numéro de lot', 'created_at' => 'Création', 'modified_at' => 'Modifié le',
        'appointment_at' => 'Date RDV', 'client' => 'Client', 'planning_user' => 'Suivi planning', 'address' => 'Adresse',
        'company' => 'Société', 'phone' => 'Téléphone', 'service' => 'Prestation', 'technician' => 'Technicien',
    ];

    private const COLUMN_WEIGHTS = [
        'lot' => 9, 'created_at' => 8.5, 'modified_at' => 10, 'appointment_at' => 8,
        'client' => 14, 'planning_user' => 13, 'address' => 23, 'company' => 13, 'phone' => 12, 'service' => 13, 'technician' => 8,
    ];

    public function __construct(private readonly ImportedAddressCleaner $addressCleaner) {}

    public function companies(Dossier $dossier): array
    {
        $names = [];
        foreach (['client', 'delegataire'] as $role) {
            $contact = $dossier->$role;
            $snapshot = $dossier->{$role.'_contact'} ?? [];
            $candidates = [$contact?->company_name, $contact?->name, $snapshot['company_name'] ?? null, $snapshot['name'] ?? null];
            $names[$role] = collect($candidates)->filter(fn ($name) => is_string($name) && trim($name) !== '')
                ->map(fn ($name) => trim($name))->first() ?? '';
        }

        return $names;
    }

    public function address(Dossier $dossier): array
    {
        $rawAddress = trim(preg_replace('/\s+/u', ' ', $dossier->address ?? ''));
        $street = $this->addressCleaner->clean($rawAddress) ?: $rawAddress;
        $locality = trim(preg_replace('/\s+/u', ' ', $dossier->postal_code.' '.$dossier->city));

        return ['street' => $street, 'locality' => $locality,
            'full' => implode(', ', array_filter([$street, $locality], fn ($value) => $value !== '')),
            'original' => DossierLocation::address($dossier)];
    }

    public function phoneNumbers(Dossier $dossier): array
    {
        $numbers = preg_split('~[\r\n;,/|]+~u', $dossier->phone ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map(function (string $number): array {
            $label = trim(preg_replace('/\s+/u', ' ', $number));
            $compact = preg_replace('/[\s.()\-]+/u', '', $label);
            if (preg_match('/^[1-9][0-9]{8}$/D', $compact)) {
                $compact = '0'.$compact;
            } elseif (preg_match('/^(?:\+33|0033)0?([1-9][0-9]{8})$/D', $compact, $match)) {
                $compact = '0'.$match[1];
            }
            if (preg_match('/^0[1-9][0-9]{8}$/D', $compact)) {
                return ['label' => implode(' ', str_split($compact, 2)), 'href' => 'tel:+33'.substr($compact, 1)];
            }
            // Keep foreign numbers and annotations intact; never concatenate two contacts.
            $international = str_starts_with($compact, '00') ? '+'.substr($compact, 2) : $compact;

            return ['label' => $label, 'href' => preg_match('/^\+[1-9][0-9]{7,14}$/D', $international) ? 'tel:'.$international : null];
        }, $numbers), fn (array $number) => $number['label'] !== ''));
    }

    public function columns(array $filters): array
    {
        $columns = self::COLUMNS;
        if (! ($filters['all_columns'] ?? false)) {
            if (($filters['status'] ?? null) !== 'visited') {
                unset($columns['planning_user']);
            }
            if (in_array($filters['status'] ?? null, ['pending', 'cancelled'], true)) {
                unset($columns['technician']);
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
            ->where(fn ($q) => $q->whereNull('appointments.deleted_at')->orWhere('appointments.external_source', 'coffrac_archive')
                ->orWhere('appointments.status', Appointment::STATUS_CANCELLED));
        $serviceIds = DossierServiceFilter::ids($filters);
        if ($serviceIds !== []) {
            $appointment->whereIn('dossier_operations.service_id', $serviceIds);
        }
        if (($filters['date_field'] ?? null) === 'appointment') {
            if (filled($filters['date_from'] ?? null)) {
                $appointment->where('appointments.starts_at', '>=', $filters['date_from'].' 00:00:00');
            }
            if (filled($filters['date_to'] ?? null)) {
                $appointment->where('appointments.starts_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
            }
        }
        if (filled($filters['status'] ?? null) && $filters['status'] !== 'unknown') {
            $appointment->where(function ($query) use ($filters): void {
                $query->whereIn('dossier_operations.legacy_status', DossierStatusCatalog::STATUSES[$filters['status']]['values']);
                if ($filters['status'] === 'invoiced') {
                    $query->orWhereExists(fn ($billing) => $billing->selectRaw('1')->from('dossier_billings')
                        ->whereColumn('dossier_billings.dossier_operation_id', 'dossier_operations.id'));
                }
            });
        }
        // Prefer a current booking over historical copies, then the next booking or the most recent past visit.
        $future = 'appointments.starts_at >= ? AND appointments.status = ?';
        $bindings = [now()->format('Y-m-d H:i:s'), Appointment::STATUS_SCHEDULED];
        $appointment->orderByRaw('CASE WHEN appointments.deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw("CASE WHEN $future THEN 0 ELSE 1 END", $bindings)
            ->orderByRaw("CASE WHEN $future THEN appointments.starts_at END ASC", $bindings)
            ->orderByDesc('appointments.starts_at')->orderByDesc('appointments.id')->limit(1);

        $query->select('dossiers.*')
            ->selectSub((clone $appointment)->select('appointments.id'), 'listing_appointment_id');
        DossierLotReference::select($query);

        $sort = $filters['sort'] ?? null;
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        switch ($sort) {
            case 'lot': $query->orderBy('listing_lot', $direction);
                break;
            case 'created_at': $query->orderBy('dossiers.created_at', $direction);
                break;
            case 'modified_at':
                $query->withMax('statusChanges', 'created_at')->orderBy('status_changes_max_created_at', $direction);
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
                $query->orderByRaw($this->companySortExpression().' '.$direction);
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
        $activity = $dossier->latestStatusChange;
        if (! $activity) {
            return ['date' => null, 'label' => 'Aucun changement d’état enregistré'];
        }

        $fields = $activity->changed_fields ?? [];
        if (in_array($activity->kind, ['dossier_deleted', 'dossier_restored'], true)) {
            return ['date' => $activity->created_at, 'label' => $activity->kind === 'dossier_deleted' ? 'Dossier supprimé' : 'Dossier restauré'];
        }
        $status = $fields['status'] ?? $fields['after']['legacy_status'] ?? null;
        if ($activity->kind === 'legacy_history') {
            // One small reference lookup per table/export, never one query per dossier.
            $this->legacyStatuses ??= DB::table('legacy_records')->where('source_table', 'status')->pluck('payload', 'source_id')
                ->map(fn ($payload) => json_decode($payload, true)['name'] ?? null)->all();
            $status = $this->legacyStatuses[$fields['source_status_id'] ?? ''] ?? null;
        } elseif ($activity->kind === 'appointment_linked' && $status === null) {
            $status = 'RDV ATTENTE VISITE';
        }

        return ['date' => $activity->created_at, 'label' => $status
            ? DossierStatusCatalog::describe($status)['label'] : 'Changement d’état Cofrac'];
    }

    private function concat(string ...$parts): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? '('.implode(' || ', $parts).')' : 'CONCAT('.implode(', ', $parts).')';
    }

    private function companySortExpression(): string
    {
        $names = [];
        foreach (['client', 'delegataire'] as $role) {
            $organisation = DB::table('external_delegataires')->selectRaw("COALESCE(NULLIF(TRIM(company_name), ''), NULLIF(TRIM(name), ''))")
                ->whereColumn('id', 'dossiers.'.$role.'_id')->limit(1);
            $names[] = '('.$organisation->toSql().')';
            foreach (['company_name', 'name'] as $field) {
                $value = "JSON_EXTRACT(dossiers.{$role}_contact, '$.{$field}')";
                if (DB::connection()->getDriverName() !== 'sqlite') {
                    $value = 'JSON_UNQUOTE('.$value.')';
                }
                $names[] = "NULLIF(NULLIF(TRIM({$value}), ''), 'null')";
            }
        }

        return 'COALESCE('.implode(', ', $names).')';
    }
}
