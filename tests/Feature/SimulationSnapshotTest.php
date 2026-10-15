<?php

use App\Http\Controllers\Planner\PlannerBookingController;
use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\TechnicianDailyRouteMetric;
use App\Models\User;
use App\Services\MapboxDrivingRouteService;
use App\Services\Simulation\SimulationSafety;
use App\Services\Simulation\SnapshotImporter;
use App\Services\Simulation\SnapshotReader;
use App\Services\TechnicianDailyRouteMetricService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function simulationFixture(): string
{
    $directory = sys_get_temp_dir().'/techcalendar-test-snapshot-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    mkdir($directory.'/coffrac', 0700);
    $tables = array_fill_keys(['departments', 'users', 'services', 'external_service_aliases', 'service_user', 'department_user', 'external_delegataires', 'mail_senders', 'mail_templates', 'technician_absences'], []);
    $tables['users'] = [['id' => 11, 'email' => 'TECH@example.test', 'first_name' => 'Jean', 'last_name' => 'Test', 'role' => 2, 'admin' => 0]];
    $tables['services'] = [['id' => 21, 'name' => 'BAR EN 101', 'type' => 'COFFRAC', 'average_duration_minutes' => 90]];
    $tables['external_service_aliases'] = [['id' => 31, 'service_id' => 21, 'source' => 'coffrac', 'external_type' => null,
        'external_name' => 'BAR EN 101 combles', 'normalized_external_type' => '', 'normalized_external_name' => 'bar en 101 combles']];
    $tables['external_delegataires'] = [['id' => 41, 'source' => 'coffrac', 'external_id' => '81', 'name' => 'Delegataire test']];
    $references = ['format' => 'techcalendar.reference-snapshot', 'version' => 1, 'complete' => true, 'tables' => []];
    foreach ($tables as $name => $rows) {
        $references['tables'][$name] = ['count' => count($rows), 'rows' => $rows];
    }
    file_put_contents($directory.'/references.json.gz', gzencode(json_encode($references, JSON_THROW_ON_ERROR)));

    $legacy = array_fill_keys(SnapshotReader::TABLES, []);
    $baseUser = ['prenom' => 'Prenom', 'name' => 'Historique', 'type' => 4, 'actif' => 1, 'is_archived' => 0,
        'adresse' => null, 'telephone' => null, 'latitude' => null, 'longitude' => null, 'created_at' => null, 'updated_at' => null];
    $legacy['users'] = [
        ['id' => 71, 'email' => 'tech@example.test'] + $baseUser,
        ['id' => 72, 'email' => 'historique@example.test'] + $baseUser,
        ['id' => 73, 'email' => 'source-admin@example.test', 'type' => 1] + $baseUser,
    ];
    $legacy['fiche_type'] = [['id' => 51, 'name' => 'BAR EN 101 combles'], ['id' => 52, 'name' => 'Autre fiche ancienne']];
    $legacy['status'] = [['id' => 2, 'name' => 'RDV ATTENTE VISITE'], ['id' => 11, 'name' => 'DOSSIERS FACTURER']];
    $baseDossier = ['dossier_unique' => 'DOUBLON', 'client_name' => 'Beneficiaire test', 'numero_lot' => 'Lot source',
        'delegataire_id' => 81, 'author_id' => 73, 'technicien_id' => 71, 'entreprise_id' => null,
        'client_mail' => null, 'telephone' => '', 'mobile' => '', 'adress' => '', 'ville' => '', 'zip' => 'code incorrect',
        'adresse_demandeur' => null, 'ville_demandeur' => null, 'code_postale_demandeur' => null,
        'latitude' => null, 'longitude' => null, 'status_id' => 1, 'created_at' => '2026-10-01 08:00:00', 'updated_at' => null];
    $legacy['dossiers'] = [['id' => 101] + $baseDossier, ['id' => 102] + $baseDossier];
    $legacy['dossier_fiches'] = [
        ['id' => 201, 'dossier_id' => 101, 'fiche_id' => 51, 'status_id' => 2, 'created_at' => null, 'updated_at' => null],
        ['id' => 202, 'dossier_id' => 102, 'fiche_id' => 52, 'status_id' => 11, 'created_at' => null, 'updated_at' => null],
    ];
    $baseRdv = ['dossier_id' => 101, 'dossier_fiche' => 201, 'date_rdv' => '2026-10-20', 'heure' => '10:00:00.000000', 'rdv_duree' => 2, 'created_at' => null, 'updated_at' => null];
    $legacy['dossier_fiche_rdv'] = [
        ['id' => 301, 'date_rdv' => '2026-10-25'] + $baseRdv,
        ['id' => 302] + $baseRdv,
        ['id' => 303, 'dossier_id' => 102, 'dossier_fiche' => 202, 'heure' => null] + $baseRdv,
    ];
    $legacy['dossier_comment'] = [['id' => 401, 'dossier_id' => 101, 'author_id' => 73, 'text' => '<script>alert(1)</script>', 'is_private' => 1, 'created_at' => null, 'updated_at' => null]];
    $legacy['dossier_document'] = [['id' => 501, 'dossier_id' => 101, 'name' => 'Facture', 'path' => '../unsafe.php', 'is_private' => 1, 'is_delegataire' => 1]];
    $legacy['rdv_extern'] = [['id' => 601, 'technicien_id' => 71, 'date_rdv' => '2026-10-21', 'heure' => '09:00:00.000000',
        'rdv_duree' => 1, 'client_name' => 'Occupation externe', 'adress' => '', 'latitude' => null, 'longitude' => null,
        'type' => 'Autre', 'comment' => 'Historique', 'created_at' => null, 'updated_at' => null]];
    $manifest = ['format' => 'techcalendar.coffrac-snapshot', 'version' => 1, 'complete' => true, 'dump_sha256' => str_repeat('a', 64), 'tables' => []];
    foreach ($legacy as $table => $rows) {
        $path = $directory.'/coffrac/'.$table.'.jsonl';
        file_put_contents($path, implode('', array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows)));
        $manifest['tables'][$table] = ['count' => count($rows), 'sha256' => hash_file('sha256', $path)];
    }
    file_put_contents($directory.'/coffrac/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/matching.json', '{"users":{},"services":{}}');
    file_put_contents($directory.'/dataset.json', json_encode([
        'references_sha256' => hash_file('sha256', $directory.'/references.json.gz'),
        'coffrac_manifest_sha256' => hash_file('sha256', $directory.'/coffrac/manifest.json'),
        'matching_sha256' => hash_file('sha256', $directory.'/matching.json'),
    ], JSON_THROW_ON_ERROR));

    return $directory;
}

