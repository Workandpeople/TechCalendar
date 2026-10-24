<?php

use App\Services\Coffrac\CoffracNativeImporter;
use App\Services\Coffrac\NativeTechCalendarPlanning;
use App\Services\Coffrac\ProductionDataset;
use App\Services\Coffrac\TechCalendarPlanningSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Fixtures/CoffracProductionFixture.php';
require_once __DIR__.'/../Fixtures/TechCalendarPlanningFixture.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'coffrac.offline_rehearsal' => true,
        'admin.mail' => 'seed-admin@example.test', 'admin.password' => 'Seed-password-123',
        'services.smsfactor.enabled' => false, 'capcee.enabled' => false, 'mail.default' => 'array']);
    Http::preventStrayRequests();
    $this->datasetDirectory = productionCoffracFixture();
    $this->planningDirectory = techCalendarPlanningFixture();
    $this->planningSnapshot = fn () => new TechCalendarPlanningSnapshot($this->planningDirectory, hash_file('sha256', $this->planningDirectory.'/references.json.gz'));
});

afterEach(function () {
    File::deleteDirectory($this->datasetDirectory);
    File::deleteDirectory($this->planningDirectory);
});

it('matches by exact normalized email rather than unrelated ids and preserves all original access states', function () {
    $dataset = new ProductionDataset($this->datasetDirectory);
    $planning = ($this->planningSnapshot)();
    $report = app(CoffracNativeImporter::class)->rehearse($dataset, $planning);
    $nativeId = $report['matching']['users'][21];
    $user = DB::table('users')->find($nativeId);
    expect($nativeId)->not->toBe(901)
        ->and($report['matching']['techcalendar_planning']['matched_users'])->toBe([21 => 901])
        ->and($user->department_code)->toBe('01')
        ->and($user->day_start_time)->toBe('08:00:00')
        ->and($user->day_end_time)->toBe('18:00:00')
        ->and($user->break_duration_minutes)->toBe(60)
        ->and($user->role)->toBe(2)->and($user->admin)->toBe(0)->and($user->portal_enabled)->toBe(0)
        ->and(DB::table('users')->count())->toBe(7)
        ->and(DB::table('department_user')->where('user_id', $nativeId)->orderBy('department_code')->pluck('department_code')->all())->toBe(['75', '92'])
        ->and(DB::table('department_user')->where('user_id', '!=', $nativeId)->count())->toBe(0)
        ->and(DB::table('service_user')->where('user_id', $nativeId)->count())->toBe(1)
        ->and(DB::table('services')->where('coffrac_reference', '7')->value('average_duration_minutes'))->toBe(90)
        ->and(DB::table('appointments')->value('duration_minutes'))->toBe(60)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('dossier_sms')->count())->toBe(0);
    foreach ($dataset->credentials() as $credential) {
        expect(DB::table('users')->where('id', $report['matching']['users'][$credential['id']])->value('password'))->toBe($credential['password_hash']);
    }
    expect(app(CoffracNativeImporter::class)->rehearse($dataset, $planning))->toBe($report)
        ->and(DB::table('department_user')->count())->toBe(2);
    expect(fn () => app(CoffracNativeImporter::class)->rehearse($dataset))->toThrow(RuntimeException::class, 'planning differente');
    Http::assertNothingSent();
});

