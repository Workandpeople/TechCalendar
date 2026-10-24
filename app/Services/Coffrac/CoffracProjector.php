<?php

namespace App\Services\Coffrac;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

abstract class CoffracProjector
{
    protected array $report = ['counts' => [], 'issues' => [], 'matching' => []];

    protected array $users = [];

    protected array $services = [];

    protected array $delegataires = [];

    protected array $dossiers = [];

    protected array $operations = [];

    protected int $actorId;

    protected function projectDossiers(CoffracDataset $snapshot): void
    {
        $installers = $snapshot->keyed('entreprise');
        $batch = [];
        foreach ($snapshot->rows('dossiers') as $row) {
            $this->dossiers[$row['id']] = $row;
            $installer = $installers[$row['entreprise_id']] ?? [];
            $siren = preg_replace('/\D/', '', $installer['siren'] ?? '');
            $batch[] = [
                'id' => $row['id'], 'reference' => 'CFR-'.$row['id'], 'source' => 'coffrac', 'source_reference' => (string) $row['id'],
                'legacy_reference' => $row['dossier_unique'], 'legacy_label' => $row['client_name'], 'legacy_lot_name' => $row['numero_lot'],
                'delegataire_id' => $this->delegataires[$row['delegataire_id']] ?? null,
                'created_by' => $this->users[$row['author_id']] ?? null,
                'email' => $this->bounded($row['client_mail'], 190, 'dossier_email', $row['id']),
                'phone' => $row['telephone'] ?: $row['mobile'], 'address' => $row['adress'] ?? '', 'city' => $row['ville'] ?? '',
                'postal_code' => $this->postalCode($row['zip'], $row['id']),
                'beneficiary_address' => $row['adresse_demandeur'],
                'beneficiary_postal_code' => $this->postalCode($row['code_postale_demandeur'], $row['id']),
                'beneficiary_city' => $this->bounded($row['ville_demandeur'], 190, 'beneficiary_city', $row['id']),
                'installer_name' => $this->bounded($installer['raison_social'] ?? null, 190, 'installer_name', $row['id']),
                'installer_siren' => strlen($siren) === 9 ? $siren : null,
                'created_at' => $this->timestamp($row['created_at']), 'updated_at' => $this->timestamp($row['updated_at']),
            ];
            if (count($batch) >= 200) {
                DB::table('dossiers')->insert($batch);
                $batch = [];
            }
            if (! $row['adress'] || ! $row['ville']) {
                $this->issue('dossier_address_incomplete', $row['id']);
            }
            if ($row['delegataire_id'] && ! isset($this->delegataires[$row['delegataire_id']])) {
                $this->issue('delegataire_unmatched', $row['id']);
            }
        }
        if ($batch) {
            DB::table('dossiers')->insert($batch);
        }
    }

