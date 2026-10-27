<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\CoffracAppointmentService;
use App\Services\Crm\NativeServiceCatalog;
use App\Services\SidebarNotifications;
use App\Services\Simulation\SimulationSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    $this->manager = User::factory()->create(['role' => 0, 'admin' => true]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Isolation combles', 'average_duration_minutes' => 60]);
    $this->tech->services()->attach($this->service);
    $this->appointment = Appointment::create([
        'created_by' => $this->manager->id, 'technician_id' => $this->tech->id, 'service_id' => $this->service->id,
        'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '0100000000',
        'address' => '1 rue Test', 'starts_at' => '2026-10-20 10:00:00', 'ends_at' => '2026-10-20 11:00:00',
        'duration_minutes' => 60, 'status' => 'scheduled', 'latitude' => null, 'longitude' => null,
    ]);
    $this->actingAs($this->manager);
});

it('renders native tracking and removes remote actions and alias fields', function () {
    foreach (['manager.appointments', 'planner.tracking'] as $route) {
        $this->get(route($route))->assertOk()->assertSee('id="tracking-list-panel"', false)
            ->assertSee('id="tracking-edit-modal"', false)->assertSee('tracking_edit_technician_id')
            ->assertDontSee('id="tracking-coffrac-placed-refresh"', false)
            ->assertDontSee('<summary>Réaffecter le RDV</summary>', false);
    }
    $this->get(route('manager.services'))->assertOk()->assertDontSee('>Alias Coffrac<', false);
    $this->get(route('manager.delegataires'))->assertOk()->assertDontSee('Récupérer depuis Coffrac');
    $this->postJson(route('manager.appointments.coffrac.placed.refresh'))->assertStatus(410);
    $this->post(route('manager.delegataires.sync'))->assertStatus(410);
});

it('paginates past and future imported history without restoring deleted bookings', function () {
    $history = $this->appointment->replicate();
    $history->forceFill(['external_source' => 'coffrac_archive', 'external_reference' => '10', 'starts_at' => '2023-01-01 10:00:00',
        'ends_at' => '2023-01-01 11:00:00', 'deleted_at' => now()])->save();
    $this->postJson(route('manager.appointments.search'), ['limit' => 1, 'page' => 1])->assertOk()
        ->assertJsonPath('total', 2)->assertJsonPath('last_page', 2)->assertJsonPath('appointments.0.id', $this->appointment->id);
    $this->postJson(route('manager.appointments.search'), ['limit' => 1, 'page' => 2])->assertOk()
        ->assertJsonPath('appointments.0.id', $history->id)->assertJsonPath('appointments.0.extendedProps.can_edit', false);
    $this->postJson(route('manager.appointments.search'), ['appointment_status' => 'active'])->assertOk()->assertJsonPath('total', 1);
    $this->postJson(route('planner.tracking.events'), ['technician_ids' => [$this->tech->id], 'start' => '2023-01-01', 'end' => '2023-02-01'])
        ->assertOk()->assertJsonCount(1, 'events')->assertJsonPath('events.0.extendedProps.is_historical', true);
    $this->postJson(route('planner.tracking.events'), ['technician_ids' => [$this->tech->id], 'start' => '2026-10-01', 'end' => '2026-11-01'])
        ->assertOk()->assertJsonPath('events.0.id', $this->appointment->id);
    expect($history->fresh()->trashed())->toBeTrue();
});

it('edits all appointment fields locally and rejects overlapping and stale updates', function () {
    $dossier = Dossier::create(['address' => 'Adresse test', 'city' => 'Paris', 'reference' => 'TEST-V2', 'created_by' => $this->manager->id]);
    $operation = $dossier->operations()->create(['service_id' => $this->service->id]);
    $this->appointment->forceFill(['dossier_operation_id' => $operation->id])->save();
    $payload = ['starts_at' => '2026-10-20T14:00', 'duration_minutes' => 75, 'address' => 'Adresse corrigée',
        'latitude' => null, 'longitude' => null, 'technician_id' => $this->tech->id, 'service_id' => $this->service->id,
        'customer_first_name' => 'Nouveau', 'customer_last_name' => 'Client', 'customer_phone' => '0200000000', 'comment' => 'Note modifiée'];
    $url = route('planner.tracking.appointments.details', $this->appointment);
    $this->patchJson($url, $payload)->assertOk()->assertJsonPath('event.extendedProps.customer_name', 'Nouveau Client');
    expect($this->appointment->fresh()->ends_at->format('H:i'))->toBe('15:15')
        ->and($dossier->activities()->where('kind', 'appointment_updated')->count())->toBe(1);
    $this->patchJson($url, $payload + ['updated_at' => '2020-01-01T00:00:00Z'])->assertUnprocessable();
    $other = $this->appointment->fresh()->replicate();
    $other->address = 'Une autre adresse sans coordonnées vérifiées';
    $other->save();
    $this->patchJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    Http::assertNothingSent();
});

