<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointmentDocument;
use App\Models\Service;
use App\Models\User;
use App\Services\Tech\TechnicianWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(10, 0));
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'must_change_password' => false]);
    $this->otherTech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Visite BAT EQ 127', 'average_duration_minutes' => 60]);
    $this->dossier = Dossier::create(['reference' => 'TECH-MOBILE', 'company_name' => 'Bénéficiaire mobile', 'address' => '18 rue des Lilas', 'city' => 'Paris']);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->makeAppointment = function (User $technician, array $attributes = []): Appointment {
        return Appointment::forceCreate([...[
            'service_id' => $this->service->id, 'technician_id' => $technician->id, 'created_by' => $technician->id,
            'dossier_operation_id' => $this->operation->id, 'customer_first_name' => 'Marie', 'customer_last_name' => 'Martin',
            'customer_phone' => '612345678', 'address' => '18 rue des Lilas, 75012 Paris', 'status' => 'scheduled',
            'starts_at' => '2026-10-27 09:30:00', 'ends_at' => '2026-10-27 10:30:00', 'duration_minutes' => 60,
        ], ...$attributes]);
    };
    $this->appointment = ($this->makeAppointment)($this->tech);
    $this->native = $this->dossier->documents()->create(['name' => 'Plan technique confidentiel', 'path' => 'crm/dossiers/plan.pdf',
        'mime_type' => 'application/pdf', 'size' => 32, 'client_visible' => false, 'delegataire_visible' => false]);
    Storage::disk('local')->put($this->native->path, "%PDF-1.4\nTest\n%%EOF\n");
    $this->lotRow = Lot::create(['name' => 'Lot mobile'])->appointments()->create(['appointment_id' => $this->appointment->id, 'customer_name' => 'Marie Martin']);
    $this->lotFile = LotAppointmentDocument::create(['lot_appointment_id' => $this->lotRow->id, 'disk' => 'local',
        'name' => 'Photo lot', 'original_name' => 'lot.pdf', 'path' => 'lot-appointment-documents/'.$this->lotRow->id.'/lot.pdf',
        'mime' => 'application/pdf', 'size' => 32, 'is_private' => true]);
    Storage::disk('local')->put($this->lotFile->path, "%PDF-1.4\nLot\n%%EOF\n");
    $disk = Storage::disk('local');
    $disk->put('collected/history.pdf', "%PDF-1.4\nArchive\n%%EOF\n");
    $disk->put('selection.list0', "history.pdf\0");
    config(['crm.collected_documents_root' => $disk->path('collected'), 'crm.collected_documents_manifest' => $disk->path('selection.list0')]);
    DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 123, 'dossier_id' => $this->dossier->id,
        'payload' => json_encode(['name' => 'Rapport historique', 'path' => 'history.pdf', 'is_private' => true])]);
    $this->actingAs($this->tech);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('renders the daily mobile agenda with direct documents phone and itinerary actions without recalculating routes', function () {
    ($this->makeAppointment)($this->otherTech, ['customer_first_name' => 'AUTRE TECH']);
    $this->get(route('tech.planning'))->assertOk()->assertSee('Documents du dossier')->assertSee('Marie Martin')
        ->assertDontSee('AUTRE TECH')->assertSee('tel:+33612345678', false)
        ->assertSee(route('tech.appointments.show', $this->appointment).'#tech-documents', false)
        ->assertViewHas('dayCards', fn ($cards) => $cards->count() === 1 && $cards->first()['ongoing']);
    expect(DB::table('technician_daily_route_metrics')->count())->toBe(0);
});

it('supports date navigation an empty day and a visit spanning midnight', function () {
    ($this->makeAppointment)($this->tech, ['starts_at' => '2026-10-28 23:30', 'ends_at' => '2026-10-29 00:30']);
    $this->get(route('tech.planning', ['date' => '2026-10-29']))->assertOk()->assertViewHas('dayCards', fn ($cards) => $cards->count() === 1);
    $this->get(route('tech.planning', ['date' => '2026-11-01']))->assertOk()->assertSee('Aucune intervention ce jour');
    $this->getJson(route('tech.planning', ['date' => 'bad']))->assertUnprocessable();
});

