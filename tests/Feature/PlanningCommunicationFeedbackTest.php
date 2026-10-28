<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentMailTemplateData;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierBooking;
use App\Services\Crm\DossierStatusCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'COMM-CLIENT',
        'kind' => 'client', 'name' => 'Client fictif', 'is_active' => true]);
    $this->delegate = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'COMM-DELEGATE',
        'kind' => 'delegataire', 'name' => 'Delegataire fictif', 'is_active' => true]);
    $this->dossier = Dossier::create(['reference' => 'COMMUNICATION-TEST', 'company_name' => 'Beneficiaire fictif',
        'email' => 'beneficiary@example.test', 'address' => '1 rue Test', 'city' => 'Paris',
        'client_id' => $this->client->id, 'delegataire_id' => $this->delegate->id]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Visite test', 'average_duration_minutes' => 60]);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->appointment = Appointment::forceCreate(['dossier_operation_id' => $this->operation->id,
        'service_id' => $this->service->id, 'technician_id' => $this->tech->id, 'created_by' => $this->actor->id,
        'customer_first_name' => '', 'customer_last_name' => 'Beneficiaire fictif', 'customer_phone' => '',
        'address' => '1 rue Test', 'starts_at' => '2026-10-30 12:30:00', 'ends_at' => '2026-10-30 13:30:00',
        'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED,
        'external_payload' => ['customer_email' => 'stale@example.test']]);
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
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()
        ->assertJsonPath('events.0.extendedProps.customer_email', 'updated@example.test');
    $this->postJson(route('planner.tracking.search'), ['q' => 'Beneficiaire'])->assertOk()
        ->assertJsonPath('appointments.0.extendedProps.customer_email', 'updated@example.test');
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $pending = app(DossierBooking::class)->pending($this->actor)->sole();
    expect($pending['email'])->toBe('updated@example.test')
        ->and($pending['customer_email'])->toBe('updated@example.test');
});

it('uses only valid legacy fallback emails and leaves missing recipients empty', function () {
    $this->dossier->update(['email' => null]);
    $this->appointment->update(['external_payload' => ['customer_email' => ['unsafe'], 'client_email' => 'invalid',
        'contact' => ['email' => ' fallback@example.test ']]]);
    $data = app(AppointmentMailTemplateData::class);
    expect($data->defaultRecipientEmail($this->appointment->fresh()))->toBe('fallback@example.test');
    $this->appointment->update(['external_payload' => ['email' => 'a@example.test'."\r\n".'Bcc: b@example.test']]);
    expect($data->defaultRecipientEmail($this->appointment->fresh()))->toBeNull();
    $this->appointment->update(['external_payload' => []]);
    expect($data->defaultRecipientEmail($this->appointment->fresh()))->toBeNull();
    config(['crm.v2_enabled' => false]);
    $this->appointment->update(['external_payload' => ['email' => 'legacy@example.test']]);
    expect($data->defaultRecipientEmail($this->appointment->fresh()))->toBe('legacy@example.test');
});

it('keeps recipient lookup batched on event feeds and search regardless of the appointment count', function (int $count) {
    for ($index = 1; $index < $count; $index++) {
        $dossier = $this->dossier->replicate();
        $dossier->reference = 'RECIPIENT-'.$index;
        $dossier->save();
        $operation = $dossier->operations()->create(['service_id' => $this->service->id]);
        $operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
        $appointment = $this->appointment->replicate();
        $appointment->dossier_operation_id = $operation->id;
        $appointment->save();
    }
    foreach (['planner.tracking.events' => [$this->range, 'events'],
        'planner.tracking.search' => [['q' => 'Beneficiaire', 'limit' => 100], 'appointments']] as $route => [$payload, $key]) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->postJson(route($route), $payload)->assertOk()->assertJsonCount($count, $key);
            expect(collect($response->json($key))->pluck('extendedProps.customer_email')->unique()->all())
                ->toBe(['beneficiary@example.test']);
            $lookups = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/from ["`]dossiers["`] /i', $query['query']));
            expect($lookups)->toHaveCount(1);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
})->with([1, 30]);

it('allows external comments in every accessible category without changing the workflow or audience', function (string $kind) {
    $organisation = $kind === 'client' ? $this->client : $this->delegate;
    $states = DossierStatusCatalog::manualStates();
    $organisation->update(['portal_categories' => array_keys($states)]);
    $external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $organisation->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => [...ClientAccess::defaults($kind), 'comments' => true, 'add_comments' => true]]);
    foreach ($states as $category) {
        $this->operation->forceFill(['legacy_status' => $category['values'][0]])->save();
        $this->actingAs($external)->get(route('client.dossiers.show', $this->dossier))->assertOk()
            ->assertSee('id="client-comment-create"', false);
        $this->post(route('client.dossiers.comments', $this->dossier), ['body' => 'Question externe',
            'client_visible' => false, 'delegataire_visible' => true])->assertRedirect();
        expect($this->operation->fresh()->legacy_status)->toBe($category['values'][0]);
    }
    expect($this->dossier->comments()->count())->toBe(count($states))
        ->and($this->dossier->comments()->where('client_visible', false)->exists())->toBeFalse()
        ->and($this->dossier->comments()->where('delegataire_visible', $kind !== 'delegataire')->exists())->toBeFalse()
        ->and($this->dossier->fresh()->version)->toBe(1);
    foreach (['comments', 'add_comments'] as $permission) {
        $external->forceFill(['portal_permissions' => [...ClientAccess::defaults($kind),
            'comments' => true, 'add_comments' => true, $permission => false]])->save();
        $this->actingAs($external->fresh())->post(route('client.dossiers.comments', $this->dossier), ['body' => 'Refuse'])
            ->assertForbidden();
    }
})->with(['client', 'delegataire']);

it('still refuses comments for inaccessible categories, unrelated dossiers, disabled accounts and deleted dossiers', function (string $kind) {
    $organisation = $kind === 'client' ? $this->client : $this->delegate;
    $organisation->update(['portal_categories' => ['report']]);
    $external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $organisation->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => [...ClientAccess::defaults($kind), 'comments' => true, 'add_comments' => true]]);
    $url = route('client.dossiers.comments', $this->dossier);
    $this->actingAs($external)->post($url, ['body' => 'Refuse'])->assertForbidden();
    $organisation->update(['portal_categories' => ['awaiting_visit']]);
    $this->dossier->update([$kind === 'client' ? 'client_id' : 'delegataire_id' => null]);
    $this->post($url, ['body' => 'Refuse'])->assertForbidden();
    $this->dossier->update([$kind === 'client' ? 'client_id' : 'delegataire_id' => $organisation->id]);
    $external->forceFill(['portal_enabled' => false])->save();
    $this->actingAs($external->fresh())->post($url, ['body' => 'Refuse'])->assertForbidden();
    $external->forceFill(['portal_enabled' => true])->save();
    $this->dossier->delete();
    $this->actingAs($external->fresh())->post($url, ['body' => 'Refuse'])->assertNotFound();
    expect($this->dossier->comments()->count())->toBe(0);
})->with(['client', 'delegataire']);
