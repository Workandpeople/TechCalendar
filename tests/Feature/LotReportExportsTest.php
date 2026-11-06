<?php

use App\Jobs\BuildLotReportExport;
use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\LotReportExport;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\LotReportExports;
use App\Services\Crm\ReportExportStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'queue.default' => 'database', 'report_exports.reserve_bytes' => 0]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'EXPORT',
        'name' => 'Organisation export', 'kind' => 'client', 'is_active' => true,
        'portal_categories' => ['report', 'awaiting_visit'], 'report_download_naming' => 'outcome_prefix']);
    $this->actor = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->organisation->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => ClientAccess::defaults('client')]);
    $this->makeDossier = function (string $lot = 'LOT 299', ?int $organisation = null): Dossier {
        $dossier = Dossier::create(['reference' => 'EXP-'.Str::uuid(), 'legacy_lot_name' => $lot,
            'company_name' => 'BENEFICIAIRE TEST', 'client_id' => $organisation ?? $this->organisation->id,
            'delegataire_id' => $organisation ?? $this->organisation->id, 'address' => 'Rue Test', 'city' => 'Paris']);
        $dossier->operations()->create()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();

        return $dossier;
    };
    $this->makeReport = function (Dossier $dossier, array $attributes = []) {
        $path = 'crm/dossiers/'.$dossier->id.'/'.Str::uuid().'.pdf';
        $content = file_get_contents(__DIR__.'/../Fixtures/documents/preview.pdf');
        Storage::disk('local')->put($path, $content);

        return $dossier->documents()->create([...['name' => 'Rapport de contrôle - REF-001', 'path' => $path,
            'size' => strlen($content), 'mime_type' => 'application/pdf', 'document_type' => 'report',
            'control_outcome' => 'unsatisfactory', 'control_reference' => 'REF-001',
            'client_visible' => true, 'delegataire_visible' => true], ...$attributes]);
    };
    $this->makeArchive = function (Dossier $dossier, int $id = 100, array $payload = [], array $overrides = []): void {
        $file = 'report-'.$id.'.pdf';
        Storage::disk('local')->put('collected/'.$file, file_get_contents(__DIR__.'/../Fixtures/documents/preview.pdf'));
        $manifest = Storage::disk('local')->exists('manifest.list0') ? Storage::disk('local')->get('manifest.list0') : '';
        Storage::disk('local')->put('manifest.list0', $manifest.$file."\0");
        config(['crm.collected_documents_root' => Storage::disk('local')->path('collected'),
            'crm.collected_documents_manifest' => Storage::disk('local')->path('manifest.list0')]);
        DB::table('legacy_records')->insert([...['source_table' => 'dossier_document', 'source_id' => $id,
            'dossier_id' => $dossier->id, 'payload' => json_encode([...['name' => 'Rapport de contrôle - ARCH-001',
                'reference_controle' => 'ARCH-001', 'path' => $file, 'status' => 'Satisfaisant',
                'is_private' => false, 'is_delegataire' => true], ...$payload])], ...$overrides]);
    };
    $this->startExport = function (string $lot = 'LOT 299'): LotReportExport {
        $response = $this->actingAs($this->actor)->postJson(route('client.report-exports.store'), ['lot' => $lot])->assertStatus(202)
            ->assertJsonPath('status', 'queued')->assertJsonPath('message', 'Demande enregistrée. La préparation va commencer automatiquement.');

        return LotReportExport::findOrFail($response->json('id'));
    };
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('offers only visible exact lot names and treats SQL wildcards literally', function () {
    ($this->makeDossier)('LOT %_ 299');
    ($this->makeDossier)('LOT XY 299');
    $other = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'OTHER', 'name' => 'Autre organisation', 'kind' => 'client', 'is_active' => true]);
    ($this->makeDossier)('LOT SECRET', $other->id);
    $hidden = ($this->makeDossier)('LOT CONFIDENTIEL');
    $hidden->operations()->first()->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    $this->actingAs($this->actor)->getJson(route('client.report-exports.lots', ['q' => '%_']))
        ->assertOk()->assertExactJson(['lots' => ['LOT %_ 299']]);
    $this->getJson(route('client.report-exports.lots'))->assertOk()->assertJsonCount(2, 'lots');
    $this->postJson(route('client.report-exports.store'), ['lot' => 'LOT'])->assertNotFound();
    expect(LotReportExport::count())->toBe(0);
});