it('shows owned dossier archive and lot documents without exposing management actions or storage paths', function () {
    $this->get(route('tech.appointments.show', $this->appointment))->assertOk()->assertViewHas('documentCount', 3)
        ->assertSee('Plan technique confidentiel')->assertSee('Rapport historique')->assertSee('Photo lot')
        ->assertSee('data-document-open', false)->assertDontSee('Supprimer')->assertDontSee('Modifier l’accès')
        ->assertDontSee($this->native->path)->assertDontSee('history.pdf')->assertDontSee('lot-appointment-documents/');
    expect(Gate::forUser($this->tech)->allows('view', $this->dossier))->toBeFalse()
        ->and(Gate::forUser($this->tech)->allows('viewDocuments', $this->dossier))->toBeTrue()
        ->and(Gate::forUser($this->tech)->allows('update', $this->dossier))->toBeFalse();
    $this->get(route('v2.dossiers.index'))->assertForbidden();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertForbidden();
    $this->post(route('v2.dossiers.documents.store', $this->dossier))->assertForbidden();
});

it('searches all three file sources and paginates the metadata without loading file contents', function () {
    foreach (range(1, 35) as $index) {
        $this->dossier->documents()->create(['name' => 'Plan '.$index, 'path' => 'not-read-'.$index.'.pdf', 'mime_type' => 'application/pdf', 'size' => 32]);
    }
    $this->get(route('tech.appointments.show', [$this->appointment, 'document_q' => 'historique']))->assertOk()
        ->assertViewHas('documentCount', 1)->assertSee('Rapport historique')->assertDontSee('Plan technique confidentiel')->assertDontSee('Photo lot');
    $this->get(route('tech.appointments.show', [$this->appointment, 'document_q' => 'Plan', 'documents_page' => 2]))->assertOk()
        ->assertViewHas('documentGroups', fn ($groups) => $groups['Documents du dossier']->total() === 36 && $groups['Documents du dossier']->count() === 6);
    $this->getJson(route('tech.appointments.show', [$this->appointment, 'document_q' => str_repeat('a', 121)]))->assertUnprocessable();
});

it('downloads and previews each file source privately through the assigned appointment', function (string $kind) {
    $id = match ($kind) {
        'dossier' => $this->native->id, 'archive' => 123, 'lot' => $this->lotFile->id
    };
    $url = route('tech.documents.download', [$this->appointment, $kind, $id]);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
    $response = $this->get($url.'?preview=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline');
    $this->head($url.'?preview=1')->assertOk();
})->with(['dossier', 'archive', 'lot']);

it('denies every direct file link after reassignment and prevents cross appointment access', function (string $kind) {
    $id = match ($kind) {
        'dossier' => $this->native->id, 'archive' => 123, 'lot' => $this->lotFile->id
    };
    $url = route('tech.documents.download', [$this->appointment, $kind, $id]);
    $this->actingAs($this->otherTech)->get($url)->assertForbidden();
    $this->get(route('tech.appointments.show', $this->appointment))->assertForbidden();
    $this->appointment->update(['technician_id' => $this->otherTech->id]);
    $this->actingAs($this->tech)->get($url)->assertForbidden();
})->with(['dossier', 'archive', 'lot']);

it('refuses removed attachments wrong owners deleted dossiers and deleted appointments', function () {
    $url = route('tech.documents.download', [$this->appointment, 'dossier', $this->native->id]);
    $other = Dossier::create(['reference' => 'FOREIGN', 'address' => 'Rue', 'city' => 'Paris']);
    $foreignFile = $other->documents()->create(['name' => 'SECRET', 'path' => 'foreign.pdf', 'mime_type' => 'application/pdf', 'size' => 32]);
    $this->get(route('tech.documents.download', [$this->appointment, 'dossier', $foreignFile->id]))->assertNotFound();
    $this->native->delete();
    $this->get($url)->assertNotFound();
    DB::table('legacy_records')->where('source_id', 123)->update(['hidden_at' => now()]);
    $this->get(route('tech.documents.download', [$this->appointment, 'archive', 123]))->assertNotFound();
    $this->dossier->delete();
    $this->get(route('tech.appointments.show', $this->appointment))->assertForbidden();
    $this->get(route('tech.planning'))->assertViewHas('dayCards', fn ($cards) => $cards->isEmpty());
    $this->appointment->delete();
    $this->get(route('tech.appointments.show', $this->appointment))->assertNotFound();
});

