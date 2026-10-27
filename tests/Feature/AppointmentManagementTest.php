<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\Lot;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Services\Crm\DossierBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(19, 0));
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false, 'notification_mail_enabled' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'notification_mail_enabled' => false]);
    $this->replacement = User::factory()->create(['role' => 2, 'admin' => false, 'notification_mail_enabled' => false]);
    $this->service = Service::create(['name' => 'Visite test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($this->service);
    $this->replacement->services()->attach($this->service);
    $this->dossier = Dossier::create(['reference' => 'MANAGEMENT-TEST', 'company_name' => 'Client test', 'address' => '10 rue de Rivoli', 'city' => 'Paris']);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->appointment = Appointment::forceCreate(['technician_id' => $this->tech->id, 'service_id' => $this->service->id,
        'dossier_operation_id' => $this->operation->id, 'created_by' => $this->actor->id,
        'customer_last_name' => 'Client test', 'customer_first_name' => '', 'customer_phone' => '0600000000',
        'address' => '10 rue de Rivoli, 75001 Paris', 'latitude' => 48.8566, 'longitude' => 2.3522,
        'starts_at' => '2026-11-02 14:00:00', 'ends_at' => '2026-11-02 15:00:00', 'duration_minutes' => 60, 'status' => 'scheduled']);
    $this->url = route('v2.dossiers.appointments.technician', [$this->dossier, $this->appointment->id]);
    $this->data = ['version' => $this->dossier->fresh()->version, 'technician_id' => $this->replacement->id,
        'updated_at' => $this->appointment->updated_at->toIso8601String()];
    $this->deleteUrl = route('planner.tracking.appointments.destroy', $this->appointment);
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('changes a technician immediately without any force when no conflict exists', function () {
    $this->patchJson($this->url, $this->data)->assertOk()->assertJsonPath('technician_id', $this->replacement->id)
        ->assertJsonPath('version', 2);
    expect($this->appointment->fresh()->technician_id)->toBe($this->replacement->id)
        ->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and(DossierActivity::where('kind', 'appointment_forced')->count())->toBe(0);
});

it('explains every conflict and applies a force only after explicit confirmation', function () {
    $this->replacement->services()->detach();
    $busy = $this->appointment->replicate();
    $busy->forceFill(['technician_id' => $this->replacement->id, 'dossier_operation_id' => null,
        'address' => '80 avenue de Lyon, 69000 Lyon', 'latitude' => 45.76, 'longitude' => 4.84])->save();
    TechnicianAbsence::create(['technician_id' => $this->replacement->id, 'created_by' => $this->actor->id,
        'starts_at' => '2026-11-02 00:00:00', 'ends_at' => '2026-11-03 00:00:00']);
    $this->patchJson($this->url, $this->data)->assertUnprocessable()->assertJsonPath('can_force', true)->assertJsonCount(3, 'conflicts');
    expect($this->appointment->fresh()->technician_id)->toBe($this->tech->id);
    $this->patchJson($this->url, [...$this->data, 'force_placement' => true])->assertUnprocessable()->assertJsonValidationErrors('force_confirmed');
    $this->patchJson($this->url, [...$this->data, 'force_placement' => true, 'force_confirmed' => true])->assertOk();
    $audit = DossierActivity::where('kind', 'appointment_forced')->sole();
    expect($audit->changed_fields['previous_technician_id'])->toBe($this->tech->id)
        ->and($audit->changed_fields['overlapping_appointment_ids'])->toBe([$busy->id])
        ->and($audit->client_visible)->toBeFalse()->and($audit->delegataire_visible)->toBeFalse();
});

it('does not let forcing bypass stale versions disabled technicians or engaged controls', function (string $restriction) {
    $data = [...$this->data, 'force_placement' => true, 'force_confirmed' => true];
    match ($restriction) {
        'version' => $data['version'] = 999,
        'disabled' => $this->replacement->delete(),
        'control' => $this->operation->forceFill(['inspected_on' => '2026-10-26'])->save(),
    };
    $this->patchJson($this->url, $data)->assertStatus($restriction === 'disabled' ? 404 : 422)->assertJsonMissing(['can_force' => true]);
    expect($this->appointment->fresh()->technician_id)->toBe($this->tech->id);
})->with(['version', 'disabled', 'control']);

it('renders active technician selectors only in the awaiting visit category', function () {
    $inactive = User::factory()->create(['role' => 2, 'admin' => false]);
    $inactive->delete();
    $this->get(route('v2.dossiers.index', ['status' => 'awaiting_visit']))->assertOk()
        ->assertSee('data-technician-auto', false)->assertSee('data-technician-id="'.$this->tech->id.'"', false)
        ->assertDontSee('value="'.$inactive->id.'"', false);
    $this->get(route('v2.dossiers.index'))->assertOk()->assertDontSee('data-technician-auto', false);
});

it('soft deletes future appointments reopens the booking request and preserves documents and billing', function () {
    $document = $this->dossier->documents()->create(['name' => 'Conserver', 'path' => 'keep.pdf', 'size' => 10, 'mime_type' => 'application/pdf']);
    $billing = $this->dossier->billings()->create(['dossier_operation_id' => $this->operation->id,
        'marked_at' => now(), 'marked_by' => $this->actor->id, 'source' => 'native']);
    $lot = Lot::create(['name' => 'Lot test', 'type' => Lot::TYPE_FULL_CONTROL, 'status' => Lot::STATUS_IN_PROGRESS]);
    $row = $lot->appointments()->create(['customer_name' => 'Client test', 'appointment_id' => $this->appointment->id, 'status' => 'placed']);
    $this->deleteJson($this->deleteUrl, ['confirmed' => true, 'updated_at' => $this->data['updated_at']])->assertOk();
    expect($this->appointment->fresh()->trashed())->toBeTrue()->and($this->operation->fresh()->legacy_status)->toBe('PRISE DE RDV')
        ->and($row->fresh()->appointment_id)->toBeNull()->and($row->fresh()->status)->toBe('pending')
        ->and($document->fresh()->trashed())->toBeFalse()->and($billing->fresh())->not->toBeNull();
    expect(app(DossierBooking::class)->pending($this->actor)->where('dossier_id', $this->dossier->id)->count())->toBe(1);
    $this->deleteJson($this->deleteUrl, ['confirmed' => true, 'updated_at' => $this->data['updated_at']])->assertNotFound();
    expect(DossierActivity::where('kind', 'appointment_cancelled')->count())->toBe(1);
});

it('refuses past started stale and control-protected appointment deletions', function (string $restriction) {
    $data = ['confirmed' => true, 'updated_at' => $this->data['updated_at']];
    match ($restriction) {
        'past' => $this->appointment->forceFill(['starts_at' => now()->subDay()])->save(),
        'started' => $this->appointment->forceFill(['starts_at' => now()])->save(),
        'stale' => $data['updated_at'] = now()->subDay()->toIso8601String(),
        'control' => $this->operation->forceFill(['inspected_on' => '2026-10-26'])->save(),
    };
    $this->deleteJson($this->deleteUrl, $data)->assertUnprocessable();
    expect($this->appointment->fresh()->trashed())->toBeFalse()->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE');
})->with(['past', 'started', 'stale', 'control']);

it('requires a confirmation and internal authorization for deletion', function () {
    $this->deleteJson($this->deleteUrl, ['updated_at' => $this->data['updated_at']])->assertUnprocessable();
    $this->actingAs($this->tech)->deleteJson($this->deleteUrl, ['confirmed' => true, 'updated_at' => $this->data['updated_at']])->assertForbidden();
    expect($this->appointment->fresh()->trashed())->toBeFalse();
});

it('rolls back all lot releases when one linked row has already been processed', function () {
    $lot = Lot::create(['name' => 'Lot protégé', 'type' => Lot::TYPE_FULL_CONTROL, 'status' => Lot::STATUS_IN_PROGRESS]);
    $first = $lot->appointments()->create(['customer_name' => 'Premier', 'appointment_id' => $this->appointment->id, 'status' => 'placed']);
    $lot->appointments()->create(['customer_name' => 'Traité', 'appointment_id' => $this->appointment->id,
        'status' => 'placed', 'physical_satisfaction' => true]);
    $this->deleteJson($this->deleteUrl, ['confirmed' => true, 'updated_at' => $this->data['updated_at']])->assertUnprocessable();
    expect($first->fresh()->appointment_id)->toBe($this->appointment->id)->and($first->fresh()->status)->toBe('placed')
        ->and($this->appointment->fresh()->trashed())->toBeFalse()
        ->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE');
});

it('does not reopen a prestation that still has another future appointment', function () {
    $other = $this->appointment->replicate();
    $other->save();
    $this->deleteJson($this->deleteUrl, ['confirmed' => true, 'updated_at' => $this->data['updated_at']])->assertUnprocessable();
    expect($this->appointment->fresh()->trashed())->toBeFalse()->and($other->fresh()->trashed())->toBeFalse();
});

it('does not redisplay a newly cancelled imported appointment as historical in the calendar', function () {
    $this->appointment->forceFill(['external_source' => 'coffrac_archive', 'external_reference' => 'TEST-IMPORT'])->save();
    $this->deleteJson($this->deleteUrl, ['confirmed' => true, 'updated_at' => $this->appointment->fresh()->updated_at->toIso8601String()])->assertOk();
    $this->postJson(route('planner.tracking.events'), ['technician_ids' => [$this->tech->id],
        'start' => '2026-11-01', 'end' => '2026-11-04'])->assertOk()->assertJsonCount(0, 'events');
    expect($this->appointment->fresh()->status)->toBe(Appointment::STATUS_CANCELLED);
});