it('prepares all pages of authorized native and archived reports with existing naming and collision protection', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $paths = [];
    for ($i = 0; $i < 27; $i++) {
        $dossier = ($this->makeDossier)();
        $paths[] = ($this->makeReport)($dossier)->path;
    }
    ($this->makeArchive)($dossier);
    ($this->makeReport)($dossier, ['document_type' => 'free', 'name' => 'Facture privée']);
    ($this->makeReport)($dossier, ['client_visible' => false, 'delegataire_visible' => false]);
    $otherLot = ($this->makeDossier)('LOT 2990');
    ($this->makeReport)($otherLot);
    $this->actingAs($this->actor)->get(route('client.dossiers'))->assertOk()->assertSee('Télécharger les rapports d’un lot');
    $export = ($this->startExport)();
    Queue::assertPushed(BuildLotReportExport::class, fn ($job) => $job->exportId === $export->id);
    expect($export->status)->toBe('queued');
    expect(app(LotReportExports::class)->build($export->id))->toBeTrue();
    expect($export->fresh()->status)->toBe('ready')->and($export->fresh()->file_count)->toBe(28);
    $response = $this->get(route('client.report-exports.download', $export))->assertOk()->assertHeader('Content-Type', 'application/zip');
    $bytes = $response->streamedContent();
    Storage::disk('local')->put('inspection.zip', $bytes);
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path('inspection.zip')))->toBeTrue()->and($zip->numFiles)->toBe(29);
    expect($zip->getNameIndex(0))->toStartWith('S - ARCH-001')->and($zip->getNameIndex(1))->toStartWith('NS - REF-001');
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    expect(count(array_unique($names)))->toBe(29)->and(implode('\n', $names))->not->toContain('Facture');
    $zip->close();
    foreach ($paths as $path) {
        Storage::disk('local')->assertExists($path);
    }
    expect($dossier->documents()->first()->name)->toBe('Rapport de contrôle - REF-001');
    $json = $this->getJson(route('client.report-exports.index'))->assertOk()->json('exports.0');
    expect($json['file_count'])->toBe(28)->and(json_encode($json))->not->toContain('crm/dossiers', 'collected/', 'fingerprint');
    $this->get(route('client.report-exports.download', $export))->assertHeader('X-Content-Type-Options', 'nosniff');
})->with(['client', 'delegataire']);

it('includes archived legacy overrides but never hidden deleted private or forbidden dossier reports', function () {
    $dossier = ($this->makeDossier)();
    ($this->makeReport)($dossier)->delete();
    ($this->makeArchive)($dossier, 100, ['is_private' => true], ['client_visible_override' => true]);
    ($this->makeArchive)($dossier, 101, [], ['hidden_at' => now()]);
    ($this->makeArchive)($dossier, 102, [], ['client_visible_override' => false]);
    $hidden = ($this->makeDossier)();
    ($this->makeReport)($hidden);
    $hidden->operations()->first()->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    $deleted = ($this->makeDossier)();
    ($this->makeReport)($deleted);
    $deleted->delete();
    $export = ($this->startExport)();
    app(LotReportExports::class)->build($export->id);
    expect($export->fresh()->file_count)->toBe(1)->and($export->fresh()->status)->toBe('ready');
});

