<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\LotAppointmentDocument;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierPlanning;
use App\Services\Crm\DossierRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->manager = User::factory()->create(['role' => 0]);
    $this->technician = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->service = Service::create(['name' => 'BAR EN 101', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->dossierData = [
        'reference' => 'DOS-V2-TEST', 'company_name' => 'Client dossier',
        'address' => '10 rue de Lyon', 'city' => 'Lyon', 'service_ids' => [$this->service->id],
    ];
    $this->dossier = app(DossierRegistry::class)->create($this->manager, $this->dossierData);
    $this->operation = $this->dossier->operations()->firstOrFail();
    $this->appointmentData = [
        'service_id' => $this->service->id, 'technician_id' => $this->technician->id, 'created_by' => $this->manager->id,
        'customer_first_name' => 'Marie', 'customer_last_name' => 'DUPONT', 'customer_phone' => '0600000000',
        'address' => '10 rue de Lyon, 69001 Lyon', 'latitude' => 45.76, 'longitude' => 4.83,
        'starts_at' => now()->addDay()->setTime(10, 0), 'ends_at' => now()->addDay()->setTime(11, 0),
        'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED,
        'external_source' => 'coffrac', 'external_reference' => 'source-unique',
        'external_payload' => ['customer_email' => 'client@example.test'],
    ];
    $this->appointment = Appointment::create($this->appointmentData);
    $this->linkUrl = route('v2.dossiers.appointments.store', [$this->dossier, $this->operation]);
    $this->actingAs($this->manager);
});

it('links multiple existing appointments without duplicating or changing booking data', function () {
    $before = $this->appointment->fresh()->getRawOriginal();
    $this->post($this->linkUrl, ['appointment_id' => $this->appointment->id, 'version' => 1])->assertSessionHasNoErrors()->assertRedirect();
    $this->post($this->linkUrl, ['appointment_id' => $this->appointment->id, 'version' => 1])->assertSessionHasNoErrors();
    $second = Appointment::create([...$this->appointmentData, 'external_reference' => 'another']);
    $this->post($this->linkUrl, ['appointment_id' => $second->id, 'version' => 2])->assertSessionHasNoErrors();

    expect($this->operation->appointments()->count())->toBe(2)
        ->and(Appointment::count())->toBe(2)->and(Dossier::count())->toBe(1)
        ->and($this->appointment->fresh()->getRawOriginal())->toBe([...$before, 'dossier_operation_id' => $this->operation->id])
        ->and($this->dossier->fresh()->version)->toBe(3)
        ->and($this->dossier->activities()->where('kind', 'appointment_linked')->count())->toBe(2);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Rendez-vous et lots')
        ->assertSee('RDV #'.$second->id)->assertSee('Voir / modifier dans le planning');
});

it('rejects cross dossier operations incompatible services and missing appointments', function () {
    $other = app(DossierRegistry::class)->create($this->manager, [...$this->dossierData, 'reference' => 'OTHER']);
    $foreignUrl = route('v2.dossiers.appointments.store', [$other, $this->operation]);
    $this->post($foreignUrl, ['appointment_id' => $this->appointment->id, 'version' => 1])->assertNotFound();
    $this->get(route('v2.dossiers.appointments.index', [$other, $this->operation]))->assertNotFound();
    $this->post($this->linkUrl, ['appointment_id' => 999999, 'version' => 1])->assertNotFound();
    $different = Service::create(['name' => 'Other service', 'type' => 'AUDIT', 'average_duration_minutes' => 30]);
    $this->appointment->update(['service_id' => $different->id]);
    $this->post($this->linkUrl, ['appointment_id' => $this->appointment->id, 'version' => 1])->assertSessionHasErrors('appointment_id');
    expect($this->appointment->fresh()->dossier_operation_id)->toBeNull()->and($this->dossier->fresh()->version)->toBe(1);
});

it('rejects a conflicting link and stale updates without losing the first link', function () {
    app(DossierPlanning::class)->link($this->manager, $this->dossier, $this->operation, $this->appointment->id, 1);
    $other = app(DossierRegistry::class)->create($this->manager, [...$this->dossierData, 'reference' => 'OTHER']);
    $this->post(route('v2.dossiers.appointments.store', [$other, $other->operations()->first()]), ['appointment_id' => $this->appointment->id, 'version' => 1])
        ->assertSessionHasErrors('appointment_id');
    $second = Appointment::create([...$this->appointmentData, 'external_reference' => 'second']);
    $this->post($this->linkUrl, ['appointment_id' => $second->id, 'version' => 1])->assertSessionHasErrors('version');
    expect($this->appointment->fresh()->dossier_operation_id)->toBe($this->operation->id)
        ->and($second->fresh()->dossier_operation_id)->toBeNull();
});