it('does not guess when identities are missing ambiguous or inactive in TechCalendar', function (string $case, string $reason) {
    $path = $this->planningDirectory.'/references.json.gz';
    $data = json_decode(gzdecode(file_get_contents($path)), true);
    if ($case === 'missing') {
        $data['tables']['users']['rows'][0]['email'] = 'another@example.test';
    } elseif ($case === 'inactive') {
        $data['tables']['users']['rows'][0]['deleted_at'] = '2026-10-01 00:00:00';
    } else {
        $data['tables']['users']['rows'][] = [...$data['tables']['users']['rows'][0], 'id' => 902];
        $data['tables']['users']['count']++;
    }
    writeTechCalendarPlanningFixture($this->planningDirectory, $data);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory), ($this->planningSnapshot)());
    expect($report['matching']['techcalendar_planning']['issues'][$reason])->toBe([21])
        ->and($report['matching']['techcalendar_planning']['matched_users'])->toBe([])
        ->and(DB::table('department_user')->count())->toBe(0)
        ->and(DB::table('users')->count())->toBe(7)
        ->and(DB::table('users')->where('id', $report['matching']['users'][21])->value('department_code'))->toBeNull()
        ->and(DB::table('service_user')->count())->toBe(1);
})->with([['missing', 'no_exact_email_match'], ['inactive', 'techcalendar_account_inactive'], ['ambiguous', 'ambiguous_email']]);

it('copies sectors without reactivating an inactive Coffrac account', function () {
    $path = $this->datasetDirectory.'/users.jsonl';
    $rows = array_map(fn ($line) => json_decode($line, true), file($path));
    $rows[2]['actif'] = 0;
    file_put_contents($path, implode('', array_map(fn ($row) => json_encode($row)."\n", $rows)));
    sealProductionCoffracFixture($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory), ($this->planningSnapshot)());
    expect(DB::table('department_user')->count())->toBe(2)
        ->and(DB::table('users')->where('id', $report['matching']['users'][21])->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('coffrac_user_links')->where('source_user_id', 21)->value('access_state'))->toBe('source_inactive');
});

it('keeps invalid working hours for review without dropping proven sectors', function () {
    $path = $this->planningDirectory.'/references.json.gz';
    $data = json_decode(gzdecode(file_get_contents($path)), true);
    $data['tables']['users']['rows'][0]['day_start_time'] = '22:00';
    writeTechCalendarPlanningFixture($this->planningDirectory, $data);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory), ($this->planningSnapshot)());
    expect($report['matching']['techcalendar_planning']['issues']['working_hours_require_review'])->toBe([21])
        ->and(DB::table('department_user')->count())->toBe(2)
        ->and(DB::table('users')->where('id', $report['matching']['users'][21])->value('day_start_time'))->toBeNull();
});

it('does not transfer durations across unknown labels or ambiguous native service variants', function (string $case) {
    $path = $this->planningDirectory.'/references.json.gz';
    $data = json_decode(gzdecode(file_get_contents($path)), true);
    if ($case === 'different name') {
        $data['tables']['services']['rows'][0]['name'] = 'Prestation source - autre version';
    } elseif ($case === 'invalid duration') {
        $data['tables']['services']['rows'][0]['average_duration_minutes'] = 1441;
    } else {
        $data['tables']['services']['rows'][] = [...$data['tables']['services']['rows'][0], 'id' => 72, 'average_duration_minutes' => 120];
        $data['tables']['services']['count']++;
    }
    writeTechCalendarPlanningFixture($this->planningDirectory, $data);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory), ($this->planningSnapshot)());
    expect($report['matching']['techcalendar_planning']['durations']['native_services_pending_review'])->toBe([7])
        ->and(DB::table('services')->where('coffrac_reference', '7')->value('average_duration_minutes'))->toBe(60);
})->with(['different name', 'invalid duration', 'ambiguous name']);

it('refuses a changed planning collection on an idempotent rerun', function () {
    $dataset = new ProductionDataset($this->datasetDirectory);
    app(CoffracNativeImporter::class)->rehearse($dataset, ($this->planningSnapshot)());
    $path = $this->planningDirectory.'/references.json.gz';
    $data = json_decode(gzdecode(file_get_contents($path)), true);
    $data['exported_at'] = '2026-10-24T19:00:00+00:00';
    writeTechCalendarPlanningFixture($this->planningDirectory, $data);
    expect(fn () => app(CoffracNativeImporter::class)->rehearse($dataset, ($this->planningSnapshot)()))
        ->toThrow(RuntimeException::class, 'planning differente');
    expect(DB::table('department_user')->count())->toBe(2);
});

