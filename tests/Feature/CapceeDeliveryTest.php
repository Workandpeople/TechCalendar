<?php

use App\Jobs\SendCapceeReport;
use App\Models\CapceeDelivery;
use App\Models\CapceeReport;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Capcee\CapceeApiException;
use App\Services\Capcee\CapceeClient;
use App\Services\Capcee\CapceeDeliveryProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'capcee.enabled' => true, 'capcee.environment' => 'uat2',
        'capcee.client_id' => 'test-client', 'capcee.client_secret' => 'secret-test',
        'capcee.queue_connection' => 'database', 'capcee.queue' => 'default', 'cache.default' => 'array',
        'queue.connections.database.retry_after' => 1800]);
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('local');
    Cache::flush();
    $this->manager = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->dossier = Dossier::create(['reference' => 'CAPCEE-TEST', 'company_name' => 'Client test', 'address' => '10 rue du Test', 'city' => 'Lyon']);
    $service = Service::create(['name' => 'BAR EN 101', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->operation = $this->dossier->operations()->create(['service_id' => $service->id]);
    $this->pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    $this->data = ['submission_token' => (string) Str::uuid(), 'emmy_reference' => 'EMMY-42', 'object_type' => 'Operation__c',
        'inspection_type' => 'site', 'issued_on' => '2026-10-14', 'inspected_on' => '2026-10-13',
        'conclusion' => 'Satisfaisant', 'send_email' => '0', 'validated_report' => '1'];
    $this->submit = function (array $changes = [], ?CapceeReport $report = null) {
        return $this->actingAs($this->manager)->post(
            route($report ? 'v2.capcee.versions.store' : 'v2.capcee.store', array_filter([$this->dossier, $this->operation, $report])),
            [...$this->data, 'report_file' => UploadedFile::fake()->createWithContent('rapport.pdf', $this->pdf), ...$changes],
        );
    };
    $this->client = function () {
        $client = Mockery::mock(CapceeClient::class);
        $client->shouldReceive('assertConfigured')->andReturnNull()->byDefault();
        $this->app->instance(CapceeClient::class, $client);

        return $client;
    };
    $this->process = fn ($delivery) => app(CapceeDeliveryProcessor::class)->process($delivery->id);
    $this->targetId = 'a0E5q000000NYbnEAG';
    $this->versionId = '0681x000001s2hHAAQ';
    $this->documentId = '0691x000001rwSwAAI';
    $this->linkId = '06A1x000001rwSwAAI';
    $this->prepareClient = function ($client): void {
        $client->shouldReceive('resolveTarget')->once()->andReturn(['id' => $this->targetId]);
        $client->shouldReceive('findReport')->once()->andReturnNull();
    };
});

