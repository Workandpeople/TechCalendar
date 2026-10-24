<?php

namespace App\Services\Coffrac;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NativeTechCalendarPlanning
{
    public function apply(TechCalendarPlanningSnapshot $planning, array $coffracUsers, array $userIds, array $serviceIds): array
    {
        NativeImportTarget::assertAllowed();
        if (DB::table('department_user')->exists()) {
            throw new RuntimeException('Affectations planning natives non vides.');
        }
        $byEmail = $sectors = [];
        foreach ($planning->technicians() as $user) {
            $byEmail[strtolower(trim($user['email']))][] = $user;
        }
        foreach ($planning->rows('department_user') as $row) {
            $sectors[(int) $row['user_id']][] = $row['department_code'];
        }
        $departments = DB::table('departments')->pluck('code')->all();
        $nativeUsers = DB::table('users')->whereIn('id', array_values($userIds))->get(['id', 'email', 'role'])->keyBy('id');
        $report = ['snapshot_sha256' => $planning->fingerprint, 'exported_at' => $planning->data['exported_at'],
            'source_technicians' => count($planning->technicians()), 'matched_users' => [], 'department_assignments' => 0,
            'working_hours_copied' => 0, 'base_locations_copied' => 0, 'issues' => [], 'techcalendar_users_not_matched' => [],
            'durations' => ['matched_services' => [], 'evidence' => [], 'native_services_pending_review' => []],
            'source_accounts_reactivated' => 0, 'native_accounts_created' => 0, 'service_grants_changed' => 0];
        $matched = [];
        foreach ($coffracUsers as $source) {
            if ($source['type'] !== 4) {
                continue;
            }
            $candidates = $byEmail[strtolower(trim($source['email']))] ?? [];
            $reason = match (true) {
                count($candidates) === 0 => 'no_exact_email_match',
                count($candidates) > 1 => 'ambiguous_email',
                filled($candidates[0]['deleted_at']) => 'techcalendar_account_inactive',
                default => null,
            };
            if ($reason !== null) {
                $report['issues'][$reason][] = $source['id'];

                continue;
            }
            $origin = $candidates[0];
            $id = $userIds[$source['id']] ?? throw new RuntimeException('Correspondance utilisateur native manquante.');
            $target = $nativeUsers->get($id);
            if (! $target || (int) $target->role !== 2 || $target->email !== strtolower(trim($source['email']))) {
                throw new RuntimeException('Identite native planning incompatible : projection annulee.');
            }
            $codes = $sectors[(int) $origin['id']] ?? [];
            $primary = filled($origin['department_code']) ? $origin['department_code'] : null;
            if (($primary !== null && ! in_array($primary, $departments, true)) || array_diff($codes, $departments)) {
                $report['issues']['unknown_department'][] = $source['id'];

                continue;
            }
            // Keep the original primary department separately; never expand a nonempty intervention selection.
            $values = ['department_code' => $primary];
            $hours = $this->workingHours($origin);
            if ($hours === null) {
                $report['issues']['working_hours_require_review'][] = $source['id'];
            } else {
                $values += $hours;
                $report['working_hours_copied']++;
            }
            if ($planning->hasFullSettings()) {
                $base = $this->baseLocation($origin);
                if ($base === null) {
                    $report['issues']['base_location_requires_review'][] = $source['id'];
                } else {
                    $values += $base;
                    $report['base_locations_copied']++;
                }
            }
            if ($codes === [] && $primary === null) {
                $report['issues']['no_configured_department'][] = $source['id'];
            }
            DB::table('users')->where('id', $id)->where('role', 2)->update($values);
            foreach (array_chunk($codes, 200) as $chunk) {
                DB::table('department_user')->insert(array_map(fn ($code) => ['user_id' => $id, 'department_code' => $code,
                    'created_at' => now(), 'updated_at' => now()], $chunk));
            }
            $report['department_assignments'] += count($codes);
            $report['matched_users'][$source['id']] = (int) $origin['id'];
            $matched[(int) $origin['id']] = true;
        }
        foreach ($planning->technicians() as $user) {
            if (! isset($matched[(int) $user['id']])) {
                $report['techcalendar_users_not_matched'][] = (int) $user['id'];
            }
        }
        $byService = $byAlias = $services = [];
        foreach ($planning->rows('services') as $service) {
            $services[(int) $service['id']] = $service;
            if (is_string($service['type']) && strtoupper(trim($service['type'])) === 'COFFRAC' && is_string($service['name'])) {
                $byService[trim($service['name'])][(int) $service['id']] = $service;
            }
        }
        foreach ($planning->hasFullSettings() ? $planning->rows('external_service_aliases') : [] as $alias) {
            $service = $services[(int) $alias['service_id']];
            if ($alias['source'] === 'coffrac' && $alias['external_type'] === 'COFFRAC' && is_string($alias['external_name'])
                && $service['type'] === 'COFFRAC') {
                $byAlias[trim($alias['external_name'])][(int) $service['id']][] = (int) $alias['id'];
            }
        }
        foreach (DB::table('services')->whereIn('id', array_values($serviceIds))->get() as $service) {
            $candidates = $byService[$service->name] ?? [];
            foreach (array_keys($byAlias[$service->name] ?? []) as $originId) {
                $candidates[$originId] = $services[$originId];
            }
            $candidate = count($candidates) === 1 ? reset($candidates) : null;
            $duration = $candidate['average_duration_minutes'] ?? null;
            if ((is_int($duration) || is_string($duration)) && preg_match('/^[1-9][0-9]{0,3}$/D', (string) $duration) && (int) $duration <= 1440) {
                DB::table('services')->where('id', $service->id)->update(['average_duration_minutes' => (int) $duration]);
                $report['durations']['matched_services'][(int) $service->coffrac_reference] = (int) $candidate['id'];
                $report['durations']['evidence'][(int) $service->coffrac_reference] = [
                    'techcalendar_service_id' => (int) $candidate['id'],
                    'exact_label' => isset($byService[$service->name][(int) $candidate['id']]),
                    'source_alias_ids' => $byAlias[$service->name][(int) $candidate['id']] ?? [],
                ];
            } else {
                $report['durations']['native_services_pending_review'][] = (int) $service->coffrac_reference;
            }
        }
        $absences = $planning->hasFullSettings() ? $planning->rows('technician_absences') : [];
        $report['absences'] = ['source_count' => count($absences), 'created' => 0,
            // This export has no source timezone. Nonempty absences need explicit temporal/identity review.
            'pending_temporal_and_identity_review' => array_map(fn ($row) => (int) $row['id'], $absences)];

        return $report;
    }

    private function baseLocation(array $user): ?array
    {
        $latitude = $user['latitude'];
        $longitude = $user['longitude'];
        $address = $user['address'];
        if (! is_numeric($latitude) || ! is_numeric($longitude) || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)
            || abs((float) $latitude) > 90 || abs((float) $longitude) > 180 || ((float) $latitude === 0.0 && (float) $longitude === 0.0)
            || ! is_string($address) || trim($address) === '' || mb_strlen($address) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $address)) {
            return null;
        }

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude, 'address' => trim($address)];
    }

    private function workingHours(array $user): ?array
    {
        $start = $this->time($user['day_start_time']);
        $end = $this->time($user['day_end_time']);
        $break = $user['break_duration_minutes'];
        if ($start === null || $end === null || $start >= $end
            || ! (is_int($break) || is_string($break)) || ! preg_match('/^[0-9]{1,4}$/D', (string) $break)) {
            return null;
        }
        $minutes = static fn ($time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        if ((int) $break >= $minutes($end) - $minutes($start)) {
            return null;
        }

        return ['day_start_time' => $start, 'day_end_time' => $end, 'break_duration_minutes' => (int) $break];
    }

    private function time(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $value)) {
            return null;
        }

        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