it('refuses direct projection in production before opening a database connection', function () {
    $planning = ($this->planningSnapshot)();
    try {
        app()->detectEnvironment(fn () => 'production');
        expect(fn () => app(NativeTechCalendarPlanning::class)->apply($planning, [], [], []))
            ->toThrow(RuntimeException::class, 'Cible refusee');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
    expect(DB::table('department_user')->count())->toBe(0);
});

it('reuses full settings without importing TechCalendar accounts privileges aliases or obsolete grants', function () {
    File::deleteDirectory($this->planningDirectory);
    $this->planningDirectory = techCalendarPlanningFixture([], true);
    $dataset = new ProductionDataset($this->datasetDirectory);
    $planning = ($this->planningSnapshot)();
    $report = app(CoffracNativeImporter::class)->rehearse($dataset, $planning);
    $matching = $report['matching']['techcalendar_planning'];
    $tech = DB::table('users')->find($report['matching']['users'][21]);
    expect($matching['source_technicians'])->toBe(1)
        ->and($matching['base_locations_copied'])->toBe(1)
        ->and((float) $tech->latitude)->toBe(48.8566)
        ->and((float) $tech->longitude)->toBe(2.3522)
        ->and($tech->address)->toBe('Rue de test')
        ->and(DB::table('users')->where('id', $report['matching']['users'][11])->value('admin'))->toBe(0)
        ->and(DB::table('users')->count())->toBe(7)
        ->and(DB::table('users')->where('admin', true)->count())->toBe(1)
        ->and(DB::table('external_delegataires')->count())->toBe(2)
        ->and(DB::table('external_service_aliases')->count())->toBe(0)
        ->and($matching['durations']['matched_services'])->toBe([7 => 71])
        ->and($matching['durations']['evidence'][7])->toBe(['techcalendar_service_id' => 71, 'exact_label' => true, 'source_alias_ids' => [6]])
        ->and($matching['absences'])->toBe(['source_count' => 0, 'created' => 0, 'pending_temporal_and_identity_review' => []]);
    expect(app(CoffracNativeImporter::class)->rehearse($dataset, $planning))->toBe($report);
    Http::assertNothingSent();
});

it('uses explicit legacy alias evidence only for duration without merging the native catalogue', function () {
    File::deleteDirectory($this->planningDirectory);
    $this->planningDirectory = techCalendarPlanningFixture(['services' => [
        ['id' => 71, 'type' => 'COFFRAC', 'name' => 'Ancien libelle', 'average_duration_minutes' => 130],
    ]], true);
    file_put_contents($this->datasetDirectory.'/fiche_type.jsonl', json_encode(['id' => 9, 'name' => 'Prestation nouvelle'])."\n", FILE_APPEND);
    sealProductionCoffracFixture($this->datasetDirectory);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory), ($this->planningSnapshot)());
    $matching = $report['matching']['techcalendar_planning'];
    expect($matching['durations']['matched_services'])->toBe([7 => 71])
        ->and($matching['durations']['evidence'][7])->toBe(['techcalendar_service_id' => 71, 'exact_label' => false, 'source_alias_ids' => [6]])
        ->and($matching['durations']['native_services_pending_review'])->toBe([9])
        ->and(DB::table('services')->whereNotNull('coffrac_reference')->count())->toBe(2)
        ->and(DB::table('services')->where('coffrac_reference', '7')->value('name'))->toBe('Prestation source')
        ->and(DB::table('services')->where('coffrac_reference', '7')->value('average_duration_minutes'))->toBe(130)
        ->and(DB::table('services')->where('coffrac_reference', '9')->value('average_duration_minutes'))->toBe(60)
        ->and(DB::table('appointments')->value('duration_minutes'))->toBe(60)
        ->and(DB::table('service_user')->count())->toBe(2)
        ->and(DB::table('external_service_aliases')->count())->toBe(0);
});