it('persists an immutable private report and queues only its identifier without a web API call', function () {
    ($this->submit)()->assertRedirect(route('v2.dossiers.show', $this->dossier).'#capcee');
    $delivery = CapceeDelivery::sole();
    expect($delivery->status)->toBe('queued')->and($delivery->revision)->toBe(1)
        ->and($delivery->sha256)->toBe(hash('sha256', $this->pdf))
        ->and($delivery->metadata['send_email'])->toBeFalse()
        ->and($delivery->report->reference)->toStartWith('TC-')
        ->and($delivery->report->environment)->toBe('uat2');
    Storage::disk('local')->assertExists($delivery->path);
    Queue::assertPushed(SendCapceeReport::class, fn ($job) => $job->deliveryId === $delivery->id && $job->connection === 'database' && ! str_contains(serialize($job), '%PDF-'));
    Http::assertNothingSent();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Rapports CAPCEE')->assertSee('En attente du worker');
    $this->getJson(route('v2.capcee.status', $this->dossier))->assertOk()->assertJsonPath('active', true);
    $this->get(route('v2.capcee.download', [$this->dossier, $delivery]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('deduplicates repeated forms and refuses a second first report for the same inspection', function () {
    ($this->submit)()->assertSessionHasNoErrors();
    $delivery = CapceeDelivery::sole();
    // MySQL JSON storage can reorder keys, unlike SQLite's textual representation.
    $delivery->update(['metadata' => array_reverse($delivery->metadata, true)]);
    ($this->submit)()->assertSessionHasNoErrors();
    expect(CapceeDelivery::count())->toBe(1)->and(CapceeReport::count())->toBe(1)->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    ($this->submit)(['submission_token' => (string) Str::uuid()])->assertSessionHasErrors('inspection_type');
    ($this->submit)(['emmy_reference' => 'WRONG'])->assertSessionHasErrors('submission_token');
    expect(CapceeDelivery::count())->toBe(1);
    Http::assertNothingSent();
});

it('rejects incomplete or unsafe report inputs', function (array $changes, string $field) {
    ($this->submit)($changes)->assertSessionHasErrors($field);
    expect(CapceeDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    [['validated_report' => '0'], 'validated_report'],
    [['emmy_reference' => ''], 'emmy_reference'],
    [['inspected_on' => '2026-10-15'], 'inspected_on'],
    [['conclusion' => 'wrong'], 'conclusion'],
    [['send_email' => null], 'send_email'],
    [['object_type' => 'Contact'], 'object_type'],
    [['report_file' => 'not-an-upload'], 'report_file'],
]);

it('rejects disguised and oversized PDFs', function () {
    ($this->submit)(['report_file' => UploadedFile::fake()->createWithContent('rapport.pdf', '<script>bad()</script>')])->assertSessionHasErrors('report_file');
    ($this->submit)(['report_file' => UploadedFile::fake()->create('rapport.pdf', 35000, 'application/pdf')])->assertSessionHasErrors('report_file');
    expect(CapceeDelivery::count())->toBe(0);
});

it('allows managers to transmit but restricts planners to reading and excludes technicians', function () {
    ($this->submit)()->assertSessionHasNoErrors();
    $delivery = CapceeDelivery::sole();
    $this->manager = User::factory()->create(['role' => 1, 'admin' => false]);
    ($this->submit)()->assertForbidden();
    $this->getJson(route('v2.capcee.status', $this->dossier))->assertOk();
    $this->get(route('v2.capcee.create', [$this->dossier, $this->operation]))->assertForbidden();
    $this->get(route('v2.capcee.download', [$this->dossier, $delivery]))->assertOk();
    $this->manager = User::factory()->create(['role' => 2, 'admin' => false]);
    ($this->submit)()->assertForbidden();
    $this->get(route('v2.capcee.download', [$this->dossier, $delivery]))->assertForbidden();
});

it('enforces dossier ownership on all nested endpoints', function () {
    ($this->submit)()->assertSessionHasNoErrors();
    $delivery = CapceeDelivery::sole();
    $other = Dossier::create(['reference' => 'OTHER', 'address' => 'Rue test', 'city' => 'Paris']);
    $this->get(route('v2.capcee.create', [$other, $this->operation]))->assertNotFound();
    $this->get(route('v2.capcee.download', [$other, $delivery]))->assertNotFound();
    $this->post(route('v2.capcee.retry', [$other, $delivery]))->assertNotFound();
    Http::assertNothingSent();
});

it('does not transmit when disabled or configured with an unsafe queue', function (array $changes) {
    config($changes);
    ($this->submit)()->assertSessionHasErrors('capcee');
    expect(CapceeDelivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    [['capcee.enabled' => false]], [['capcee.client_secret' => '']], [['capcee.queue_connection' => 'sync']],
    [['queue.connections.database.retry_after' => 90]],
]);

it('runs the documented HTTP cascade and does not repeat a successful delivery', function () {
    ($this->submit)()->assertSessionHasNoErrors();
    $delivery = CapceeDelivery::sole();
    $base = 'https://capcee--uat2.sandbox.my.salesforce.com';
    Http::fake(function ($request) use ($base) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/services/oauth2/token' => Http::response(['access_token' => 'secret-token', 'token_type' => 'Bearer', 'instance_url' => $base]),
            '/services/apexrest/getObjectByClientReference' => Http::response([['entityId' => $this->targetId, 'sObjectType' => 'Operation__c']]),
            '/services/data/v58.0/sobjects/ContentVersion' => Http::response(['success' => true, 'id' => $this->versionId]),
            '/services/data/v58.0/sobjects/ContentDocumentLink' => Http::response(['success' => true, 'id' => $this->linkId]),
            '/services/data/v58.0/query' => str_contains($request['q'], 'WHERE Id =')
                ? Http::response(['totalSize' => 1, 'done' => true, 'records' => [['ContentDocumentId' => $this->documentId]]])
                : Http::response(['totalSize' => 0, 'done' => true, 'records' => []]),
            default => throw new RuntimeException('Unexpected API path'),
        };
    });
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->version_id)->toBe($this->versionId)
        ->and($delivery->fresh()->report->document_id)->toBe($this->documentId);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/ContentVersion')
        && $request['VersionData'] === base64_encode($this->pdf) && $request['StatutEnvoiEmail__c'] === 'NePasEnvoyer'
        && $request['ExternalReference__c'] === 'GC_'.$delivery->report->reference);
    Http::assertSentCount(7);
    ($this->process)($delivery);
    Http::assertSentCount(7);
    $this->getJson(route('v2.capcee.status', $this->dossier))->assertJsonPath('active', false);
});

it('resumes a failed document lookup without uploading the version twice', function () {
    ($this->submit)();
    $delivery = CapceeDelivery::sole();
    $client = ($this->client)();
    ($this->prepareClient)($client);
    $client->shouldReceive('createReportVersion')->once()->andReturn($this->versionId);
    $client->shouldReceive('documentIdForVersion')->once()->andThrow(new CapceeApiException('Lecture temporairement indisponible.', 503, true));
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('queued')->and($delivery->fresh()->stage)->toBe('document')
        ->and($delivery->fresh()->version_id)->toBe($this->versionId);
    $this->travel(61)->seconds();
    $client->shouldReceive('documentIdForVersion')->once()->andReturn($this->documentId);
    $client->shouldReceive('findDocumentLink')->once()->andReturn($this->linkId);
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->attempts)->toBe(2);
});

it('marks uncertain POSTs for review and blocks manual and automatic retries', function () {
    ($this->submit)();
    $delivery = CapceeDelivery::sole();
    $client = ($this->client)();
    ($this->prepareClient)($client);
    $client->shouldReceive('createReportVersion')->once()->andThrow(new CapceeApiException('Résultat inconnu.', outcomeUnknown: true));
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('review');
    ($this->process)($delivery);
    $this->post(route('v2.capcee.retry', [$this->dossier, $delivery]))->assertSessionHasErrors('capcee');
    ($this->submit)(['submission_token' => (string) Str::uuid(), 'reason_for_change' => 'test'], $delivery->report)->assertSessionHasErrors('report_file');
    expect(CapceeDelivery::count())->toBe(1);
});

it('retries a definitive linking failure without recreating the uploaded PDF', function () {
    ($this->submit)();
    $delivery = CapceeDelivery::sole();
    $client = ($this->client)();
    ($this->prepareClient)($client);
    $client->shouldReceive('createReportVersion')->once()->andReturn($this->versionId);
    $client->shouldReceive('documentIdForVersion')->once()->andReturn($this->documentId);
    $client->shouldReceive('findDocumentLink')->twice()->andReturnNull();
    $client->shouldReceive('linkDocument')->once()->andThrow(new CapceeApiException('Droits de liaison refusés.', 403));
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->stage)->toBe('link');
    $this->post(route('v2.capcee.retry', [$this->dossier, $delivery]))->assertSessionHasNoErrors();
    $client->shouldReceive('linkDocument')->once()->andReturn($this->linkId);
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->version_id)->toBe($this->versionId);
});