it('preserves removed appointments in history and only detaches the requested link', function () {
    app(DossierPlanning::class)->link($this->manager, $this->dossier, $this->operation, $this->appointment->id, 1);
    $this->appointment->delete();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Retiré du planning');
    $this->delete(route('v2.dossiers.appointments.destroy', [$this->dossier, $this->operation, $this->appointment]), ['version' => 1])->assertSessionHasErrors('version');
    $this->delete(route('v2.dossiers.appointments.destroy', [$this->dossier, $this->operation, $this->appointment]), ['version' => 2])->assertSessionHasNoErrors();
    expect(Appointment::withTrashed()->count())->toBe(1)
        ->and($this->appointment->fresh()->dossier_operation_id)->toBeNull()
        ->and($this->dossier->activities()->latest('id')->first()->kind)->toBe('appointment_unlinked');
});

it('requires registry permissions even for searches and direct service calls', function (int $role, bool $passwordPending) {
    $actor = User::factory()->create(['role' => $role, 'admin' => false, 'must_change_password' => $passwordPending]);
    $this->actingAs($actor)->get(route('v2.dossiers.appointments.index', [$this->dossier, $this->operation]))->assertForbidden();
    $this->post($this->linkUrl, ['appointment_id' => $this->appointment->id, 'version' => 1])->assertForbidden();
    expect(fn () => app(DossierPlanning::class)->link($actor, $this->dossier, $this->operation, $this->appointment->id, 1))
        ->toThrow(AuthorizationException::class);
})->with([[2, false], [0, true], [1, true]]);

it('keeps planning links inaccessible when V2 is disabled', function () {
    config(['crm.v2_enabled' => false]);
    $this->get(route('v2.dossiers.appointments.index', [$this->dossier, $this->operation]))->assertNotFound();
    $this->post($this->linkUrl, ['appointment_id' => $this->appointment->id, 'version' => 1])->assertNotFound();
});

it('searches literal values and dates without returning every appointment by default', function () {
    $url = route('v2.dossiers.appointments.index', [$this->dossier, $this->operation]);
    $this->get($url)->assertOk()->assertViewHas('appointments', fn ($rows) => $rows->total() === 0);
    $this->get($url.'?q=DUPONT')->assertOk()->assertViewHas('appointments', fn ($rows) => $rows->total() === 1);
    $this->get($url.'?q=%25')->assertOk()->assertViewHas('appointments', fn ($rows) => $rows->total() === 0);
    $this->get($url.'?date='.$this->appointment->starts_at->toDateString())->assertOk()->assertViewHas('appointments', fn ($rows) => $rows->total() === 1);
    $this->get($url.'?date=2026-99-99')->assertSessionHasErrors('date');
    $this->appointment->delete();
    $this->get($url.'?q=DUPONT')->assertViewHas('appointments', fn ($rows) => $rows->total() === 0);
});

it('filters dossiers using current booking status without inferring a completed inspection', function () {
    app(DossierPlanning::class)->link($this->manager, $this->dossier, $this->operation, $this->appointment->id, 1);
    $this->get(route('v2.dossiers.index', ['planning' => 'upcoming']))->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1);
    $this->get(route('v2.dossiers.index', ['planning' => 'unlinked']))->assertViewHas('dossiers', fn ($rows) => $rows->total() === 0);
    $this->appointment->update(['status' => Appointment::STATUS_PROBLEM]);
    $this->get(route('v2.dossiers.index', ['planning' => 'problem']))->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1);
    $this->get(route('v2.dossiers.index', ['planning' => 'upcoming']))->assertViewHas('dossiers', fn ($rows) => $rows->total() === 0);
    $this->appointment->delete();
    $this->get(route('v2.dossiers.index', ['planning' => 'unlinked']))->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1);
});