it('does not choose between a direct label and a conflicting explicit alias', function () {
    File::deleteDirectory($this->planningDirectory);
    $this->planningDirectory = techCalendarPlanningFixture(['services' => [
        ['id' => 71, 'type' => 'COFFRAC', 'name' => 'Prestation source', 'average_duration_minutes' => 90],
        ['id' => 72, 'type' => 'COFFRAC', 'name' => 'Autre prestation', 'average_duration_minutes' => 130],
    ], 'external_service_aliases' => [['id' => 6, 'service_id' => 72, 'source' => 'coffrac', 'external_type' => 'COFFRAC', 'external_name' => 'Prestation source']]], true);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory), ($this->planningSnapshot)());
    expect($report['matching']['techcalendar_planning']['durations']['native_services_pending_review'])->toBe([7])
        ->and(DB::table('services')->where('coffrac_reference', '7')->value('average_duration_minutes'))->toBe(60);
});

it('does not guess from normalized alias fields or unrelated connectors', function (string $case) {
    File::deleteDirectory($this->planningDirectory);
    $alias = ['id' => 6, 'service_id' => 71, 'source' => 'coffrac', 'external_type' => 'COFFRAC', 'external_name' => 'Prestation source'];
    if ($case === 'other connector') {
        $alias['source'] = 'global_plus';
    } elseif ($case === 'other type') {
        $alias['external_type'] = 'RGE';
    } else {
        $alias['external_name'] = 'Nom different';
        $alias['normalized_external_name'] = 'Prestation source';
    }
    $this->planningDirectory = techCalendarPlanningFixture(['services' => [
        ['id' => 71, 'type' => 'COFFRAC', 'name' => 'Ancien nom', 'average_duration_minutes' => 90],
    ], 'external_service_aliases' => [$alias]], true);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory), ($this->planningSnapshot)());
    expect($report['matching']['techcalendar_planning']['durations']['matched_services'])->toBe([])
        ->and(DB::table('services')->where('coffrac_reference', '7')->value('average_duration_minutes'))->toBe(60);
})->with(['other connector', 'other type', 'normalized only']);

it('retains invalid source depot data for review instead of inventing or clearing a base', function (array $change) {
    File::deleteDirectory($this->planningDirectory);
    $this->planningDirectory = techCalendarPlanningFixture([], true);
    $path = $this->planningDirectory.'/references.json.gz';
    $data = json_decode(gzdecode(file_get_contents($path)), true);
    $data['tables']['users']['rows'][0] = array_replace($data['tables']['users']['rows'][0], $change);
    writeTechCalendarPlanningFixture($this->planningDirectory, $data);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory), ($this->planningSnapshot)());
    expect($report['matching']['techcalendar_planning']['issues']['base_location_requires_review'])->toBe([21])
        ->and(DB::table('users')->where('id', $report['matching']['users'][21])->value('latitude'))->toBeNull()
        ->and(DB::table('department_user')->count())->toBe(2);
})->with([
    [['latitude' => null]], [['longitude' => null]], [['latitude' => 91]], [['longitude' => 181]],
    [['latitude' => 0, 'longitude' => 0]], [['address' => '']], [['address' => str_repeat('a', 256)]], [['address' => "Adresse\0"]],
]);

it('does not silently import nonempty absences without a proven source timezone', function () {
    File::deleteDirectory($this->planningDirectory);
    $this->planningDirectory = techCalendarPlanningFixture(['technician_absences' => [['id' => 1, 'technician_id' => 901,
        'created_by' => 1, 'starts_at' => '2026-10-26 08:00:00', 'ends_at' => '2026-10-26 18:00:00', 'reason' => 'Test']]], true);
    $report = app(CoffracNativeImporter::class)->rehearse(new ProductionDataset($this->datasetDirectory), ($this->planningSnapshot)());
    expect($report['matching']['techcalendar_planning']['absences']['pending_temporal_and_identity_review'])->toBe([1])
        ->and($report['remaining_gates'])->toContain('techcalendar_absences_temporal_and_identity_review')
        ->and(DB::table('technician_absences')->count())->toBe(0);
});
