<?php

namespace App\Services\Coffrac;

use App\Models\ExternalDelegataire;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierArchiving;
use App\Services\Crm\DossierContacts;
use App\Services\Crm\LegacyBillingReconciliation;
use App\Services\Crm\LegacyClientReconciliation;
use App\Services\Crm\NativeServiceCatalog;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared projections, with distinct rehearsal and protected production-staging entry points. */
final class CoffracNativeImporter extends CoffracProjector
{
    public static function assertRehearsalTarget(): void
    {
        // Check configuration before opening any connection, including idempotent reruns.
        $connection = config('database.connections.'.config('database.default'), []);
        $sqlite = ($connection['driver'] ?? '') === 'sqlite' && ($connection['database'] ?? '') === ':memory:';
        $mysql = ($connection['driver'] ?? '') === 'mysql'
            && ($connection['database'] ?? '') === 'cofrac2_native_rehearsal'
            && ($connection['username'] ?? '') === 'cofrac2_rehearsal'
            && ($connection['host'] ?? '') === '127.0.0.1' && empty($connection['unix_socket']);
        if (! app()->environment('testing') || ! config('coffrac.offline_rehearsal')
            || ! ($sqlite || $mysql) || ! empty($connection['url'])
            || ! config('crm.v2_enabled') || config('simulation.enabled')
            || ! in_array(config('mail.default'), ['array', 'log'], true)
            || config('services.smsfactor.enabled') || config('capcee.enabled')) {
            throw new RuntimeException('Cible refusee : ce mode autorise une repetition locale isolee, jamais un import serveur.');
        }
    }

    public function rehearse(ProductionDataset $snapshot, ?TechCalendarPlanningSnapshot $planning = null): array
    {
        self::assertRehearsalTarget();

        return $this->importDataset($snapshot, $planning);
    }

    public function stageProduction(ProductionDataset $snapshot, TechCalendarPlanningSnapshot $planning): array
    {
        NativeImportTarget::assertProductionScope();

        return $this->importDataset($snapshot, $planning);
    }