it('does not grant access to inactive accounts password changes external users or disabled V2', function () {
    $url = route('tech.appointments.show', $this->appointment);
    $this->tech->update(['must_change_password' => true]);
    $this->get($url)->assertForbidden();
    $this->tech->update(['must_change_password' => false]);
    $this->tech->delete();
    $this->get($url)->assertForbidden();
    $this->get(route('tech.planning'))->assertForbidden();
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'EXTERNAL', 'kind' => 'client', 'name' => 'Client']);
    $external = User::factory()->create(['role' => 3, 'admin' => false, 'external_client_id' => $organisation->id]);
    $this->actingAs($external)->get($url)->assertForbidden();
    config(['crm.v2_enabled' => false]);
    $this->actingAs($this->otherTech)->get($url)->assertNotFound();
});

it('supports a lot appointment without a native dossier while enforcing its ownership', function () {
    $this->appointment->forceFill(['dossier_operation_id' => null])->save();
    $this->get(route('tech.appointments.show', $this->appointment))->assertOk()->assertViewHas('documentCount', 1)->assertSee('Photo lot');
    $this->get(route('tech.documents.download', [$this->appointment, 'lot', $this->lotFile->id]))->assertOk();
    $otherAppointment = ($this->makeAppointment)($this->tech, ['dossier_operation_id' => null]);
    $this->get(route('tech.documents.download', [$otherAppointment, 'lot', $this->lotFile->id]))->assertNotFound();
    $this->get(route('tech.documents.download', [$this->appointment, 'archive', 123]))->assertNotFound();
});

it('scopes historical and lot file ids to the dossier even for a technician assigned to both', function () {
    $other = Dossier::create(['reference' => 'OTHER-TECH-DOSSIER', 'address' => 'Rue', 'city' => 'Paris']);
    $operation = $other->operations()->create(['service_id' => $this->service->id]);
    $appointment = ($this->makeAppointment)($this->tech, ['dossier_operation_id' => $operation->id]);
    $row = Lot::create(['name' => 'Other lot'])->appointments()->create(['appointment_id' => $appointment->id, 'customer_name' => 'Other']);
    $file = LotAppointmentDocument::create(['lot_appointment_id' => $row->id, 'disk' => 'local', 'name' => 'Other',
        'original_name' => 'foreign.pdf', 'path' => 'lot-appointment-documents/'.$row->id.'/foreign.pdf', 'mime' => 'application/pdf', 'size' => 32]);
    DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 456, 'dossier_id' => $other->id,
        'payload' => json_encode(['name' => 'Other', 'path' => 'history.pdf'])]);
    $this->get(route('tech.documents.download', [$this->appointment, 'lot', $file->id]))->assertNotFound();
    $this->get(route('tech.documents.download', [$this->appointment, 'archive', 456]))->assertNotFound();
});

it('never renders active content inline despite a PDF filename or declared mime', function (string $kind) {
    $path = match ($kind) {
        'dossier' => $this->native->path, 'archive' => 'collected/history.pdf', 'lot' => $this->lotFile->path
    };
    $id = match ($kind) {
        'dossier' => $this->native->id, 'archive' => 123, 'lot' => $this->lotFile->id
    };
    Storage::disk('local')->put($path, '<!doctype html><html><body><script>alert(1)</script></body></html>');
    $response = $this->get(route('tech.documents.download', [$this->appointment, $kind, $id]).'?preview=1')->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment');
})->with(['dossier', 'archive', 'lot']);

it('adds a private document entry point to calendar events without serializing all dossier files', function () {
    $this->postJson(route('tech.planning.events'), ['start' => '2026-10-27', 'end' => '2026-10-28'])->assertOk()
        ->assertJsonPath('events.0.extendedProps.detail_url', route('tech.appointments.show', $this->appointment))
        ->assertJsonPath('events.0.extendedProps.phone_href', 'tel:+33612345678')
        ->assertJsonPath('events.0.extendedProps.documents', []);
});

it('keeps daily listing query volume constant when adding appointments', function () {
    $workspace = app(TechnicianWorkspace::class);
    $queries = function () use ($workspace): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $workspace->overview($this->tech, '2026-10-27');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $before = $queries();
    foreach (range(1, 20) as $index) {
        ($this->makeAppointment)($this->tech, ['customer_last_name' => 'Visite '.$index]);
    }
    expect($queries())->toBe($before);
});