it('clears stale coordinates on address changes and rejects an empty beneficiary', function () {
    $this->appointment->update(['latitude' => 48.85, 'longitude' => 2.34]);
    $payload = ['starts_at' => '2026-10-20T14:00', 'duration_minutes' => 60, 'address' => 'Nouvelle adresse'];
    $url = route('planner.tracking.appointments.details', $this->appointment);
    $this->patchJson($url, $payload)->assertOk();
    expect($this->appointment->fresh()->latitude)->toBeNull()
        ->and($this->appointment->fresh()->longitude)->toBeNull();
    $this->patchJson($url, $payload + ['customer_first_name' => null, 'customer_last_name' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('customer_last_name');
});

it('reports a problem on the native dossier without contacting Coffrac', function () {
    $dossier = Dossier::create(['address' => 'Adresse test', 'city' => 'Paris', 'reference' => 'PROBLEM-V2', 'created_by' => $this->manager->id]);
    $operation = $dossier->operations()->create(['service_id' => $this->service->id]);
    $this->appointment->forceFill(['dossier_operation_id' => $operation->id])->save();
    $this->postJson(route('planner.tracking.appointments.problem', $this->appointment), [
        'comment' => 'Le client demande un rappel.',
        'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_CALLBACK,
        'recall_date' => '2026-11-01', 'recall_time' => '14:00',
    ])->assertOk();
    expect($operation->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS')
        ->and($dossier->comments()->first()->body)->toContain('Le client demande un rappel.', 'Rappel prévu le 01/11/2026 à 14:00.')
        ->and($this->appointment->fresh()->status)->toBe(Appointment::STATUS_PROBLEM);
    Http::assertNothingSent();
});

it('counts only this users scheduled appointments today and reports missing settings', function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
    expect(app(SidebarNotifications::class)->forUser($this->tech)['today'])->toBe(1)
        ->and(app(SidebarNotifications::class)->forUser($this->manager)['today'])->toBe(0);
    config(['application_settings.definitions' => [
        'missing' => ['group' => 'Test', 'label' => 'Missing'],
        'zero' => ['group' => 'Test', 'label' => 'Zero', 'fallback' => 0],
        'off' => ['group' => 'Test', 'label' => 'Off', 'fallback' => false],
        'services.coffrac.token' => ['group' => 'Test', 'label' => 'Obsolete'],
    ]]);
    expect(app(ApplicationSettings::class)->missingCount())->toBe(1);
    $this->get(route('profile'))->assertOk()->assertSee('1 paramètre(s) non renseigné(s)');
});

it('allows explicitly enabled dev APIs but still blocks outgoing email in simulation', function () {
    config(['simulation.allow_external_apis' => true, 'services.mapbox.token' => 'test-token']);
    app(SimulationSafety::class)->activate();
    Http::fake(['https://example.test/*' => Http::response(['ok' => true])]);
    expect(Http::get('https://example.test/test')->json('ok'))->toBeTrue()
        ->and(config('services.mapbox.token'))->toBe('test-token')
        ->and(config('mail.default'))->toBe('log');
});

it('splits source services without deleting old aliases and preserves native edits on a second run', function () {
    $this->service->externalAliases()->create(['source' => 'coffrac', 'external_type' => 'COFFRAC', 'external_name' => 'Autre isolation',
        'normalized_external_name' => 'autre isolation', 'normalized_external_type' => 'coffrac']);
    $dossier = Dossier::create(['address' => 'Adresse test', 'city' => 'Paris', 'reference' => 'SOURCE', 'created_by' => $this->manager->id]);
    $operation = $dossier->operations()->create(['service_id' => $this->service->id, 'source' => 'coffrac', 'source_reference' => '200']);
    $operation->forceFill(['legacy_service_name' => 'Autre isolation'])->save();
    foreach ([['fiche_type', 10, ['name' => 'Isolation combles']], ['fiche_type', 11, ['name' => 'Autre isolation']],
        ['dossier_fiches', 200, ['fiche_id' => 11]]] as [$table, $id, $data]) {
        DB::table('legacy_records')->insert(['source_table' => $table, 'source_id' => $id, 'payload' => json_encode($data)]);
    }
    $mapping = app(NativeServiceCatalog::class)->reconcile();
    expect($mapping[10])->not->toBe($mapping[11])
        ->and($operation->fresh()->service_id)->toBe($mapping[11])
        ->and($this->service->externalAliases()->count())->toBe(1);
    $operation->refresh()->update(['service_id' => $mapping[10]]);
    $newService = Service::create(['type' => 'COFFRAC', 'name' => 'Nouvelle prestation native', 'average_duration_minutes' => 60]);
    expect(app(NativeServiceCatalog::class)->reconcile())->toBe($mapping)
        ->and($operation->fresh()->service_id)->toBe($mapping[10])
        ->and($newService->fresh()->retired_at)->toBeNull();
});
