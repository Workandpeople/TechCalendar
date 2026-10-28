<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentMailTemplateData;
use App\Services\Crm\AppointmentSlotAvailability;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierBooking;
use App\Services\Crm\DossierWorkflow;
use App\Services\Tech\TechnicianWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'notification_mail_enabled' => false, 'notification_push_enabled' => false]);
    $this->client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'CLIENT', 'kind' => 'client', 'name' => 'Sunnygo test', 'is_active' => true]);
    $this->delegate = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'DELEGATE', 'kind' => 'delegataire', 'name' => 'Délégataire test', 'is_active' => true]);
    $this->dossier = Dossier::create(['reference' => 'FEEDBACK', 'company_name' => 'Bénéficiaire fictif', 'address' => '1 rue Test',
        'city' => 'Paris', 'email' => 'beneficiary@example.test', 'client_id' => $this->client->id, 'delegataire_id' => $this->delegate->id]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Visite test', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($this->service);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->appointment = Appointment::forceCreate(['dossier_operation_id' => $this->operation->id, 'service_id' => $this->service->id,
        'technician_id' => $this->tech->id, 'created_by' => $this->actor->id, 'customer_first_name' => '', 'customer_last_name' => 'Bénéficiaire fictif',
        'customer_phone' => '', 'address' => '1 rue Test', 'starts_at' => '2026-10-30 12:30:00', 'ends_at' => '2026-10-30 13:30:00',
        'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED, 'external_payload' => ['customer_email' => 'stale@example.test']]);
    $this->range = ['technician_ids' => [$this->tech->id], 'start' => '2026-10-30', 'end' => '2026-10-31'];
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('prefills the current dossier email before stale imported appointment data without sending anything', function () {
    $data = app(AppointmentMailTemplateData::class);
    expect($data->defaultRecipientEmail($this->appointment->fresh()))->toBe('beneficiary@example.test');
    $this->dossier->update(['email' => 'updated@example.test']);
    expect($data->defaultRecipientEmail($this->appointment->fresh()))->toBe('updated@example.test');
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()
        ->assertJsonPath('events.0.extendedProps.customer_email', 'updated@example.test');
    $this->dossier->update(['email' => null]);
    $this->appointment->update(['external_payload' => ['customer_email' => 'invalid', 'contact' => ['email' => 'fallback@example.test']]]);
    expect($data->defaultRecipientEmail($this->appointment->fresh()))->toBe('fallback@example.test');
    $this->appointment->update(['external_payload' => []]);
    expect($data->defaultRecipientEmail($this->appointment->fresh()))->toBeNull();
});

it('identifies pending dossiers by client company rather than delegate and includes the beneficiary email', function () {
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $pending = app(DossierBooking::class)->pending($this->actor)->sole();
    expect($pending['company_name'])->toBe('Sunnygo test')->and($pending['client_company_name'])->toBe('Sunnygo test')
        ->and($pending['delegataire_company_name'])->toBe('Délégataire test')
        ->and($pending['email'])->toBe('beneficiary@example.test')->and($pending['dossier_summary']['client'])->toBe('Sunnygo test');
    $this->dossier->update(['client_id' => null, 'client_contact' => ['company_name' => '', 'name' => 'Client manuel']]);
    expect(app(DossierBooking::class)->pending($this->actor)->sole()['company_name'])->toBe('Client manuel');
});

it('opens every pending dossier eye directly in a new tab with or without a previous appointment', function (bool $replacement) {
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    if (! $replacement) {
        $this->appointment->delete();
    }
    $html = $this->get(route('planner.book'))->assertOk()->getContent();
    expect($html)->toContain('href="'.route('v2.dossiers.show', $this->dossier, absolute: false).'" target="_blank" rel="noopener noreferrer"')
        ->toContain('aria-label="Voir le dossier (nouvel onglet)"');
})->with([true, false]);

it('allows authorized external comments in all accessible states without widening content visibility', function (string $kind) {
    $organisation = $kind === 'client' ? $this->client : $this->delegate;
    $states = ['pending' => 'PRISE DE RDV', 'awaiting_visit' => 'RDV ATTENTE VISITE', 'visited' => 'RDV VISITE',
        'rt_control' => 'CONTROLE RT', 'non_compliant' => 'NON CONFORMITES', 'recontrol' => 'RECONTROLE', 'report' => 'RAPPORT GENIUS', 'cancelled' => 'DOSSIER ANNULE'];
    $organisation->update(['portal_categories' => array_keys($states)]);
    $external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $organisation->id,
        'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => [...ClientAccess::defaults($kind), 'comments' => true, 'add_comments' => true]]);
    foreach ($states as $state) {
        $this->operation->forceFill(['legacy_status' => $state])->save();
        $this->actingAs($external)->post(route('client.dossiers.comments', $this->dossier), ['body' => 'Question externe', 'delegataire_visible' => true])
            ->assertRedirect();
        expect($this->operation->fresh()->legacy_status)->toBe($state);
    }
    expect($this->dossier->comments()->count())->toBe(8)
        ->and($this->dossier->comments()->where('client_visible', false)->exists())->toBeFalse()
        ->and($this->dossier->comments()->where('delegataire_visible', $kind !== 'delegataire')->exists())->toBeFalse();
    $external->forceFill(['portal_permissions' => [...$external->portal_permissions, 'add_comments' => false]])->save();
    $this->actingAs($external->fresh())->post(route('client.dossiers.comments', $this->dossier), ['body' => 'Refusé'])->assertForbidden();
    $external->forceFill(['portal_permissions' => [...$external->portal_permissions, 'add_comments' => true, 'comments' => false]])->save();
    $this->actingAs($external->fresh())->post(route('client.dossiers.comments', $this->dossier), ['body' => 'Refusé'])->assertForbidden();
})->with(['client', 'delegataire']);

it('removes cancelled and problem dossiers from planning and occupancy without deleting their appointment history', function (string $status) {
    app(DossierWorkflow::class)->changeStatus($this->actor, $this->dossier->fresh(), $this->operation, ['status' => $status, 'version' => $this->dossier->fresh()->version]);
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()->assertJsonCount(0, 'events');
    expect(app(TechnicianWorkspace::class)->appointmentsQuery($this->tech)->count())->toBe(0)
        ->and(app(AppointmentSlotAvailability::class)->overlaps($this->tech->id, $this->appointment->starts_at, $this->appointment->ends_at))->toBeEmpty()
        ->and($this->appointment->fresh()->trashed())->toBeFalse()
        ->and($this->operation->appointments()->count())->toBe(1);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('RDV #'.$this->appointment->id);
})->with(['cancelled', 'problem']);

it('filters the affected operation only rather than hiding all appointments of a multi-service dossier', function () {
    $other = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $other->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $copy = $this->appointment->replicate();
    $copy->dossier_operation_id = $other->id;
    $copy->save();
    $this->operation->forceFill(['legacy_status' => 'DOSSIER ANNULE'])->save();
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()->assertJsonCount(1, 'events')
        ->assertJsonPath('events.0.id', $copy->id);
    expect(app(AppointmentSlotAvailability::class)->overlaps($this->tech->id, $this->appointment->starts_at, $this->appointment->ends_at)->modelKeys())->toBe([$copy->id]);
});

it('hides appointment-level problems and deleted parent dossiers but retains an active unlinked appointment', function () {
    $this->appointment->update(['status' => Appointment::STATUS_PROBLEM]);
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()->assertJsonCount(0, 'events');
    $this->appointment->update(['status' => Appointment::STATUS_SCHEDULED]);
    $this->dossier->delete();
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()->assertJsonCount(0, 'events');
    $this->appointment->forceFill(['dossier_operation_id' => null])->save();
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()->assertJsonCount(1, 'events');
});