it('shows existing lot documents without copying them and scopes downloads to their dossier', function () {
    Storage::fake('local');
    app(DossierPlanning::class)->link($this->manager, $this->dossier, $this->operation, $this->appointment->id, 1);
    $lot = Lot::create(['name' => 'LOT ORIGINE', 'type' => Lot::TYPE_FULL_CONTROL]);
    $row = LotAppointment::create(['lot_id' => $lot->id, 'appointment_id' => $this->appointment->id, 'customer_name' => 'Client lot']);
    $path = 'lot-appointment-documents/'.$row->id.'/original.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 test');
    $document = LotAppointmentDocument::create([
        'lot_appointment_id' => $row->id, 'appointment_id' => $this->appointment->id, 'name' => 'Piece existante',
        'original_name' => 'original.pdf', 'disk' => 'local', 'path' => $path, 'size' => 13, 'is_private' => true,
    ]);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('LOT ORIGINE')->assertSee('Piece existante')
        ->assertViewHas('lotDocuments', fn ($docs) => $docs->total() === 1);
    expect(DossierDocument::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([$path]);
    $url = route('v2.dossiers.lot-documents.download', [$this->dossier, $document]);
    $this->get($url)->assertOk()->assertDownload('Piece existante.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $other = app(DossierRegistry::class)->create($this->manager, [...$this->dossierData, 'reference' => 'OTHER']);
    $this->get(route('v2.dossiers.lot-documents.download', [$other, $document]))->assertNotFound();
    $document->update(['path' => '../secret.txt']);
    $this->get($url)->assertNotFound();
    $this->actingAs($this->technician)->get($url)->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->get($url)->assertRedirect(route('login'));
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('exposes the dossier shortcut in the existing calendar only to permitted roles', function () {
    app(DossierPlanning::class)->link($this->manager, $this->dossier, $this->operation, $this->appointment->id, 1);
    $payload = ['technician_ids' => [$this->technician->id], 'start' => now()->toDateString(), 'end' => now()->addDays(3)->toDateString()];
    $this->postJson(route('planner.tracking.events'), $payload)->assertOk()
        ->assertJsonPath('events.0.extendedProps.dossier_url', route('v2.dossiers.show', $this->dossier));
    $this->actingAs(User::factory()->create(['role' => 1, 'admin' => false]))
        ->postJson(route('planner.tracking.events'), $payload)->assertOk()
        ->assertJsonPath('events.0.extendedProps.dossier_url', route('v2.dossiers.show', $this->dossier));
    config(['crm.v2_enabled' => false]);
    $this->actingAs($this->manager)->postJson(route('planner.tracking.events'), $payload)->assertOk()->assertJsonPath('events.0.extendedProps.dossier_url', null);
});

it('refuses missing files foreign disks and symlinks outside the lot document directory', function () {
    Storage::fake('local');
    app(DossierPlanning::class)->link($this->manager, $this->dossier, $this->operation, $this->appointment->id, 1);
    $lot = Lot::create(['name' => 'LOT DOCUMENTS', 'type' => Lot::TYPE_FULL_CONTROL]);
    $row = LotAppointment::create(['lot_id' => $lot->id, 'appointment_id' => $this->appointment->id, 'customer_name' => 'Client lot']);
    $path = 'lot-appointment-documents/'.$row->id.'/document.pdf';
    $document = LotAppointmentDocument::create([
        'lot_appointment_id' => $row->id, 'name' => 'Document', 'original_name' => 'document.pdf',
        'disk' => 'local', 'path' => $path, 'size' => 13,
    ]);
    $url = route('v2.dossiers.lot-documents.download', [$this->dossier, $document]);
    $this->get($url)->assertNotFound();
    $document->update(['disk' => 'public']);
    $this->get($url)->assertNotFound();
    $document->update(['disk' => 'local']);

    $disk = Storage::disk('local');
    $disk->put('private-other-dossier.txt', 'must not be downloaded');
    $disk->makeDirectory(dirname($path));
    symlink($disk->path('private-other-dossier.txt'), $disk->path($path));
    try {
        $this->get($url)->assertNotFound();
    } finally {
        unlink($disk->path($path));
    }

    $disk->deleteDirectory(dirname($path));
    $disk->makeDirectory('other-documents');
    $disk->put('other-documents/document.pdf', 'must not be downloaded');
    symlink($disk->path('other-documents'), $disk->path(dirname($path)));
    try {
        $this->get($url)->assertNotFound();
    } finally {
        unlink($disk->path(dirname($path)));
    }
});

it('paginates appointments and history independently', function () {
    foreach (range(1, 22) as $number) {
        $appointment = Appointment::create([...$this->appointmentData, 'external_reference' => 'history-'.$number]);
        app(DossierPlanning::class)->link($this->manager, $this->dossier, $this->operation, $appointment->id, $number);
    }
    foreach (range(1, 5) as $number) {
        app(DossierRegistry::class)->addComment($this->manager, $this->dossier, 'Commentaire '.$number);
    }

    $this->get(route('v2.dossiers.show', [$this->dossier, 'appointments_page' => 2]))->assertOk()
        ->assertViewHas('appointments', fn ($rows) => $rows->currentPage() === 2 && $rows->count() === 2 && $rows->total() === 22)
        ->assertViewHas('activities', fn ($rows) => $rows->currentPage() === 1 && $rows->count() === 25);
    $this->get(route('v2.dossiers.show', [$this->dossier, 'history_page' => 2]))->assertOk()
        ->assertViewHas('appointments', fn ($rows) => $rows->currentPage() === 1 && $rows->count() === 20)
        ->assertViewHas('activities', fn ($rows) => $rows->currentPage() === 2 && $rows->count() === 3);
});
