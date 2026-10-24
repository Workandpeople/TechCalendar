<?php

namespace App\Services\Simulation;

use App\Models\ExternalServiceAlias;
use App\Services\Coffrac\CoffracProjector;
use App\Services\Crm\LegacyBillingReconciliation;
use App\Services\Crm\LegacyClientReconciliation;
use App\Services\Crm\NativeServiceCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class SnapshotImporter extends CoffracProjector
{
    public function import(SnapshotReader $snapshot): array
    {
        app(SimulationSafety::class)->assertSeedTarget();
        app(SimulationSafety::class)->activate();
        $existing = DB::table('simulation_snapshots')->first();
        if ($existing) {
            if ($existing->fingerprint !== $snapshot->fingerprint) {
                throw new RuntimeException('Autre snapshot deja importe : utiliser simulation:reset.');
            }

            return json_decode($existing->report, true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (['users', 'services', 'dossiers', 'appointments', 'lots', 'legacy_records'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('La base cible doit etre vide : '.$table);
            }
        }
        if (strlen((string) config('admin.password')) < 8 || ! filter_var(config('admin.mail'), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Definir ADMIN_MAIL et un ADMIN_PASSWORD local de 8 caracteres minimum.');
        }

        return DB::transaction(function () use ($snapshot): array {
            $this->references($snapshot);
            $this->matchUsers($snapshot);
            $this->matchServices($snapshot);
            $this->projectDossiers($snapshot);
            $this->projectOperations($snapshot);
            $this->projectAppointments($snapshot);
            $this->projectExternalAppointments($snapshot);
            $this->archive($snapshot);
            if (config('crm.v2_enabled')) {
                $this->report['matching']['services'] = app(NativeServiceCatalog::class)->reconcile();
                $this->report['matching']['clients'] = app(LegacyClientReconciliation::class)->run(true);
            }
            $this->projectActivities($snapshot);
            $this->report['matching']['billing'] = app(LegacyBillingReconciliation::class)->run(true);
            $this->report['source_dump_sha256'] = $snapshot->manifest['dump_sha256'];
            $this->report['counts']['dossiers'] = DB::table('dossiers')->count();
            $this->report['counts']['operations'] = DB::table('dossier_operations')->count();
            $this->report['counts']['appointments'] = DB::table('appointments')->count();
            $this->report['counts']['active_appointments'] = DB::table('appointments')->whereNull('deleted_at')->count();
            $this->report['counts']['archived_source_rows'] = DB::table('legacy_records')->count();
            DB::table('simulation_snapshots')->insert([
                'fingerprint' => $snapshot->fingerprint, 'report' => $this->json($this->report), 'created_at' => now(),
            ]);

            return $this->report;
        });
    }

    private function references(SnapshotReader $snapshot): void
    {
        // Migrations may initialise a default sender from local SMTP configuration.
        DB::table('mail_senders')->delete();
        // Only these reference tables are writable; never trust table names from JSON.
        foreach (['departments', 'users', 'services', 'external_service_aliases', 'service_user', 'department_user', 'external_delegataires', 'mail_senders', 'mail_templates', 'technician_absences'] as $table) {
            $source = $snapshot->references['tables'][$table] ?? throw new RuntimeException('References manquantes : '.$table);
            $columns = Schema::getColumnListing($table);
            foreach ($source['rows'] as $row) {
                if (array_diff(array_keys($row), $columns)
                    || array_intersect(['password', 'remember_token', 'mail_password', 'mail_username', 'mail_host', 'payload'], array_keys($row))) {
                    throw new RuntimeException('Champs inattendus dans les references : '.$table);
                }
                if ($table === 'users') {
                    $row['email'] = strtolower(trim($row['email']));
                    $row = array_replace($row, ['password' => Hash::make(Str::random(64)), 'must_change_password' => false,
                        'notification_mail_enabled' => false, 'notification_push_enabled' => false]);
                }
                if ($table === 'mail_senders') {
                    $row += ['mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_username' => null, 'mail_password' => null];
                }
                if (in_array($table, ['mail_senders', 'mail_templates'], true)) {
                    $row['logo_path'] = null; // Paths alone do not prove that a logo was collected.
                }
                $key = $table === 'departments' ? 'code' : 'id';
                DB::table($table)->updateOrInsert([$key => $row[$key]], $row);
            }
        }
        $email = strtolower(trim(config('admin.mail')));
        $admin = DB::table('users')->where('email', $email)->first();
        $values = ['password' => Hash::make(config('admin.password')), 'admin' => true, 'must_change_password' => false,
            'deleted_at' => null, 'notification_mail_enabled' => false, 'notification_push_enabled' => false];
        if ($admin) {
            DB::table('users')->where('id', $admin->id)->update($values);
            $this->actorId = $admin->id;
        } else {
            $this->actorId = DB::table('users')->insertGetId($values + ['first_name' => 'Admin', 'last_name' => 'Simulation',
                'email' => $email, 'role' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->delegataires = DB::table('external_delegataires')->where('source', 'coffrac')->pluck('id', 'external_id')->all();
    }

    private function matchUsers(SnapshotReader $snapshot): void
    {
        $emails = DB::table('users')->get()->groupBy(fn ($user) => strtolower(trim($user->email)));
        $source = $snapshot->keyed('users');
        $sourceEmails = collect($source)->groupBy(fn ($user) => strtolower(trim($user['email'] ?? '')));
        foreach ($source as $row) {
            $email = strtolower(trim($row['email'] ?? ''));
            $matches = $emails->get($email, collect());
            $override = $snapshot->overrides['users'][$row['id']] ?? null;
            if ($override !== null) {
                if (! DB::table('users')->where('id', $override)->exists()) {
                    throw new RuntimeException('Correspondance utilisateur invalide : '.$row['id']);
                }
                $this->users[$row['id']] = (int) $override;
            } elseif ($email !== '' && $matches->count() === 1 && $sourceEmails->get($email)->count() === 1) {
                $this->users[$row['id']] = $matches->first()->id;
            } elseif ((int) $row['type'] === 4 && filter_var($email, FILTER_VALIDATE_EMAIL)
                && $matches->isEmpty() && $sourceEmails->get($email)->count() === 1) {
                // Historical technicians are needed for the calendar, not granted admin access.
                $this->users[$row['id']] = DB::table('users')->insertGetId([
                    'first_name' => $row['prenom'] ?? '', 'last_name' => $row['name'] ?? '', 'email' => $email,
                    'password' => Hash::make(Str::random(64)), 'role' => 2, 'admin' => false,
                    'notification_mail_enabled' => false, 'notification_push_enabled' => false,
                    'address' => $row['adresse'], 'phone' => $this->bounded($row['telephone'], 200, 'user_phone', $row['id']),
                    'latitude' => $this->coordinate($row['latitude'], 90), 'longitude' => $this->coordinate($row['longitude'], 180),
                    'deleted_at' => ! $row['actif'] || $row['is_archived'] ? now() : null,
                    'created_at' => $this->timestamp($row['created_at']), 'updated_at' => $this->timestamp($row['updated_at']),
                ]);
                $this->issue('historical_technician_created_without_login', $row['id']);
            } elseif ((int) $row['type'] === 4) {
                $this->issue('technician_unmatched', $row['id']);
            }
        }
        $this->report['matching']['users'] = $this->users;
    }

    private function matchServices(SnapshotReader $snapshot): void
    {
        $names = [];
        foreach (DB::table('services')->where('type', 'COFFRAC')->get() as $service) {
            $names[ExternalServiceAlias::normalizeValue($service->name)][] = $service->id;
        }
        foreach (DB::table('external_service_aliases')->where('source', 'coffrac')->get() as $alias) {
            if (! in_array(strtoupper(trim($alias->external_type ?? '')), ['', 'COFFRAC'], true)) {
                continue;
            }
            $names[ExternalServiceAlias::normalizeValue($alias->external_name)][] = $alias->service_id;
        }
        foreach ($snapshot->rows('fiche_type') as $row) {
            $matches = array_values(array_unique($names[ExternalServiceAlias::normalizeValue($row['name'])] ?? []));
            $override = $snapshot->overrides['services'][$row['id']] ?? null;
            if ($override !== null) {
                if (! DB::table('services')->where('id', $override)->exists()) {
                    throw new RuntimeException('Correspondance prestation invalide : '.$row['id']);
                }
                $id = (int) $override;
            } elseif (count($matches) === 1) {
                $id = $matches[0];
            } else {
                // Keep an unmatched source service distinct instead of guessing an equivalence.
                $label = 'Historique #'.$row['id'].' - '.$row['name'];
                if (mb_strlen($label) > 190) {
                    $this->issue('service_name_too_long', $row['id']);

                    continue;
                }
                $id = DB::table('services')->insertGetId(['type' => 'COFFRAC', 'name' => $label,
                    'average_duration_minutes' => 60, 'created_at' => now(), 'updated_at' => now()]);
                $this->issue('service_kept_distinct_duration_to_configure', $row['id']);
            }
            $this->services[$row['id']] = $id;
        }
        $this->report['matching']['services'] = $this->services;
    }
}