beforeEach(function () {
    config(['simulation.enabled' => true, 'crm.v2_enabled' => true, 'admin.mail' => 'local-admin@example.test', 'admin.password' => 'Local-password-1234']);
    $this->snapshotDirectory = simulationFixture();
    config(['simulation.dataset' => $this->snapshotDirectory]);
});

afterEach(function () {
    File::deleteDirectory($this->snapshotDirectory);
});

it('imports native dossiers and appointments without merging references or inventing locations', function () {
    $reader = new SnapshotReader($this->snapshotDirectory);
    $report = app(SnapshotImporter::class)->import($reader);
    expect(DB::table('dossiers')->count())->toBe(2)
        ->and(DB::table('dossiers')->pluck('reference')->all())->toBe(['CFR-101', 'CFR-102'])
        ->and(DB::table('dossiers')->where('id', 101)->value('delegataire_id'))->toBe(41)
        ->and(DB::table('appointments')->count())->toBe(3)
        ->and(DB::table('appointments')->whereNull('deleted_at')->value('external_reference'))->toBe('302')
        ->and(DB::table('appointments')->whereNull('deleted_at')->value('latitude'))->toBeNull()
        ->and(DB::table('appointments')->whereNull('deleted_at')->value('duration_minutes'))->toBe(120)
        ->and(DB::table('appointments')->whereNull('deleted_at')->value('starts_at'))->toBe('2026-10-20 10:00:00')
        ->and($report['matching']['users'][71])->toBe(11)
        ->and($report['matching']['services'][51])->toBe(DB::table('services')->where('coffrac_reference', 51)->value('id'))
        ->and(DB::table('services')->where('coffrac_reference', 51)->value('name'))->toBe('BAR EN 101 combles')
        ->and(User::where('email', 'source-admin@example.test')->exists())->toBeFalse()
        ->and($report['issues']['appointment_not_projectable']['source_ids'])->toBe([303])
        ->and(DB::table('legacy_records')->where('source_table', 'dossier_fiche_rdv')->count())->toBe(3)
        ->and(DB::table('dossier_documents')->count())->toBe(0);
    expect(app(SnapshotImporter::class)->import($reader))->toBe($report)
        ->and(DB::table('appointments')->count())->toBe(3)
        ->and(DB::table('appointments')->where('external_source', 'coffrac_external_archive')->value('starts_at'))->toBe('2026-10-21 09:00:00');
});

it('blocks outgoing HTTP and dynamic SMTP in simulation', function () {
    config(['broadcasting.default' => 'reverb']);
    app(SimulationSafety::class)->activate();
    expect(config('broadcasting.default'))->toBe('log');
    Http::fake();
    expect(fn () => Http::get('https://example.test'))->toThrow(RuntimeException::class, 'interdits');
    config(['mail.mailers.danger' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1], 'mail.from.address' => 'local@example.test']);
    expect(Mail::mailer('danger')->raw('test', fn ($message) => $message->to('real@example.test')))->toBeNull();
});

