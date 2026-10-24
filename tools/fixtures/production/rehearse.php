<?php

declare(strict_types=1);

use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\DocumentReconciliationPlan;
use App\Services\Coffrac\NativeDocumentAudit;
use App\Services\Coffrac\ProductionDataset;
use App\Services\Coffrac\TechCalendarPlanningSnapshot;
use App\Services\Sms\DossierSmsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    if (! in_array($argc, [3, 4, 6], true) || getenv('COFFRAC_OFFLINE_REHEARSAL') !== 'true' || getenv('APP_ENV') !== 'testing'
        || gethostname() === 'ns365601') {
        throw new RuntimeException('Repetition locale uniquement.');
    }
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    // Never load the developer's .env or cached production configuration.
    $app->loadEnvironmentFrom('.env.offline-rehearsal-not-used');
    if (is_file($app->environmentFilePath()) || $app->configurationIsCached()) {
        throw new RuntimeException('Environnement ou cache applicatif present.');
    }
    $app->make(Kernel::class)->bootstrap();
    CoffracNativeImporter::assertRehearsalTarget();
    $planning = $argc === 6 ? new TechCalendarPlanningSnapshot($argv[4], $argv[5]) : null;
    Http::preventStrayRequests();
    if (DB::select('SHOW TABLES') !== []) {
        throw new RuntimeException('La repetition exige une nouvelle base jetable.');
    }
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Migration native incomplete.');
    }
    config(['admin.mail' => 'super-admin-offline@example.invalid', 'admin.password' => bin2hex(random_bytes(24))]);
    $dataset = new ProductionDataset($argv[1]);
    $report = app(CoffracNativeImporter::class)->rehearse($dataset, $planning);
    $matchingPasswords = 0;
    foreach ($dataset->credentials() as $credential) {
        $targetId = $report['matching']['users'][$credential['id']];
        if (! hash_equals($credential['password_hash'], DB::table('users')->where('id', $targetId)->value('password'))) {
            throw new RuntimeException('Hash de compte non conserve.');
        }
        $matchingPasswords++;
    }
    $report['verification'] = [
        'matching_password_hashes' => $matchingPasswords,
        'single_seeded_super_admin' => DB::table('users')->where('admin', true)->count() === 1
            && ! DB::table('coffrac_user_links')->where('user_id', DB::table('users')->where('admin', true)->value('id'))->exists(),
        'historically_billed_dossiers' => DB::table('dossier_billings')->distinct()->count('dossier_id'),
        'enabled_external_portals' => DB::table('users')->where('role', 3)->where('portal_enabled', true)->count(),
        'held_atypical_accounts' => DB::table('coffrac_user_links')->where('access_state', 'profile_review')->count(),
        'imported_auth_tokens_or_sessions' => DB::table('password_reset_tokens')->count() + DB::table('sessions')->count() + DB::table('mobile_access_tokens')->count(),
        'outbound_jobs_or_messages' => DB::table('jobs')->count() + DB::table('dossier_sms')->count() + DB::table('capcee_deliveries')->count(),
        'inactive_native_mail_templates' => DB::table('mail_templates')->where('is_active', false)->count(),
        'active_native_mail_templates' => DB::table('mail_templates')->where('is_active', true)->count(),
        'mail_templates_with_sender' => DB::table('mail_templates')->whereNotNull('mail_sender_id')->count(),
        'readable_archived_sms_templates' => count(app(DossierSmsService::class)->templates()),
        'inferred_technician_sectors' => 0,
        'techcalendar_department_assignments' => DB::table('department_user')->count(),
        'explicit_default_service_grants' => DB::table('service_user')->count(),
    ];
    // Repeating the same source must not create any additional native rows.
    if (app(CoffracNativeImporter::class)->rehearse($dataset, $planning) !== array_diff_key($report, ['verification' => true])) {
        throw new RuntimeException('Reprise idempotente non conforme.');
    }
    if (in_array($argc, [4, 6], true) && $argv[3] !== '-') {
        $report['document_verification'] = app(NativeDocumentAudit::class)->rehearse($dataset, new DocumentReconciliationPlan($argv[3], $dataset));
    }
    $handle = fopen($argv[2], 'xb');
    if ($handle === false) {
        throw new RuntimeException('Rapport deja present.');
    }
    chmod($argv[2], 0600);
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    if (fwrite($handle, $json) !== strlen($json)) {
        throw new RuntimeException('Rapport incomplet.');
    }
    fclose($handle);
    echo json_encode(['native_counts' => $report['counts'], 'verification' => $report['verification'],
        'external_access_verification' => [
            'policy_version' => $report['matching']['external_access']['policy_version'],
            'confirmed_business_rules' => $report['matching']['external_access']['confirmed_business_rules'],
            'source_external_accounts' => $report['matching']['external_access']['source_external_accounts'],
            'native_disabled_accounts_checked' => $report['matching']['external_access']['native_disabled_accounts_checked'],
            'candidate_categories_by_kind' => array_map(fn ($profile) => $profile['candidate_categories'], $report['matching']['external_access']['profiles']),
            'approved_category_expansions_by_kind' => array_map(fn ($profile) => array_keys($profile['approved_category_expansions']), $report['matching']['external_access']['profiles']),
            'assignments_with_wrong_or_missing_profile' => array_map('count', $report['matching']['external_access']['assignments_with_wrong_or_missing_profile']),
            'rights_approval_required' => true, 'proposal_applied' => false,
        ],
        'lot_satisfaction_verification' => [
            'source_lots' => $report['matching']['lots_and_satisfaction']['source_lots'],
            'dossiers_with_explicit_lot_id' => $report['matching']['lots_and_satisfaction']['dossiers_with_explicit_lot_id'],
            'dossiers_with_free_lot_label_only' => $report['matching']['lots_and_satisfaction']['dossiers_with_free_lot_label_only'],
            'historical_dossier_opinions' => count($report['matching']['lots_and_satisfaction']['dossier_opinions']),
            'historical_operation_opinions' => count($report['matching']['lots_and_satisfaction']['operation_opinions']),
            'operation_report_payloads_retained' => $report['matching']['lots_and_satisfaction']['operation_report_payloads_retained'],
            'native_mapping_review_required' => $report['matching']['lots_and_satisfaction']['native_mapping_review_required'],
            'native_lots_created' => 0, 'historical_opinions_applied' => false,
        ],
        'planning_verification' => isset($report['matching']['techcalendar_planning']) ? [
            'matched_technicians' => count($report['matching']['techcalendar_planning']['matched_users']),
            'department_assignments' => $report['matching']['techcalendar_planning']['department_assignments'],
            'base_locations_copied' => $report['matching']['techcalendar_planning']['base_locations_copied'],
            'matched_service_durations' => count($report['matching']['techcalendar_planning']['durations']['matched_services']),
            'service_durations_pending_review' => count($report['matching']['techcalendar_planning']['durations']['native_services_pending_review']),
            'source_absences' => $report['matching']['techcalendar_planning']['absences']['source_count'],
            'absences_pending_review' => count($report['matching']['techcalendar_planning']['absences']['pending_temporal_and_identity_review']),
        ] : null,
        'document_verification' => $report['document_verification'] ?? null,
        'native_import_ready' => false, 'final_cutover_ready' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // Never print a PDO message: SQL bindings can contain credentials or personal data.
    fwrite(STDERR, 'STOP : repetition annulee ('.get_class($error).', '.basename($error->getFile()).':'.$error->getLine()."). Aucun detail personnel affiche.\n");
    exit(1);
}