it('requires owner active organisation document rights and unchanged categories at download time', function (string $revocation) {
    $dossier = ($this->makeDossier)();
    $document = ($this->makeReport)($dossier);
    $export = ($this->startExport)();
    app(LotReportExports::class)->build($export->id);
    match ($revocation) {
        'sharing' => $document->update(['client_visible' => false]),
        'deleted_document' => $document->delete(),
        'deleted_dossier' => $dossier->delete(),
        'assignment' => $dossier->update(['client_id' => null, 'delegataire_id' => null]),
        'categories' => $this->organisation->update(['portal_categories' => []]),
        'outcome' => $document->update(['control_outcome' => 'satisfactory']),
        'organisation' => $this->organisation->update(['is_active' => false]),
        'account' => $this->actor->forceFill(['portal_enabled' => false])->save(),
        'password' => $this->actor->update(['must_change_password' => true]),
        'permission' => $this->actor->forceFill(['portal_permissions' => ['documents' => false]])->save(),
    };
    $response = $this->actingAs($this->actor->fresh())->getJson(route('client.report-exports.download', $export));
    expect($response->getStatusCode())->toBeIn([302, 403, 409]);
})->with(['sharing', 'deleted_document', 'deleted_dossier', 'assignment', 'categories', 'outcome', 'organisation', 'account', 'password', 'permission']);

it('does not expose another accounts export even in the same organisation', function () {
    ($this->makeReport)(($this->makeDossier)());
    $export = ($this->startExport)();
    app(LotReportExports::class)->build($export->id);
    $other = $this->actor->replicate();
    $other->email = 'other@example.test';
    $other->save();
    $this->actingAs($other)->getJson(route('client.report-exports.index'))->assertExactJson(['exports' => []]);
    $this->getJson(route('client.report-exports.download', $export))->assertNotFound();
    $this->actingAs(User::factory()->create(['admin' => true, 'role' => 0]))
        ->getJson(route('client.report-exports.download', $export))->assertForbidden();
});

it('rechecks permissions before processing and refuses changed contents before publishing', function () {
    $dossier = ($this->makeDossier)();
    ($this->makeReport)($dossier);
    $export = ($this->startExport)();
    $this->actor->forceFill(['portal_enabled' => false])->save();
    app(LotReportExports::class)->build($export->id);
    expect($export->fresh()->status)->toBe('failed');
    Storage::disk('local')->assertMissing('report-exports/'.$export->id.'/reports.zip');
});

it('reuses an in-flight request, serializes workers, and never rebuilds a completed archive', function () {
    ($this->makeReport)(($this->makeDossier)());
    ($this->makeDossier)('LOT OTHER');
    $export = ($this->startExport)();
    $this->postJson(route('client.report-exports.store'), ['lot' => 'LOT 299'])->assertStatus(202)->assertJsonPath('id', $export->id);
    Queue::assertPushed(BuildLotReportExport::class, 1);
    $this->postJson(route('client.report-exports.store'), ['lot' => 'LOT OTHER'])->assertUnprocessable();
    $lock = app(ReportExportStorage::class)->lock();
    expect(app(LotReportExports::class)->build($export->id))->toBeFalse()->and($export->fresh()->status)->toBe('queued');
    flock($lock, LOCK_UN);
    fclose($lock);
    app(LotReportExports::class)->build($export->id);
    $hash = hash_file('sha256', app(ReportExportStorage::class)->path($export->id, 'reports.zip'));
    app(LotReportExports::class)->build($export->id);
    expect(hash_file('sha256', app(ReportExportStorage::class)->path($export->id, 'reports.zip')))->toBe($hash);
});

it('expires private archives and cleans orphaned partial files without touching source reports', function () {
    $document = ($this->makeReport)(($this->makeDossier)());
    $export = ($this->startExport)();
    app(LotReportExports::class)->build($export->id);
    $orphan = (string) Str::uuid();
    $temporary = app(ReportExportStorage::class)->path($orphan, 'reports.zip.part');
    file_put_contents($temporary, 'orphan');
    touch(dirname($temporary), now()->subDay()->timestamp);
    $this->travel(5)->hours();
    $this->getJson(route('client.report-exports.download', $export))->assertStatus(410);
    $this->artisan('reports:prune')->assertSuccessful();
    expect($export->fresh()->status)->toBe('expired')->and(is_file($temporary))->toBeFalse();
    Storage::disk('local')->assertMissing('report-exports/'.$export->id.'/reports.zip');
    Storage::disk('local')->assertExists($document->path);
});

