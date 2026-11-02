<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\Lot;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Services\Crm\DossierLocation;
use App\Services\Crm\DossierMapPreview;
use App\Services\Crm\PostVisitWorkflow;
use App\Services\Mymar\MymarSlotGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0));
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->otherTech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->service = Service::create(['name' => 'BAT EQ 127', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($this->service);
    $this->otherTech->services()->attach($this->service);
    $this->dossier = Dossier::create(['reference' => 'RESCHEDULE-TEST', 'company_name' => 'Client test',
        'address' => '10 rue de Rivoli', 'postal_code' => '75001', 'city' => 'Paris']);
    $this->dossier->forceFill(['inspection_geocode' => DossierLocation::metadata($this->dossier, ['latitude' => 48.86, 'longitude' => 2.35])])->save();
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->appointment = Appointment::forceCreate(['technician_id' => $this->tech->id, 'service_id' => $this->service->id,
        'dossier_operation_id' => $this->operation->id, 'created_by' => $this->actor->id,
        'customer_last_name' => 'Client test', 'customer_first_name' => '', 'customer_phone' => '0600000000',
        'address' => '10 rue de Rivoli, 75001 Paris', 'latitude' => 48.86, 'longitude' => 2.35,
        'starts_at' => '2026-11-03 14:00:00', 'ends_at' => '2026-11-03 15:00:00', 'duration_minutes' => 60,
        'comment' => 'Commentaire conservé', 'external_payload' => ['source_proof' => 'keep'], 'status' => 'scheduled']);
    $this->url = route('v2.dossiers.appointments.reschedule', [$this->dossier, $this->operation]);
    $this->data = ['version' => $this->dossier->fresh()->version, 'appointment_id' => $this->appointment->id,
        'updated_at' => $this->appointment->updated_at->toIso8601String(), 'technician_id' => $this->otherTech->id,
        'starts_at' => '2026-11-04T10:40:00+01:00', 'duration_minutes' => 45];
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('loads a small fresh context with only active internal technicians and without geocoding', function () {
    $disabled = User::factory()->create(['role' => 2, 'admin' => false]);
    $disabled->delete();
    User::factory()->create(['role' => 0, 'admin' => true]);
    $this->getJson($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('appointment_id', $this->appointment->id)->assertJsonPath('technician_id', $this->tech->id)
        ->assertJsonPath('starts_at', '2026-11-03T14:00:00+01:00')->assertJsonPath('duration_minutes', 60)
        ->assertJsonCount(2, 'technicians')->assertJsonPath('technicians.0.qualified', true)
        ->assertJsonMissingPath('technicians.0.email')->assertJsonMissingPath('technicians.0.password');
    expect($this->dossier->fresh()->version)->toBe(1)->and(Appointment::count())->toBe(1);
});

it('reschedules in place at the exact Paris time and keeps comments documents and billing', function () {
    $document = $this->dossier->documents()->create(['name' => 'Conserver', 'path' => 'keep.pdf', 'size' => 10, 'mime_type' => 'application/pdf']);
    $billing = $this->dossier->billings()->create(['dossier_operation_id' => $this->operation->id,
        'marked_at' => now(), 'marked_by' => $this->actor->id, 'source' => 'native']);
    $this->patchJson($this->url, $this->data)->assertOk()->assertJsonPath('created', false)
        ->assertJsonPath('starts_at', '2026-11-04T10:40:00+01:00')->assertJsonPath('ends_at', '2026-11-04T11:25:00+01:00');
    expect(Appointment::count())->toBe(1)->and($this->appointment->fresh()->starts_at->format('H:i'))->toBe('10:40')
        ->and($this->appointment->fresh()->technician_id)->toBe($this->otherTech->id)
        ->and($this->appointment->fresh()->comment)->toBe('Commentaire conservé')
        ->and($this->appointment->fresh()->external_payload)->toBe(['source_proof' => 'keep'])
        ->and($document->fresh()->trashed())->toBeFalse()->and($billing->fresh())->not->toBeNull()
        ->and(DossierActivity::where('kind', 'appointment_rescheduled')->count())->toBe(1);
});

it('shows conflicts then forces only after explicit confirmation without moving the other RDV', function () {
    $busy = $this->appointment->replicate();
    $busy->forceFill(['technician_id' => $this->otherTech->id, 'dossier_operation_id' => null,
        'starts_at' => '2026-11-04 10:00:00', 'ends_at' => '2026-11-04 12:00:00',
        'address' => '80 avenue de Lyon, 69000 Lyon', 'latitude' => 45.76, 'longitude' => 4.84])->save();
    TechnicianAbsence::create(['technician_id' => $this->otherTech->id, 'created_by' => $this->actor->id,
        'starts_at' => '2026-11-04 00:00:00', 'ends_at' => '2026-11-05 00:00:00']);
    $this->patchJson($this->url, $this->data)->assertUnprocessable()->assertJsonPath('can_force', true)->assertJsonCount(2, 'conflicts');
    expect($this->appointment->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-11-03 14:00');
    $this->patchJson($this->url, [...$this->data, 'force_placement' => true])->assertUnprocessable()->assertJsonValidationErrors('force_confirmed');
    $this->patchJson($this->url, [...$this->data, 'force_placement' => true, 'force_confirmed' => true])->assertOk();
    $audit = DossierActivity::where('kind', 'appointment_forced')->sole();
    expect($audit->changed_fields['overlapping_appointment_ids'])->toBe([$busy->id])
        ->and($audit->changed_fields['previous_technician_id'])->toBe($this->tech->id)
        ->and($audit->client_visible)->toBeFalse()->and($audit->delegataire_visible)->toBeFalse()
        ->and($busy->fresh()->starts_at->format('H:i'))->toBe('10:00');
});

it('does not force an uncertain MAR reservation or unavailable MAR verification', function (string $type) {
    $this->mock(MymarSlotGuard::class, function ($mock) use ($type) {
        $mock->shouldReceive('pendingConflicts')->andReturn([]);
        $mock->shouldReceive('liveConflicts')->andReturn([['type' => $type, 'field' => 'starts_at', 'message' => 'Confirmation MAR non vérifiée.']]);
    });
    $this->patchJson($this->url, [...$this->data, 'force_placement' => true, 'force_confirmed' => true])
        ->assertUnprocessable()->assertJsonPath('can_force', false);
    expect($this->appointment->fresh()->technician_id)->toBe($this->tech->id);
})->with(['mymar_pending', 'mymar_unknown']);

it('requires an explicit force for a non qualified active technician', function () {
    $this->otherTech->services()->detach();
    $this->patchJson($this->url, $this->data)->assertUnprocessable()->assertJsonPath('can_force', true)
        ->assertJsonPath('conflicts.0.type', 'qualification');
    $this->patchJson($this->url, [...$this->data, 'force_placement' => true, 'force_confirmed' => true])->assertOk();
    expect($this->appointment->fresh()->technician_id)->toBe($this->otherTech->id);
});

it('keeps the existing same address overlap exemption without requiring a force', function () {
    $busy = $this->appointment->replicate();
    $busy->forceFill(['technician_id' => $this->otherTech->id, 'dossier_operation_id' => null,
        'starts_at' => '2026-11-04 10:00:00', 'ends_at' => '2026-11-04 12:00:00'])->save();
    $this->patchJson($this->url, $this->data)->assertOk();
    expect(DossierActivity::where('kind', 'appointment_forced')->count())->toBe(0);
});

it('refuses to create a new reservation without GPS even when forced', function () {
    $this->appointment->delete();
    $this->mock(DossierMapPreview::class)->shouldReceive('coordinates')->once()->andReturn(['coordinates' => null]);
    $this->patchJson($this->url, [...$this->data, 'appointment_id' => null, 'updated_at' => null,
        'force_placement' => true, 'force_confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('address');
    expect(Appointment::count())->toBe(0)->and(Appointment::withTrashed()->count())->toBe(1);
});

it('reopens a problem in awaiting visit only after successful placement', function () {
    $this->operation->forceFill(['legacy_status' => 'PROBLÈME RENDEZ-VOUS'])->save();
    $this->appointment->update(['status' => 'problem', 'problem_reported_at' => now()]);
    $this->data['updated_at'] = $this->appointment->updated_at->toIso8601String();
    $this->patchJson($this->url, $this->data)->assertOk();
    expect($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and($this->appointment->fresh()->status)->toBe('scheduled')->and($this->appointment->fresh()->problem_reported_at)->toBeNull();
});

it('creates a new reservation without restoring or overwriting a removed historical RDV', function () {
    $this->appointment->forceFill(['starts_at' => '2026-10-07 10:00:00', 'ends_at' => '2026-10-07 11:00:00', 'status' => 'cancelled'])->save();
    $this->appointment->delete();
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $before = $this->appointment->fresh()->getRawOriginal();
    $this->getJson($this->url)->assertOk()->assertJsonPath('appointment_id', null)
        ->assertJsonPath('starts_at', '2026-10-07T10:00:00+02:00');
    $data = [...$this->data, 'appointment_id' => null, 'updated_at' => null];
    $this->patchJson($this->url, $data)->assertOk()->assertJsonPath('created', true)->assertJsonPath('dossier_appointments_count', 2);
    expect($this->appointment->fresh()->getRawOriginal())->toBe($before)
        ->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')->and(Appointment::count())->toBe(1)
        ->and(Appointment::withTrashed()->count())->toBe(2);
    $this->patchJson($this->url, $data)->assertUnprocessable()->assertJsonValidationErrors('version');
    expect(Appointment::withTrashed()->count())->toBe(2);
});

it('rejects stale appointment snapshots dossier versions and newly created reservations', function (string $change) {
    match ($change) {
        'version' => $this->dossier->increment('version'),
        'appointment' => $this->appointment->forceFill(['updated_at' => now()->addSecond()])->save(),
        'removed' => $this->appointment->delete(),
    };
    $this->patchJson($this->url, [...$this->data, 'force_placement' => true, 'force_confirmed' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('version')->assertJsonMissingPath('can_force');
})->with(['version', 'appointment', 'removed']);

it('replaces directly from every business state without changing documents or billing', function (?string $state) {
    $this->operation->forceFill(['legacy_status' => $state])->save();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('data-quick-reschedule-url', false)->assertSee('Replacer rapidement');
    $this->getJson($this->url)->assertOk()->assertJsonPath('appointment_id', $this->appointment->id);
    $this->patchJson($this->url, $this->data)->assertOk()->assertJsonPath('created', false);
    expect($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and(Appointment::count())->toBe(1);
})->with(['PRISE DE RDV', 'PROBLÈME RENDEZ-VOUS', 'RDV ATTENTE VISITE', 'RDV VISITE',
    'CONTROLE RT', 'NON CONFORMITES', 'RECONTROLE', 'RAPPORT GENIUS', 'DOSSIERS FACTURER', 'DOSSIER ANNULE', null]);

it('creates a separate visit for an engaged control and can then reschedule only that new reservation', function (string $restriction) {
    $this->operation->forceFill(match ($restriction) {
        'inspected' => ['inspected_on' => '2026-11-01'],
        'submitted' => ['control_submitted_at' => now()],
        'validated' => ['control_validated_at' => now()],
        'visit' => ['visit_appointment_id' => $this->appointment->id],
    })->save();
    $before = $this->appointment->fresh()->getRawOriginal();
    $evidence = PostVisitWorkflow::planningEvidence($this->operation);
    $this->getJson($this->url)->assertOk()->assertJsonPath('appointment_id', null)
        ->assertJsonPath('preserves_previous_visit', true);
    $this->patchJson($this->url, $this->data)->assertUnprocessable()->assertJsonValidationErrors('version');
    $newId = $this->patchJson($this->url, [...$this->data, 'appointment_id' => null, 'updated_at' => null])
        ->assertOk()->assertJsonPath('created', true)->json('appointment_id');
    $context = $this->getJson($this->url)->assertOk()->assertJsonPath('appointment_id', $newId)->json();
    $this->patchJson($this->url, [...$this->data, ...array_intersect_key($context, array_flip(['version', 'appointment_id', 'updated_at'])),
        'starts_at' => '2026-11-05T10:40:00+01:00'])->assertOk()->assertJsonPath('created', false);
    expect($this->appointment->fresh()->getRawOriginal())->toBe($before)
        ->and(PostVisitWorkflow::planningEvidence($this->operation->fresh()))->toBe($evidence)
        ->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and(Appointment::count())->toBe(2)
        ->and(Appointment::find($newId)->starts_at->format('Y-m-d H:i'))->toBe('2026-11-05 10:40');
})->with(['inspected', 'submitted', 'validated', 'visit']);

it('keeps the report billing dates and inspected appointment when reopening a finalized dossier', function () {
    $document = $this->dossier->documents()->create(['name' => 'Rapport conservé', 'path' => 'keep.pdf', 'size' => 10,
        'mime_type' => 'application/pdf', 'document_type' => 'report', 'dossier_operation_id' => $this->operation->id]);
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS', 'inspected_on' => '2026-11-01',
        'control_submitted_at' => now(), 'control_validated_at' => now(), 'control_validated_by' => $this->actor->id,
        'control_outcome' => 'satisfactory', 'report_document_id' => $document->id,
        'visit_appointment_id' => $this->appointment->id])->save();
    $billing = $this->dossier->billings()->create(['dossier_operation_id' => $this->operation->id,
        'marked_at' => now()->subDay(), 'marked_by' => $this->actor->id, 'source' => 'native']);
    $beforeOperation = $this->operation->fresh()->getRawOriginal();
    $beforeBilling = $billing->fresh()->getRawOriginal();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Replacer le RDV');
    $this->patchJson($this->url, [...$this->data, 'appointment_id' => null, 'updated_at' => null])->assertOk();
    expect($document->fresh()->trashed())->toBeFalse()
        ->and($billing->fresh()->getRawOriginal())->toBe($beforeBilling)
        ->and(array_diff_key($this->operation->fresh()->getRawOriginal(), array_flip(['legacy_status', 'updated_at'])))->toBe(
            array_diff_key($beforeOperation, array_flip(['legacy_status', 'updated_at'])))
        ->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE');
});

it('protects a follow up once it becomes evidence for the next control', function () {
    $this->operation->forceFill(['inspected_on' => '2026-11-01'])->save();
    $newId = $this->patchJson($this->url, [...$this->data, 'appointment_id' => null, 'updated_at' => null])->assertOk()->json('appointment_id');
    $this->operation->forceFill(['inspected_on' => '2026-11-04', 'visit_appointment_id' => $newId])->save();
    $this->getJson($this->url)->assertOk()->assertJsonPath('appointment_id', null);
    expect(fn () => PostVisitWorkflow::assertPlanningMutable($this->operation, Appointment::find($newId)))
        ->toThrow(ValidationException::class);
});

it('accepts unordered JSON evidence keys as stored by MySQL but rejects a changed control', function () {
    $this->operation->forceFill(['inspected_on' => '2026-11-01', 'control_submitted_at' => now()])->save();
    $this->appointment->forceFill(['external_payload' => ['quick_reschedule_control' => array_reverse(PostVisitWorkflow::planningEvidence($this->operation), true)]])->save();
    expect(PostVisitWorkflow::isFollowUp($this->operation, $this->appointment))->toBeTrue();
    $this->operation->forceFill(['control_submitted_at' => now()->addSecond()])->save();
    expect(PostVisitWorkflow::isFollowUp($this->operation, $this->appointment))->toBeFalse();
});

it('keeps a finalized state on conflict and reopens only after confirmed forced placement', function () {
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS', 'inspected_on' => '2026-11-01'])->save();
    $busy = $this->appointment->replicate();
    $busy->forceFill(['technician_id' => $this->otherTech->id, 'dossier_operation_id' => null,
        'starts_at' => '2026-11-04 10:00:00', 'ends_at' => '2026-11-04 12:00:00',
        'address' => '80 avenue de Lyon, 69000 Lyon', 'latitude' => 45.76, 'longitude' => 4.84])->save();
    $data = [...$this->data, 'appointment_id' => null, 'updated_at' => null];
    $this->patchJson($this->url, $data)->assertUnprocessable()->assertJsonPath('can_force', true);
    expect($this->operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS')->and(Appointment::count())->toBe(2);
    $this->patchJson($this->url, [...$data, 'force_placement' => true, 'force_confirmed' => true])->assertOk();
    expect($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and(Appointment::count())->toBe(3)->and($busy->fresh()->starts_at->format('H:i'))->toBe('10:00')
        ->and(DossierActivity::where('kind', 'appointment_forced')->count())->toBe(1);
});

it('preserves a control-linked source lot row during successive quick replacements', function () {
    $lot = Lot::create(['name' => 'Lot test', 'type' => Lot::TYPE_FULL_CONTROL, 'status' => Lot::STATUS_IN_PROGRESS]);
    $row = $lot->appointments()->create(['customer_name' => 'Client test', 'appointment_id' => $this->appointment->id, 'status' => 'placed']);
    $this->operation->forceFill(['source' => 'lot', 'source_reference' => (string) $row->id,
        'legacy_status' => 'RAPPORT GENIUS', 'inspected_on' => '2026-11-01', 'visit_appointment_id' => $this->appointment->id])->save();
    $before = $row->fresh()->getRawOriginal();
    $newId = $this->patchJson($this->url, [...$this->data, 'appointment_id' => null, 'updated_at' => null])->assertOk()->json('appointment_id');
    $context = $this->getJson($this->url)->assertOk()->assertJsonPath('appointment_id', $newId)->json();
    $this->patchJson($this->url, [...$this->data, ...array_intersect_key($context, array_flip(['version', 'appointment_id', 'updated_at'])),
        'starts_at' => '2026-11-05T10:40:00+01:00'])->assertOk()->assertJsonPath('created', false);
    expect($row->fresh()->getRawOriginal())->toBe($before)->and(Appointment::count())->toBe(2);
});

it('does not expose quick replacement on deleted dossiers or accept a deleted dossier write', function () {
    $this->dossier->delete();
    $this->actingAs(User::factory()->create(['role' => 0, 'admin' => false]));
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertDontSee('data-quick-reschedule-url', false);
    $this->getJson($this->url)->assertNotFound();
    $this->patchJson($this->url, $this->data)->assertNotFound();
    expect(Appointment::count())->toBe(1);
});

it('refuses disabled admin non technician and external target accounts', function (string $restriction) {
    match ($restriction) {
        'disabled' => $this->otherTech->delete(),
        'admin' => $this->otherTech->update(['admin' => true]),
        'manager' => $this->otherTech->update(['role' => 0]),
        'external' => $this->otherTech->update(['role' => 3]),
    };
    $this->patchJson($this->url, [...$this->data, 'force_placement' => true, 'force_confirmed' => true])->assertNotFound();
    expect($this->appointment->fresh()->technician_id)->toBe($this->tech->id);
})->with(['disabled', 'admin', 'manager', 'external']);

it('refuses ambiguous multiple active appointments and keeps them both unchanged', function () {
    $other = $this->appointment->replicate();
    $other->save();
    $this->getJson($this->url)->assertUnprocessable();
    $this->patchJson($this->url, $this->data)->assertUnprocessable()->assertJsonMissingPath('can_force');
    expect(Appointment::count())->toBe(2);
});

it('creates a new visit without rewriting archived or processed lot proofs', function (string $restriction) {
    $lot = Lot::create(['name' => 'Lot test', 'type' => Lot::TYPE_FULL_CONTROL,
        'status' => $restriction === 'archived' ? Lot::STATUS_ARCHIVED : Lot::STATUS_IN_PROGRESS]);
    $row = $lot->appointments()->create(['customer_name' => 'Client test', 'appointment_id' => $this->appointment->id,
        'status' => 'placed', 'physical_satisfaction' => $restriction === 'processed' ? true : null]);
    $this->operation->update(['source' => 'lot', 'source_reference' => (string) $row->id]);
    $before = $row->fresh()->getRawOriginal();
    $this->getJson($this->url)->assertOk()->assertJsonPath('appointment_id', null);
    $this->patchJson($this->url, [...$this->data, 'appointment_id' => null, 'updated_at' => null])->assertOk()->assertJsonPath('created', true);
    expect($this->appointment->fresh()->technician_id)->toBe($this->tech->id)
        ->and($row->fresh()->getRawOriginal())->toBe($before)->and(Appointment::count())->toBe(2);
})->with(['archived', 'processed']);

it('rebinds the pending source lot row without restoring the removed appointment', function () {
    $this->appointment->delete();
    $lot = Lot::create(['name' => 'Lot test', 'type' => Lot::TYPE_FULL_CONTROL, 'status' => Lot::STATUS_IN_PROGRESS]);
    $row = $lot->appointments()->create(['customer_name' => 'Client test', 'status' => 'pending']);
    $this->operation->update(['source' => 'lot', 'source_reference' => (string) $row->id]);
    $this->patchJson($this->url, [...$this->data, 'appointment_id' => null, 'updated_at' => null])->assertOk();
    expect($row->fresh()->status)->toBe('placed')->and($row->fresh()->appointment_id)->toBe(Appointment::sole()->id)
        ->and($this->appointment->fresh()->trashed())->toBeTrue();
});

it('rebooks a cancelled source lot reservation without changing its old appointment or losing the lot link', function () {
    $this->appointment->update(['status' => Appointment::STATUS_CANCELLED]);
    $this->operation->forceFill(['legacy_status' => 'DOSSIER ANNULE'])->save();
    $lot = Lot::create(['name' => 'Lot test', 'type' => Lot::TYPE_FULL_CONTROL, 'status' => Lot::STATUS_IN_PROGRESS]);
    $row = $lot->appointments()->create(['customer_name' => 'Client test', 'appointment_id' => $this->appointment->id, 'status' => 'pending']);
    $this->operation->update(['source' => 'lot', 'source_reference' => (string) $row->id]);
    $before = $this->appointment->fresh()->getRawOriginal();
    $newId = $this->patchJson($this->url, [...$this->data, 'appointment_id' => null, 'updated_at' => null])
        ->assertOk()->assertJsonPath('created', true)->json('appointment_id');
    expect($this->appointment->fresh()->getRawOriginal())->toBe($before)
        ->and($row->fresh()->appointment_id)->toBe($newId)->and($row->fresh()->status)->toBe('placed')
        ->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE');
});

it('enforces internal authorization V2 and operation scoping', function () {
    $foreign = $this->dossier->replicate();
    $foreign->reference = 'FOREIGN';
    $foreign->save();
    $this->getJson(route('v2.dossiers.appointments.reschedule', [$foreign, $this->operation]))->assertNotFound();
    $this->actingAs($this->tech)->getJson($this->url)->assertForbidden();
    $this->patchJson($this->url, $this->data)->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 3, 'admin' => false]))->getJson($this->url)->assertForbidden();
    $this->actingAs($this->actor);
    config(['crm.v2_enabled' => false]);
    $this->getJson($this->url)->assertNotFound();
    $this->patchJson($this->url, $this->data)->assertNotFound();
});

it('rejects invalid past missing and nonexistent DST hours', function (array $override) {
    $this->patchJson($this->url, [...$this->data, ...$override])->assertUnprocessable();
    expect($this->appointment->fresh()->technician_id)->toBe($this->tech->id);
})->with([
    [['starts_at' => '2026-11-01T10:00:00+01:00']],
    [['starts_at' => '2027-03-28T02:30:00']],
    [['starts_at' => 'not-a-date']],
    [['duration_minutes' => 0]],
    [['duration_minutes' => 1441]],
    [['updated_at' => null]],
]);

it('renders quick replacement entries directly in the dossier without a full booking redirect', function () {
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('data-quick-reschedule-url', false)->assertSee('data-quick-reschedule-dialog', false)
        ->assertSee('Replacer rapidement')->assertDontSee('/planner/appointments/modify?replace_appointment_id', false);
});