it('refuses an already existing external reference rather than creating another document', function () {
    ($this->submit)();
    $delivery = CapceeDelivery::sole();
    $client = ($this->client)();
    $client->shouldReceive('resolveTarget')->once()->andReturn(['id' => $this->targetId]);
    $client->shouldReceive('findReport')->once()->andReturn(['version_id' => $this->versionId, 'document_id' => $this->documentId]);
    $client->shouldNotReceive('createReportVersion');
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('review');
});

it('blocks a changed or missing remote document during a revision', function () {
    ($this->submit)();
    $delivery = CapceeDelivery::sole();
    $delivery->report->update(['parent_id' => $this->targetId, 'document_id' => $this->documentId]);
    $delivery->update(['revision' => 2]);
    $client = ($this->client)();
    ($this->prepareClient)($client);
    $client->shouldNotReceive('createReportVersion');
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->last_error)->toContain('absent ou a changé');
});

it('recovers interrupted workers conservatively and never repeats an unacknowledged write', function (string $stage) {
    ($this->submit)();
    $delivery = CapceeDelivery::sole();
    $delivery->update(['status' => 'processing', 'stage' => $stage, 'started_at' => now()->subMinutes(11)]);
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('review');
    Http::assertNothingSent();
})->with(['uploading', 'linking']);