it('clearly reports missing files but does not silently publish an empty archive', function (bool $hasAvailable) {
    $dossier = ($this->makeDossier)();
    $missing = ($this->makeReport)($dossier);
    Storage::disk('local')->delete($missing->path);
    if ($hasAvailable) {
        ($this->makeReport)($dossier);
    }
    $export = ($this->startExport)();
    app(LotReportExports::class)->build($export->id);
    if ($hasAvailable) {
        expect($export->fresh()->status)->toBe('ready')->and($export->fresh()->unavailable_count)->toBe(1);
        $this->getJson(route('client.report-exports.index'))->assertJsonPath('exports.0.unavailable_count', 1);
    } else {
        expect($export->fresh()->status)->toBe('failed')->and($export->fresh()->failure_message)->toContain('Aucun rapport');
    }
})->with([true, false]);

it('bounds source volume archive storage reserve report count and pending demand', function (string $limit) {
    ($this->makeReport)(($this->makeDossier)());
    if ($limit === 'pending') {
        config(['report_exports.max_pending' => 0]);
        $this->actingAs($this->actor)->postJson(route('client.report-exports.store'), ['lot' => 'LOT 299'])->assertUnprocessable();
        expect(LotReportExport::count())->toBe(0);

        return;
    }
    config(['report_exports.'.match ($limit) {
        'source' => 'max_source_bytes', 'storage' => 'max_storage_bytes', 'reserve' => 'reserve_bytes', default => 'max_reports',
    } => $limit === 'reserve' ? PHP_INT_MAX : 0]);
    $export = ($this->startExport)();
    app(LotReportExports::class)->build($export->id);
    expect($export->fresh()->status)->toBe('failed');
    Storage::disk('local')->assertMissing('report-exports/'.$export->id.'/reports.zip');
})->with(['source', 'storage', 'reserve', 'reports', 'pending']);

it('rejects synchronous preparation malformed inputs unsafe source paths and PDF impostors', function (string $case) {
    $dossier = ($this->makeDossier)();
    $document = ($this->makeReport)($dossier);
    $this->actingAs($this->actor);
    if ($case === 'sync') {
        config(['queue.default' => 'sync']);
        $this->postJson(route('client.report-exports.store'), ['lot' => 'LOT 299'])->assertUnprocessable();
    } elseif ($case === 'input') {
        $this->postJson(route('client.report-exports.store'), ['lot' => ['LOT 299']])->assertUnprocessable();
        $this->getJson(route('client.report-exports.lots', ['q' => str_repeat('X', 257)]))->assertUnprocessable();
    } else {
        if ($case === 'traversal') {
            $document->update(['path' => 'crm/dossiers/'.$dossier->id.'/../../outside.pdf']);
        }
        if ($case === 'symlink') {
            Storage::disk('local')->put('outside.pdf', '%PDF-1.4 secret');
            Storage::disk('local')->delete($document->path);
            symlink(Storage::disk('local')->path('outside.pdf'), Storage::disk('local')->path($document->path));
        }
        if ($case === 'impostor') {
            Storage::disk('local')->put($document->path, '<script>not a PDF</script>');
        }
        $export = ($this->startExport)();
        app(LotReportExports::class)->build($export->id);
        expect($export->fresh()->status)->toBe('failed');
        Storage::disk('local')->assertMissing('report-exports/'.$export->id.'/reports.zip');
    }
})->with(['sync', 'input', 'traversal', 'symlink', 'impostor']);