    private function importDataset(ProductionDataset $snapshot, ?TechCalendarPlanningSnapshot $planning): array
    {
        $existing = DB::table('coffrac_import_runs')->first();
        if ($existing) {
            $report = json_decode($existing->report, true, 512, JSON_THROW_ON_ERROR);
            if (! hash_equals($existing->fingerprint, $snapshot->fingerprint)) {
                throw new RuntimeException('Une autre source est deja importee ; creer une nouvelle base jetable.');
            }
            if (($report['matching']['techcalendar_planning']['snapshot_sha256'] ?? null) !== $planning?->fingerprint) {
                throw new RuntimeException('Collecte planning differente : creer une nouvelle base jetable.');
            }
            if (($report['matching']['external_access']['policy_version'] ?? null) !== NativeExternalAccessReview::POLICY_VERSION) {
                throw new RuntimeException('Regles de droits differentes : creer une nouvelle base jetable.');
            }
            if (($report['matching']['seeded_admin']['source_user_id'] ?? null) !== config('admin.coffrac_source_user_id')) {
                throw new RuntimeException('Identite du super admin differente de l import existant.');
            }

            return $report;
        }
        foreach (['users', 'services', 'dossiers', 'dossier_operations', 'appointments', 'lots', 'lot_appointments',
            'external_delegataires', 'legacy_records', 'dossier_activities', 'dossier_documents', 'dossier_billings',
            'mail_templates', 'service_user', 'department_user', 'external_service_aliases', 'technician_absences',
            'coffrac_user_links', 'simulation_snapshots', 'sessions', 'jobs', 'failed_jobs', 'password_reset_tokens',
            'mobile_access_tokens', 'mobile_push_tokens', 'external_appointment_requests', 'capcee_deliveries', 'dossier_sms'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cible non vide : '.$table);
            }
        }
        $sourceUsers = $snapshot->keyed('users');
        $adminEmail = strtolower(trim((string) config('admin.mail')));
        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen((string) config('admin.password')) < 8) {
            throw new RuntimeException('Super admin de repetition non configure.');
        }
        $adminSourceId = config('admin.coffrac_source_user_id');
        if ($adminSourceId !== null) {
            $source = is_int($adminSourceId) ? ($sourceUsers[$adminSourceId] ?? null) : null;
            if (! $source || $source['type'] !== 1 || (int) $source['actif'] !== 1 || $source['is_archived']
                || strtolower(trim($source['email'])) !== $adminEmail) {
                throw new RuntimeException('Reprise du super admin reservee au compte interne actif explicitement choisi.');
            }
        }
        $emails = [];
        foreach ($sourceUsers as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190 || isset($emails[$email])
                || ($email === $adminEmail && $row['id'] !== $adminSourceId)) {
                throw new RuntimeException('Email de compte ambigu, incompatible ou reserve au seeder.');
            }
            if (! in_array($row['type'], [1, 2, 3, 4, 5, 6, 7], true)) {
                throw new RuntimeException('Profil Cofrac inconnu : correspondance explicite requise.');
            }
            $emails[$email] = true;
        }

        // Query builder imports bypass model observers, notifications and outgoing integrations.
        $this->report = ['counts' => [], 'issues' => [], 'matching' => []];
        $this->users = $this->services = $this->delegataires = $this->dossiers = $this->operations = [];
        $this->operationParents = null;

        return DB::transaction(function () use ($snapshot, $sourceUsers, $adminEmail, $adminSourceId, $planning): array {
            $this->dossiers = $snapshot->keyed('dossiers');
            foreach ($snapshot->rows('dossier_fiches') as $row) {
                $this->operations[$row['id']] = ['dossier_id' => $row['dossier_id']];
            }
            $this->archive($snapshot);
            $this->services = app(NativeServiceCatalog::class)->reconcile();
            app(LegacyClientReconciliation::class)->run(true);
            foreach (DB::table('external_delegataires')->get() as $organisation) {
                $source = $sourceUsers[(int) $organisation->external_id];
                DB::table('external_delegataires')->where('id', $organisation->id)->update([
                    'portal_categories' => '[]', 'is_active' => (int) $source['actif'] === 1 && ! $source['is_archived'],
                ]);
            }
            $this->delegataires = DB::table('external_delegataires')->where('kind', 'delegataire')->pluck('id', 'external_id')->all();
            config(['admin.mail' => $adminEmail]);
            (new AdminUserSeeder)->run();
            $this->actorId = (int) DB::table('users')->where('email', $adminEmail)->value('id');
            $this->importUsers($snapshot, $sourceUsers, $adminSourceId);
            $this->report['matching']['seeded_admin'] = ['source_user_id' => $adminSourceId, 'user_id' => $this->actorId,
                'reused_source_account' => $adminSourceId !== null, 'source_password_reset' => $adminSourceId !== null];
            $this->report['matching']['communications'] = app(NativeCommunicationTemplates::class)->import($snapshot);
            $grants = [];
            $technicians = 0;
            // dpt mixes residence/postal codes and free text, not proven intervention sectors.
            foreach ($sourceUsers as $row) {
                if ($row['type'] === 4) {
                    $technicians++;
                    if ($planning === null) {
                        $this->issue('technician_sectors_pending_techcalendar_matching', $row['id']);
                    }
                    // Explicit operator decision: all imported technicians receive every native Cofrac service.
                    foreach ($this->services as $serviceId) {
                        $grants[] = ['user_id' => $this->users[$row['id']], 'service_id' => $serviceId,
                            'created_at' => now(), 'updated_at' => now()];
                    }
                }
            }
            foreach (array_chunk($grants, 300) as $batch) {
                DB::table('service_user')->insert($batch);
            }
            $this->report['matching']['technician_services'] = ['policy' => 'all_native_coffrac_services',
                'technicians' => $technicians, 'services' => count($this->services), 'grants' => count($grants),
                'source_accounts_reactivated' => 0, 'sectors_inferred_from_source_dpt' => 0];
            if ($planning !== null) {
                $this->report['matching']['techcalendar_planning'] = app(NativeTechCalendarPlanning::class)
                    ->apply($planning, $sourceUsers, $this->users, $this->services);
            }
            $this->projectDossiers($snapshot);
            $this->projectOperations($snapshot);
            $this->report['matching']['deleted_dossiers'] = app(DossierArchiving::class)->classifyLegacyDeleted();
            $this->report['matching']['clients'] = app(LegacyClientReconciliation::class)->run(true);
            $this->projectContactsAndOwners($sourceUsers);
            $this->projectAppointments($snapshot);
            $this->projectExternalAppointments($snapshot);
            DB::table('services')->whereNull('coffrac_reference')->update(['retired_at' => now()]);
            $this->projectActivities($snapshot);
            $this->report['matching']['billing'] = app(LegacyBillingReconciliation::class)->run(true);
            $this->report['matching']['external_access'] = app(NativeExternalAccessReview::class)->rehearse($snapshot);
            $this->report['matching']['lots_and_satisfaction'] = app(NativeLotSatisfactionReview::class)->rehearse($snapshot);
            $this->verifyCoverage($snapshot);
            $this->report['matching']['users'] = $this->users;
            $this->report['matching']['services'] = $this->services;
            $this->report['source_dump_sha256'] = $snapshot->manifest['dump_sha256'];
            $this->report['native_import_ready'] = false;
            $this->report['final_cutover_ready'] = false;
            $this->report['coverage'] = [
                'native' => ['users', 'dossiers', 'dossier_fiches', 'fiche_type', 'dossier_comment', 'dossier_historique'],
                'native_with_review_queue' => ['dossier_fiche_rdv', 'rdv_extern', 'mail_templates'],
                'existing_sms_catalogue_from_archive' => ['sms_tempalte'],
                'metadata_archive_pending_physical_copy' => ['dossier_document', 'fiche_document', 'media'],
                'archive_only_pending_native_mapping' => ['clients', 'entreprise', 'lot_dossier', 'planings', 'dossier_letige'],
                'reconciled_and_archived' => ['dossier_fiche_facturation', 'status', 'user_type'],
                'unprojected_columns_preserved_in_archive' => true,
                'external_portals_enabled' => false,
            ];
            $this->report['remaining_gates'] = [
                'full_document_copy_and_content_checksums', 'external_access_permissions_review',
                'native_lot_and_satisfaction_mapping', 'communication_templates_and_sender_review',
                'techcalendar_technician_sectors_and_service_durations', 'incomplete_appointments_and_dossiers_review',
                'final_consistent_source_snapshot_and_cutover',
            ];
            if (! empty($this->report['matching']['techcalendar_planning']['absences']['pending_temporal_and_identity_review'])) {
                $this->report['remaining_gates'][] = 'techcalendar_absences_temporal_and_identity_review';
            }
            DB::table('coffrac_import_runs')->insert(['fingerprint' => $snapshot->fingerprint,
                'dump_sha256' => $snapshot->manifest['dump_sha256'], 'report' => $this->json($this->report), 'completed_at' => now()]);

            return $this->report;
        });
    }

    private function importUsers(ProductionDataset $snapshot, array $sourceUsers, ?int $adminSourceId): void
    {
        $organisations = DB::table('external_delegataires')->pluck('id', 'external_id')->all();
        $seen = [];
        foreach ($snapshot->credentials() as $credential) {
            $row = $sourceUsers[$credential['id']] ?? throw new RuntimeException('Credential sans compte metier.');
            $external = in_array($row['type'], [5, 7], true);
            $held = in_array($row['type'], [2, 3, 6], true);
            $inactive = (int) $row['actif'] !== 1 || (bool) $row['is_archived'];
            $role = $external ? 3 : ($row['type'] === 4 ? 2 : ($held ? 1 : 0));
            $values = [
                'first_name' => $row['prenom'] ?? '', 'last_name' => $row['name'],
                'email' => strtolower(trim($row['email'])), 'password' => $credential['password_hash'],
                'role' => $role, 'admin' => false, 'can_manage_billing' => false,
                'must_change_password' => false, 'remember_token' => null,
                'notification_mail_enabled' => false, 'notification_push_enabled' => false,
                'external_client_id' => $external ? ($organisations[$row['id']] ?? throw new RuntimeException('Organisation externe manquante.')) : null,
                'portal_enabled' => false, 'portal_permissions' => $external ? $this->json(array_fill_keys(array_keys(ClientAccess::PERMISSIONS), false)) : null,
                'address' => $this->bounded($row['adresse'], 255, 'user_address', $row['id']),
                'phone' => $this->bounded($row['telephone'], 200, 'user_phone', $row['id']),
                'latitude' => $this->coordinate($row['latitude'], 90), 'longitude' => $this->coordinate($row['longitude'], 180),
                'email_verified_at' => $this->timestamp($row['email_verified_at'] ?? null),
                'deleted_at' => $inactive || $held ? now() : null,
                'created_at' => $this->timestamp($row['created_at']), 'updated_at' => $this->timestamp($row['updated_at']),
            ];
            if ($row['id'] === $adminSourceId) {
                // Reuse the seeded identity; retain its new password and global privilege only here.
                unset($values['password'], $values['email_verified_at']);
                $values['admin'] = true;
                $id = $this->actorId;
                DB::table('users')->where('id', $id)->update($values);
            } else {
                $id = DB::table('users')->insertGetId($values);
            }
            DB::table('coffrac_user_links')->insert(['source_user_id' => $row['id'], 'user_id' => $id,
                'source_type' => $row['type'], 'access_state' => $held ? 'profile_review' : ($inactive ? 'source_inactive' : ($external ? 'portal_review' : 'active'))]);
            $this->users[$row['id']] = $id;
            $seen[$row['id']] = true;
            if ($held) {
                $this->issue('atypical_profile_disabled', $row['id']);
            }
            if ($external) {
                $this->issue('external_portal_pending_rights_review', $row['id']);
            }
        }
        if (count($seen) !== count($sourceUsers)) {
            throw new RuntimeException('Credential manquant pour un compte source.');
        }
    }

    private function projectContactsAndOwners(array $sourceUsers): void
    {
        $organisations = ExternalDelegataire::all()->keyBy('id');
        foreach (DB::table('dossiers')->orderBy('id')->lazyById(300) as $dossier) {
            $source = $this->dossiers[$dossier->id];
            $values = [];
            foreach (['client', 'delegataire'] as $kind) {
                if ($dossier->{$kind.'_id'}) {
                    $values[$kind.'_contact'] = $this->json(DossierContacts::details($organisations->get($dossier->{$kind.'_id'})));
                } elseif (! empty($source[$kind.'_id'])) {
                    $this->issue($kind.'_assignment_to_review', $dossier->id);
                }
            }
            $owner = $sourceUsers[$source['admin_id'] ?? 0] ?? null;
            if ($owner && $owner['type'] === 1) {
                $values['planning_user_id'] = $this->users[$owner['id']];
            }
            if (! isset($this->operationsByDossier()[$dossier->id])) {
                $this->issue('dossier_without_operation', $dossier->id);
            }
            if ($values !== []) {
                DB::table('dossiers')->where('id', $dossier->id)->update($values);
            }
        }
    }

    private ?array $operationParents = null;

    private function operationsByDossier(): array
    {
        return $this->operationParents ??= array_fill_keys(array_column($this->operations, 'dossier_id'), true);
    }

    private function verifyCoverage(ProductionDataset $snapshot): void
    {
        foreach (CoffracDataset::TABLES as $table) {
            $count = DB::table('legacy_records')->where('source_table', $table)->count();
            if ($count !== $snapshot->manifest['tables'][$table]['count']) {
                throw new RuntimeException('Couverture d archive incomplete : '.$table);
            }
            $this->report['archive_counts'][$table] = $count;
        }
        foreach (['users', 'dossiers', 'dossier_operations', 'appointments', 'external_delegataires', 'dossier_activities', 'legacy_records', 'dossier_billings', 'mail_templates', 'department_user', 'service_user'] as $table) {
            $this->report['counts'][$table] = DB::table($table)->count();
        }
        if ($this->report['counts']['users'] !== $snapshot->manifest['tables']['users']['count'] + (config('admin.coffrac_source_user_id') === null ? 1 : 0)
            || $this->report['counts']['dossiers'] !== $snapshot->manifest['tables']['dossiers']['count']
            || $this->report['counts']['dossier_operations'] !== $snapshot->manifest['tables']['dossier_fiches']['count']
            || DB::table('users')->where('admin', true)->count() !== 1
            || DB::table('users')->where('admin', true)->value('id') !== $this->actorId) {
            throw new RuntimeException('Effectif natif ou unicite super admin non conforme.');
        }
    }
}
