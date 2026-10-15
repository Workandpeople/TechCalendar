<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentDocumentSerializer;
use App\Services\Crm\CollectedDossierDocuments;
use App\Services\Crm\DossierBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->collection = sys_get_temp_dir().'/techcalendar-documents-test-'.bin2hex(random_bytes(8));
    mkdir($this->collection, 0700);
    mkdir($this->collection.'/files', 0700);
    file_put_contents($this->collection.'/files/report.pdf', "%PDF-1.4\nTest\n%%EOF\n");
    file_put_contents($this->collection.'/files/empty.pdf', '');
    file_put_contents($this->collection.'/files/unselected.pdf', '%PDF not selected');
    file_put_contents($this->collection.'/documents.list0', "report.pdf\0empty.pdf\0missing.pdf\0");
    config(['crm.v2_enabled' => true, 'crm.collected_documents_root' => $this->collection.'/files',
        'crm.collected_documents_manifest' => $this->collection.'/documents.list0']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->dossier = Dossier::create(['reference' => 'COLLECTE-TEST', 'address' => 'Rue de test', 'city' => 'Paris']);
    DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 123,
        'dossier_id' => $this->dossier->id, 'payload' => json_encode(['path' => 'report.pdf', 'name' => 'Rapport test.pdf', 'is_private' => true])]);
    $this->url = route('v2.dossiers.collected-documents.download', [$this->dossier, 123]);
});

afterEach(function () {
    File::deleteDirectory($this->collection);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('downloads an owned manifest file privately without duplicating it or creating metadata', function () {
    $before = hash_file('sha256', $this->collection.'/files/report.pdf');
    $response = $this->actingAs($this->actor)->get($this->url)->assertOk()
        ->assertDownload('Rapport test.pdf')->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($response->baseResponse->getFile()->getPathname())->toBe(realpath($this->collection.'/files/report.pdf'))
        ->and(hash_file('sha256', $this->collection.'/files/report.pdf'))->toBe($before)
        ->and(DB::table('dossier_documents')->count())->toBe(0)
        ->and(DB::table('legacy_records')->count())->toBe(1);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee($this->url, false);
});

it('requires authentication and keeps technician and disabled-v2 access closed', function () {
    $this->get($this->url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 2, 'admin' => false]))->get($this->url)->assertForbidden();
    config(['crm.v2_enabled' => false]);
    $this->actingAs($this->actor)->get($this->url)->assertNotFound();
});

it('scopes both the source table and document identifier to its dossier', function () {
    $other = Dossier::create(['reference' => 'OTHER', 'address' => 'Rue', 'city' => 'Paris']);
    $this->actingAs($this->actor)->get(route('v2.dossiers.collected-documents.download', [$other, 123]))->assertNotFound();
    DB::table('legacy_records')->where('source_id', 123)->update(['source_table' => 'dossiers']);
    $this->get($this->url)->assertNotFound();
});

it('refuses missing empty unsafe executable and unselected files', function (string $path) {
    DB::table('legacy_records')->where('source_id', 123)->update(['payload' => json_encode(['path' => $path])]);
    file_put_contents($this->collection.'/documents.list0', $path === 'unselected.pdf' ? "report.pdf\0" : $path."\0");
    $this->actingAs($this->actor)->get($this->url)->assertNotFound();
    expect(app(CollectedDossierDocuments::class)->forDossiers($this->actor, collect([$this->dossier])))->toBe([]);
})->with(['empty.pdf', 'missing.pdf', 'unselected.pdf', '../outside.pdf', '/tmp/report.pdf', '.hidden.pdf',
    'folder//report.pdf', 'folder\\report.pdf', 'https://example.test/report.pdf', 'shell.php', 'shell.php8', 'image.svg']);

it('refuses symlinks even when the target remains inside the collection', function () {
    symlink($this->collection.'/files/report.pdf', $this->collection.'/files/link.pdf');
    file_put_contents($this->collection.'/documents.list0', "link.pdf\0");
    DB::table('legacy_records')->where('source_id', 123)->update(['payload' => json_encode(['path' => 'link.pdf'])]);
    $this->actingAs($this->actor)->get($this->url)->assertNotFound();
});

it('fails closed when the manifest is unavailable or incomplete', function () {
    file_put_contents($this->collection.'/documents.list0', 'report.pdf');
    $this->actingAs($this->actor)->get($this->url)->assertNotFound();
    unlink($this->collection.'/documents.list0');
    $this->get($this->url)->assertNotFound();
});

it('deduplicates shared files within a dossier and reuses them in booking and planning', function () {
    DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 124,
        'dossier_id' => $this->dossier->id, 'payload' => json_encode(['path' => 'report.pdf', 'name' => 'Rapport test.pdf'])]);
    $service = Service::create(['name' => 'Test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $operation = $this->dossier->operations()->create(['service_id' => $service->id]);
    $operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->actingAs($this->actor);
    $booking = app(DossierBooking::class);
    $pending = $booking->pending($this->actor);
    expect($pending->first()['documents'])->toHaveCount(1)
        ->and($pending->first()['documents'][0]['url'])->toBe(route('v2.dossiers.collected-documents.download', [$this->dossier, 124]))
        ->and($booking->find($this->actor, 'dossier-'.$operation->id, false)['documents'])->toHaveCount(1);

    $appointment = Appointment::create(['dossier_operation_id' => $operation->id, 'technician_id' => $this->actor->id,
        'service_id' => $service->id, 'created_by' => $this->actor->id, 'customer_first_name' => 'Test', 'customer_last_name' => 'Test',
        'customer_phone' => '', 'address' => 'Rue', 'latitude' => 48.8, 'longitude' => 2.3,
        'starts_at' => now(), 'ends_at' => now()->addHour(), 'duration_minutes' => 60, 'status' => 'scheduled']);
    $appointment->forceFill(['dossier_operation_id' => $operation->id])->save();
    $documents = app(AppointmentDocumentSerializer::class)->forAppointment($appointment);
    expect($documents)->toHaveCount(1)->and($documents[0]['id'])->toBe('collected-124')
        ->and($documents[0])->not->toHaveKey('path');
});