it('preserves authorised development APIs and Reverb while still blocking mail', function () {
    config([
        'simulation.allow_external_apis' => true, 'broadcasting.default' => 'reverb',
        'services.mapbox.token' => 'mapbox-test', 'services.openai.api_key' => 'openai-test',
        'services.global_plus.api_token' => 'global-test',
    ]);
    app(SimulationSafety::class)->activate();
    expect(config('broadcasting.default'))->toBe('reverb')
        ->and(config('services.mapbox.token'))->toBe('mapbox-test')
        ->and(config('services.openai.api_key'))->toBe('openai-test')
        ->and(config('services.global_plus.api_token'))->toBe('global-test');
    Http::fake(['api.openai.com/*' => Http::response(['ok' => true])]);
    expect(Http::get('https://api.openai.com/v1/models')->successful())->toBeTrue();
    config(['mail.mailers.danger' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1], 'mail.from.address' => 'local@example.test']);
    expect(Mail::mailer('danger')->raw('test', fn ($message) => $message->to('real@example.test')))->toBeNull();
});

it('refuses production targets even when forced and keeps existing data', function () {
    User::factory()->create();
    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app(SimulationSafety::class)->assertSeedTarget())->toThrow(RuntimeException::class)
        ->and(User::count())->toBe(1);
});

it('refuses an ordinary local mysql database before opening a connection', function () {
    $originalConnection = config('database.default');
    app()->detectEnvironment(fn () => 'local');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'temp', 'database.connections.mysql.host' => '127.0.0.1']);
    try {
        expect(fn () => app(SimulationSafety::class)->assertSeedTarget())->toThrow(RuntimeException::class, 'Cible refusee');
    } finally {
        config(['database.default' => $originalConnection]);
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('refuses corrupted data and a populated non snapshot target', function () {
    User::factory()->create();
    expect(fn () => app(SnapshotImporter::class)->import(new SnapshotReader($this->snapshotDirectory)))
        ->toThrow(RuntimeException::class, 'doit etre vide');
    file_put_contents($this->snapshotDirectory.'/coffrac/dossiers.jsonl', "{}\n", FILE_APPEND);
    expect(fn () => new SnapshotReader($this->snapshotDirectory))->toThrow(RuntimeException::class, 'Empreinte invalide');
});

it('shows private source metadata safely without exposing remote document links', function () {
    app(SnapshotImporter::class)->import(new SnapshotReader($this->snapshotDirectory));
    $actor = User::where('email', 'local-admin@example.test')->firstOrFail();
    $this->actingAs($actor)->get(route('v2.dossiers.show', Dossier::findOrFail(101)))
        ->assertOk()->assertSee('Beneficiaire test')->assertSee('Fichier non collecté')
        ->assertSee('Privé · Équipe interne uniquement')->assertSee('Rendre public')->assertDontSee('../unsafe.php')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});

it('removes stale route metrics instead of calculating distances from missing coordinates', function () {
    app(SnapshotImporter::class)->import(new SnapshotReader($this->snapshotDirectory));
    $appointment = Appointment::where('external_source', 'coffrac_archive')->firstOrFail();
    DB::table('users')->where('id', $appointment->technician_id)->update(['latitude' => 48.85, 'longitude' => 2.35]);
    DB::table('appointments')->where('id', $appointment->id)->update(['latitude' => 48.86, 'longitude' => 2.36]);
    $technician = $appointment->technician;
    $date = Carbon::parse('2026-10-20');
    $service = app(TechnicianDailyRouteMetricService::class);
    expect($service->ensureForTechnicianPeriod($technician, $date, $date))->toHaveCount(1);

    DB::table('appointments')->where('id', $appointment->id)->update(['latitude' => null]);
    expect($service->ensureForTechnicianPeriod($technician, $date, $date))->toHaveCount(0)
        ->and(TechnicianDailyRouteMetric::count())->toBe(0);
});

it('does not suggest travel slots around an appointment without coordinates', function () {
    $controller = app(PlannerBookingController::class);
    $routes = Mockery::mock(MapboxDrivingRouteService::class);
    $routes->shouldNotReceive('estimate');
    $appointments = collect([new Appointment(['latitude' => null, 'longitude' => null])]);
    $arguments = [new User, $appointments, [], 60, Carbon::parse('2026-10-20 12:00'), $routes];
    expect((new ReflectionMethod($controller, 'suggestSlotsForDay'))->invokeArgs($controller, $arguments))->toBe([])
        ->and((new ReflectionMethod($controller, 'suggestPreferredSlotForDay'))->invokeArgs($controller, $arguments))->toBeNull();
});

it('requires explicit reset confirmation and validates checksums before deleting data', function () {
    $user = User::factory()->create();
    $this->artisan('simulation:reset')->assertExitCode(1);
    expect($user->fresh())->not->toBeNull();
    file_put_contents($this->snapshotDirectory.'/coffrac/dossiers.jsonl', "{}\n", FILE_APPEND);
    expect(fn () => Artisan::call('simulation:reset', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'Empreinte invalide');
    expect($user->fresh())->not->toBeNull();
});