it('prevents concurrent workers and waits until the retry time', function () {
    ($this->submit)();
    $delivery = CapceeDelivery::sole();
    $lock = Cache::lock('capcee:report:'.$delivery->capcee_report_id, 600);
    $lock->get();
    ($this->process)($delivery);
    $lock->release();
    $delivery->update(['next_attempt_at' => now()->addMinutes(5)]);
    ($this->process)($delivery);
    expect($delivery->fresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('rejects a changed environment and a modified local file before upload', function () {
    ($this->submit)();
    $delivery = CapceeDelivery::sole();
    config(['capcee.environment' => 'production']);
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
    config(['capcee.environment' => 'uat2']);
    $this->post(route('v2.capcee.retry', [$this->dossier, $delivery]))->assertSessionHasNoErrors();
    Storage::disk('local')->put($delivery->path, 'changed');
    $client = ($this->client)();
    ($this->prepareClient)($client);
    $client->shouldNotReceive('createReportVersion');
    ($this->process)($delivery);
    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->last_error)->toContain('modifié');
});

it('creates a new version with the same remote document and stable reference', function () {
    ($this->submit)();
    $first = CapceeDelivery::sole();
    $first->report->update(['parent_id' => $this->targetId, 'document_id' => $this->documentId]);
    $first->update(['status' => 'sent', 'stage' => 'done', 'version_id' => $this->versionId, 'document_id' => $this->documentId]);
    $report = $first->report->fresh();
    $changes = ['submission_token' => (string) Str::uuid(), 'reason_for_change' => 'Correction du rapport', 'conclusion' => 'NonSatisfaisant'];
    ($this->submit)([...$changes, 'reason_for_change' => ''], $report)->assertSessionHasErrors('reason_for_change');
    ($this->submit)($changes, $report)->assertSessionHasNoErrors();
    $second = CapceeDelivery::latest('id')->first();
    expect($second->revision)->toBe(2)->and($second->capcee_report_id)->toBe($first->capcee_report_id);
    $client = ($this->client)();
    $client->shouldReceive('resolveTarget')->once()->andReturn(['id' => $this->targetId]);
    $client->shouldReceive('findReport')->once()->andReturn(['version_id' => $this->versionId, 'document_id' => $this->documentId]);
    $client->shouldReceive('createReportVersion')->once()->withArgs(fn ($metadata, $pdf, $parent, $document) => $metadata['report_reference'] === $report->reference
        && $document === $this->documentId && $parent === $this->targetId)->andReturn('0681x000001s2hIAAQ');
    $client->shouldReceive('documentIdForVersion')->once()->andReturn($this->documentId);
    $client->shouldReceive('findDocumentLink')->once()->andReturn($this->linkId);
    ($this->process)($second);
    expect($second->fresh()->status)->toBe('sent')->and(CapceeReport::count())->toBe(1);
});

it('dispatches persisted due deliveries and stale workers without revisiting completed or uncertain ones', function () {
    ($this->submit)();
    Queue::fake();
    $this->artisan('capcee:dispatch')->assertSuccessful();
    Queue::assertPushed(SendCapceeReport::class, 1);
    Queue::fake();
    CapceeDelivery::sole()->update(['status' => 'review']);
    $this->artisan('capcee:dispatch')->assertSuccessful();
    Queue::assertNothingPushed();
});