    protected function projectOperations(CoffracDataset $snapshot): void
    {
        $types = $snapshot->keyed('fiche_type');
        $statuses = $snapshot->keyed('status');
        $batch = [];
        foreach ($snapshot->rows('dossier_fiches') as $row) {
            if (! isset($this->dossiers[$row['dossier_id']])) {
                $this->issue('operation_parent_missing', $row['id']);

                continue;
            }
            $this->operations[$row['id']] = array_intersect_key($row, array_flip(['id', 'dossier_id', 'fiche_id', 'status_id', 'date_status_change']));
            $batch[] = ['id' => $row['id'], 'dossier_id' => $row['dossier_id'], 'service_id' => $this->services[$row['fiche_id']] ?? null,
                'source' => 'coffrac', 'source_reference' => (string) $row['id'],
                'legacy_service_name' => $types[$row['fiche_id']]['name'] ?? null, 'legacy_status' => $statuses[$row['status_id']]['name'] ?? null,
                'created_at' => $this->timestamp($row['created_at']), 'updated_at' => $this->timestamp($row['updated_at'])];
            if (count($batch) >= 300) {
                DB::table('dossier_operations')->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('dossier_operations')->insert($batch);
        }
    }

    protected function projectAppointments(CoffracDataset $snapshot): void
    {
        $latest = [];
        foreach ($snapshot->rows('dossier_fiche_rdv') as $row) {
            $latest[$row['dossier_fiche']] = $row['id'];
        }
        $batch = [];
        foreach ($snapshot->rows('dossier_fiche_rdv') as $row) {
            $operation = $this->operations[$row['dossier_fiche']] ?? null;
            $dossier = $this->dossiers[$row['dossier_id']] ?? null;
            $technician = $this->users[$dossier['technicien_id'] ?? 0] ?? null;
            $service = $this->services[$operation['fiche_id'] ?? 0] ?? null;
            $startsAt = $this->appointmentDate($row['date_rdv'], $row['heure']);
            $duration = (int) $row['rdv_duree'] * 60;
            if (! $dossier || ! $operation || $operation['dossier_id'] !== $row['dossier_id']
                || ! $technician || ! $service || ! $startsAt || $duration <= 0 || $duration > 1440) {
                $this->issue('appointment_not_projectable', $row['id']);

                continue;
            }
            $latitude = $this->coordinate($dossier['latitude'], 90);
            $longitude = $this->coordinate($dossier['longitude'], 180);
            if ($latitude === null || $longitude === null) {
                $latitude = $longitude = null;
                $this->issue('appointment_without_gps', $row['id']);
            }
            // Current Coffrac relation chooses the highest RDV id, not the latest date.
            $active = $latest[$row['dossier_fiche']] === $row['id']
                && in_array((int) $operation['status_id'], [2, 10], true) && (int) $dossier['status_id'] !== -1;
            $label = trim($dossier['client_name'] ?? '');
            $address = trim(implode(', ', array_filter([$dossier['adress'], trim(($dossier['zip'] ?? '').' '.($dossier['ville'] ?? ''))])));
            if (mb_strlen($label) > 240 || mb_strlen($address) > 255) {
                $this->issue('appointment_fields_too_long', $row['id']);

                continue;
            }
            $batch[] = [
                'service_id' => $service, 'technician_id' => $technician, 'created_by' => $this->users[$dossier['author_id']] ?? $this->actorId,
                'dossier_operation_id' => $operation['id'], 'customer_first_name' => mb_substr($label, 0, 120),
                'customer_last_name' => mb_substr($label, 120), 'customer_phone' => $dossier['telephone'] ?: ($dossier['mobile'] ?? ''),
                'address' => $address, 'latitude' => $latitude, 'longitude' => $longitude,
                'starts_at' => $startsAt->format('Y-m-d H:i:s'), 'ends_at' => $startsAt->addMinutes($duration)->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration, 'status' => (int) $operation['status_id'] === 10 ? 'problem' : 'scheduled',
                'external_source' => 'coffrac_archive', 'external_reference' => (string) $row['id'],
                'external_payload' => $this->json(['source_dossier_id' => $dossier['id'], 'source_operation_id' => $operation['id'],
                    'source_status_id' => $operation['status_id'], 'technician_inferred_from_current_dossier' => true]),
                'deleted_at' => $active ? null : now(),
                'created_at' => $this->timestamp($row['created_at']), 'updated_at' => $this->timestamp($row['updated_at']),
            ];
            if (count($batch) >= 200) {
                DB::table('appointments')->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('appointments')->insert($batch);
        }
    }

    protected function projectExternalAppointments(CoffracDataset $snapshot): void
    {
        $service = null;
        $batch = [];
        foreach ($snapshot->rows('rdv_extern') as $row) {
            $technician = $this->users[$row['technicien_id']] ?? null;
            $startsAt = $this->appointmentDate($row['date_rdv'], $row['heure']);
            $duration = (int) $row['rdv_duree'] * 60;
            $label = trim($row['client_name'] ?? '');
            if (! $technician || ! $startsAt || $duration <= 0 || $duration > 1440
                || mb_strlen($label) > 240 || mb_strlen($row['adress'] ?? '') > 255) {
                $this->issue('external_appointment_not_projectable', $row['id']);

                continue;
            }
            $service ??= DB::table('services')->insertGetId(['type' => 'COFFRAC', 'name' => 'Occupation externe Coffrac (historique)',
                'average_duration_minutes' => 60, 'created_at' => now(), 'updated_at' => now()]);
            $latitude = $this->coordinate($row['latitude'], 90);
            $longitude = $this->coordinate($row['longitude'], 180);
            if ($latitude === null || $longitude === null) {
                $latitude = $longitude = null;
            }
            $batch[] = ['service_id' => $service, 'technician_id' => $technician, 'created_by' => $this->actorId,
                'customer_first_name' => mb_substr($label, 0, 120), 'customer_last_name' => mb_substr($label, 120), 'customer_phone' => '',
                'address' => $row['adress'] ?? '', 'latitude' => $latitude, 'longitude' => $longitude,
                'starts_at' => $startsAt->format('Y-m-d H:i:s'), 'ends_at' => $startsAt->addMinutes($duration)->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration, 'status' => 'scheduled', 'external_source' => 'coffrac_external_archive',
                'external_reference' => (string) $row['id'], 'external_payload' => $this->json(['source_type' => $row['type']]),
                'comment' => $row['comment'], 'created_at' => $this->timestamp($row['created_at']), 'updated_at' => $this->timestamp($row['updated_at'])];
            if (count($batch) >= 200) {
                DB::table('appointments')->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('appointments')->insert($batch);
        }
    }

    protected function archive(CoffracDataset $snapshot): void
    {
        foreach (CoffracDataset::TABLES as $table) {
            $batch = [];
            $bytes = 0;
            foreach ($snapshot->rows($table) as $row) {
                $payload = $this->json($row);
                $dossier = $table === 'dossiers' ? $row['id'] : ($row['dossier_id'] ?? null);
                if ($dossier === null && isset($row['dossier_fiche_id'])) {
                    $dossier = $this->operations[$row['dossier_fiche_id']]['dossier_id'] ?? null;
                }
                $batch[] = ['source_table' => $table, 'source_id' => $row['id'],
                    'dossier_id' => isset($this->dossiers[$dossier]) ? $dossier : null, 'payload' => $payload];
                $bytes += strlen($payload);
                if (count($batch) >= 200 || $bytes > 2 * 1024 * 1024) {
                    DB::table('legacy_records')->insert($batch);
                    $batch = [];
                    $bytes = 0;
                }
            }
            if ($batch) {
                DB::table('legacy_records')->insert($batch);
            }
        }
    }

    protected function projectActivities(CoffracDataset $snapshot): void
    {
        foreach (['dossier_comment' => 'legacy_comment', 'dossier_historique' => 'legacy_history'] as $table => $kind) {
            $batch = [];
            foreach ($snapshot->rows($table) as $row) {
                if (! isset($this->dossiers[$row['dossier_id']])) {
                    $this->issue('activity_parent_missing', $row['id']);

                    continue;
                }
                $batch[] = ['dossier_id' => $row['dossier_id'], 'author_id' => $this->users[$row['author_id']] ?? null,
                    'kind' => $kind, 'body' => $row['text'] ?? $row['commentaire'] ?? 'Changement de statut Coffrac #'.($row['status_id'] ?? ''),
                    'changed_fields' => $this->json(['source_table' => $table, 'source_id' => $row['id'], 'source_author_id' => $row['author_id'],
                        'is_private' => $row['is_private'] ?? null, 'source_status_id' => $row['status_id'] ?? null]),
                    'created_at' => $this->timestamp($row['created_at']), 'updated_at' => $this->timestamp($row['updated_at'])];
                if (count($batch) >= 200) {
                    DB::table('dossier_activities')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                DB::table('dossier_activities')->insert($batch);
            }
        }
    }

    protected function issue(string $type, int $id): void
    {
        $this->report['issues'][$type]['count'] = ($this->report['issues'][$type]['count'] ?? 0) + 1;
        $this->report['issues'][$type]['source_ids'][] = $id;
    }

    protected function bounded(?string $value, int $length, string $field, int $id): ?string
    {
        if ($value !== null && mb_strlen($value) > $length) {
            $this->issue($field.'_kept_in_source', $id);

            return null;
        }

        return $value;
    }

    protected function postalCode(?string $value, int $id): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4,5}$/', $value)) {
            return str_pad($value, 5, '0', STR_PAD_LEFT);
        }
        $this->issue('postal_code_to_review', $id);

        return null;
    }

    protected function coordinate(mixed $value, int $limit): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) && abs((float) $value) <= $limit ? (float) $value : null;
    }

    protected function appointmentDate(?string $date, ?string $time): ?CarbonImmutable
    {
        if (! $date || ! $time || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/', $time)) {
            return null;
        }
        // MySQL JSON exports TIME values with microseconds, even for TIME(0).
        $time = substr($time, 0, 8);
        try {
            $result = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $date.' '.$time, 'Europe/Paris');

            return $result && $result->format('Y-m-d H:i:s') === $date.' '.$time ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function timestamp(?string $value): ?string
    {
        if (! $value || str_starts_with($value, '0000-')) {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->setTimezone(config('app.timezone', 'Europe/Paris'))->format('Y-m-d H:i:s');
    }

    protected function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