it('counts a duplicate archive reference only once even at the report limit', function () {
    $dossier = ($this->makeDossier)();
    ($this->makeArchive)($dossier);
    $record = (array) DB::table('legacy_records')->where('source_id', 100)->first();
    unset($record['id']);
    DB::table('legacy_records')->insert([...$record, 'source_id' => 101]);
    config(['report_exports.max_reports' => 1]);
    $export = ($this->startExport)();
    app(LotReportExports::class)->build($export->id);
    expect($export->fresh()->status)->toBe('ready')->and($export->fresh()->file_count)->toBe(1);
});

it('serializes global queue reservations without blocking the builder or emails', function () {
    ($this->makeReport)(($this->makeDossier)());
    $lock = app(ReportExportStorage::class)->lock('requests');
    try {
        $this->actingAs($this->actor)->postJson(route('client.report-exports.store'), ['lot' => 'LOT 299'])
            ->assertStatus(429)->assertHeader('Retry-After', '2');
        expect(LotReportExport::count())->toBe(0);
        Queue::assertNothingPushed();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $export = ($this->startExport)();
    Queue::assertPushed(BuildLotReportExport::class, fn ($job) => $job->queue === 'report-exports' && $job->exportId === $export->id);
});

it('purges libzip adjacent temporary files and recovers interrupted builds without deleting unknown files', function () {
    $document = ($this->makeReport)(($this->makeDossier)());
    $export = ($this->startExport)();
    $export->update(['status' => 'building']);
    $directory = app(ReportExportStorage::class)->directory($export->id);
    file_put_contents($directory.'/reports.zip.part.abCD32.part', 'partial libzip data');
    file_put_contents($directory.'/reports.zip.part', 'partial');
    file_put_contents($directory.'/unknown.txt', 'keep me');
    app(LotReportExports::class)->prune();
    expect($export->fresh()->status)->toBe('failed')
        ->and(is_file($directory.'/reports.zip.part.abCD32.part'))->toBeFalse()
        ->and(is_file($directory.'/reports.zip.part'))->toBeFalse()
        ->and(file_get_contents($directory.'/unknown.txt'))->toBe('keep me');
    Storage::disk('local')->assertExists($document->path);
    $replacement = ($this->startExport)();
    expect($replacement->id)->not->toBe($export->id);
});

it('exports a native linked lot and keeps delegataire-specific archive revocations', function () {
    $this->organisation->update(['kind' => 'delegataire']);
    $dossier = ($this->makeDossier)();
    $service = Service::create(['name' => 'Inspection', 'type' => 'COFRAC', 'average_duration_minutes' => 60]);
    $operation = $dossier->operations()->first();
    $operation->update(['service_id' => $service->id]);
    $technician = User::factory()->create(['role' => 2, 'admin' => false]);
    $appointment = Appointment::forceCreate(['dossier_operation_id' => $operation->id, 'service_id' => $service->id,
        'technician_id' => $technician->id, 'created_by' => $technician->id, 'customer_first_name' => 'Client',
        'customer_last_name' => 'Fictif', 'customer_phone' => '0600000000', 'address' => 'Rue Test',
        'starts_at' => '2026-11-06 10:00:00', 'ends_at' => '2026-11-06 11:00:00', 'duration_minutes' => 60,
        'status' => Appointment::STATUS_SCHEDULED]);
    $lot = Lot::create(['name' => 'LOT NATIF', 'type' => Lot::TYPE_FULL_CONTROL]);
    LotAppointment::create(['lot_id' => $lot->id, 'appointment_id' => $appointment->id, 'customer_name' => 'Client Fictif']);
    ($this->makeReport)($dossier);
    ($this->makeArchive)($dossier, 100, [], ['client_visible_override' => true, 'delegataire_visible_override' => false]);
    $this->actingAs($this->actor)->getJson(route('client.report-exports.lots', ['q' => 'NATIF']))
        ->assertExactJson(['lots' => ['LOT NATIF']]);
    $export = ($this->startExport)('LOT NATIF');
    app(LotReportExports::class)->build($export->id);
    expect($export->fresh()->status)->toBe('ready')->and($export->fresh()->file_count)->toBe(1);
});
