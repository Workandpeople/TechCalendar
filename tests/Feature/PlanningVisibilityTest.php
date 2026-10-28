<?php

use App\Http\Controllers\Planner\PlannerBookingController;
use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\MobileAccessToken;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\AppointmentSlotAvailability;
use App\Services\Crm\DossierWorkflow;
use App\Services\Tech\TechnicianWorkspace;
use App\Services\TechnicianDailyRouteMetricService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false, 'services.mapbox.token' => '']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'latitude' => 48.86, 'longitude' => 2.35]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Visite fictive', 'average_duration_minutes' => 60]);
    $this->dossier = Dossier::create(['reference' => 'PLANNING-VISIBILITY', 'company_name' => 'Beneficiaire fictif',
        'address' => '1 rue Test', 'city' => 'Paris']);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->appointment = Appointment::forceCreate(['dossier_operation_id' => $this->operation->id,
        'service_id' => $this->service->id, 'technician_id' => $this->tech->id, 'created_by' => $this->actor->id,
        'customer_first_name' => 'Client', 'customer_last_name' => 'Fictif', 'customer_phone' => '',
        'address' => '1 rue Test, 75001 Paris', 'latitude' => 48.87, 'longitude' => 2.36,
        'starts_at' => '2026-10-30 12:30:00', 'ends_at' => '2026-10-30 13:30:00',
        'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED]);
    $this->range = ['technician_ids' => [$this->tech->id], 'start' => '2026-10-30', 'end' => '2026-10-31'];
    $this->dossier->refresh();
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('removes a cancelled or problem operation from calendars and occupancy while retaining history', function (string $status) {
    app(DossierWorkflow::class)->changeStatus($this->actor, $this->dossier, $this->operation,
        ['status' => $status, 'version' => $this->dossier->version]);
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()->assertJsonCount(0, 'events');
    expect(app(TechnicianWorkspace::class)->appointmentsQuery($this->tech)->count())->toBe(0)
        ->and(app(AppointmentSlotAvailability::class)->overlaps($this->tech->id, $this->appointment->starts_at, $this->appointment->ends_at))->toBeEmpty()
        ->and($this->appointment->fresh()->trashed())->toBeFalse()
        ->and($this->appointment->fresh()->status)->toBe(Appointment::STATUS_SCHEDULED)
        ->and($this->operation->appointments()->count())->toBe(1);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('RDV #'.$this->appointment->id);
    $this->postJson(route('planner.tracking.search'), ['q' => 'Fictif'])->assertOk()
        ->assertJsonCount(1, 'appointments')->assertJsonPath('appointments.0.id', $this->appointment->id);
    app(DossierWorkflow::class)->changeStatus($this->actor, $this->dossier->fresh(), $this->operation->fresh(),
        ['status' => 'awaiting_visit', 'version' => $this->dossier->fresh()->version]);
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()->assertJsonCount(1, 'events');
})->with(['cancelled', 'problem']);

it('filters only the affected operation of a multi service dossier', function () {
    $other = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $other->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $copy = $this->appointment->replicate();
    $copy->dossier_operation_id = $other->id;
    $copy->save();
    $this->operation->forceFill(['legacy_status' => 'DOSSIER ANNULE'])->save();
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()
        ->assertJsonCount(1, 'events')->assertJsonPath('events.0.id', $copy->id);
    expect(app(AppointmentSlotAvailability::class)->overlaps($this->tech->id, $copy->starts_at, $copy->ends_at)->modelKeys())
        ->toBe([$copy->id]);
});

it('keeps scheduled unlinked and unknown status operations visible but excludes every retired form', function () {
    foreach ([null, 'VALEUR HISTORIQUE', 'RDV VISITE', 'CONTROLE RT', 'RAPPORT GENIUS'] as $status) {
        $this->operation->forceFill(['legacy_status' => $status])->save();
        expect(Appointment::query()->onPlanning()->count())->toBe(1);
    }
    foreach (['DOSSIER ANNULE', 'Problème rendez- vous', 'PROBLÈME RENDEZ-VOUS'] as $status) {
        $this->operation->forceFill(['legacy_status' => $status])->save();
        expect(Appointment::query()->onPlanning()->count())->toBe(0);
    }
    $this->appointment->forceFill(['dossier_operation_id' => null])->save();
    expect(Appointment::query()->onPlanning()->count())->toBe(1);
    foreach ([Appointment::STATUS_CANCELLED, Appointment::STATUS_PROBLEM] as $status) {
        $this->appointment->forceFill(['status' => $status])->save();
        expect(Appointment::query()->onPlanning()->count())->toBe(0);
    }
});

it('excludes deleted dossiers and soft deleted archive appointments even from a withTrashed calendar query', function () {
    $this->dossier->delete();
    expect(Appointment::withTrashed()->onPlanning()->count())->toBe(0);
    $this->dossier->restore();
    $this->appointment->forceFill(['external_source' => 'coffrac_archive'])->save();
    $this->appointment->delete();
    $this->postJson(route('planner.tracking.events'), $this->range)->assertOk()->assertJsonCount(0, 'events');
    expect(Appointment::withTrashed()->onPlanning()->count())->toBe(0)
        ->and(Appointment::withTrashed()->count())->toBe(1);
    $this->appointment->restore();
    expect(Appointment::query()->onPlanning()->count())->toBe(1);
});

it('uses the same visibility for the technician mobile feed', function () {
    $token = 'synthetic-mobile-token';
    MobileAccessToken::create(['user_id' => $this->tech->id, 'name' => 'Test', 'token_hash' => hash('sha256', $token)]);
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson(route('api.mobile.planning', ['start' => '2026-10-30', 'end' => '2026-10-30']))
        ->assertOk()->assertJsonCount(1, 'appointments');
    $this->operation->forceFill(['legacy_status' => 'DOSSIER ANNULE'])->save();
    $this->getJson(route('api.mobile.planning', ['start' => '2026-10-30', 'end' => '2026-10-30']))
        ->assertOk()->assertJsonCount(0, 'appointments')->assertJsonPath('widgets.week_count', 0);
});

it('does not reserve the retired appointments in the booking calendar query', function () {
    $controller = app(PlannerBookingController::class);
    $method = new ReflectionMethod($controller, 'appointmentsForTechnicians');
    $rows = fn () => $method->invoke($controller, collect([$this->tech->id]), Carbon::parse('2026-10-30'), Carbon::parse('2026-10-31'));
    expect($rows()->modelKeys())->toBe([$this->appointment->id]);
    $this->operation->forceFill(['legacy_status' => 'DOSSIER ANNULE'])->save();
    expect($rows())->toBeEmpty();
});

it('calculates route metrics from the active appointments only', function () {
    $copy = $this->appointment->replicate();
    $copy->dossier_operation_id = null;
    $copy->starts_at = '2026-10-30 14:30:00';
    $copy->ends_at = '2026-10-30 15:30:00';
    $copy->save();
    $this->operation->forceFill(['legacy_status' => 'DOSSIER ANNULE'])->save();
    $metrics = app(TechnicianDailyRouteMetricService::class)->ensureForTechnicianPeriod($this->tech,
        Carbon::parse('2026-10-30'), Carbon::parse('2026-10-30'));
    expect($metrics)->toHaveCount(1)->and($metrics->first()->appointment_count)->toBe(1);
});

it('does not apply native visibility to V1 calendars or legacy occupancy checks', function () {
    config(['crm.v2_enabled' => false]);
    $this->appointment->forceFill(['status' => Appointment::STATUS_PROBLEM])->save();
    expect(app(AppointmentSlotAvailability::class)->overlaps($this->tech->id, $this->appointment->starts_at, $this->appointment->ends_at))->toHaveCount(1)
        ->and(app(TechnicianWorkspace::class)->appointmentsQuery($this->tech)->count())->toBe(1);
});

it('keeps the calendar status filter for V1 only while preserving the native history search', function () {
    $this->get(route('planner.tracking'))->assertOk()->assertDontSee('id="tracking_status_filter"', false);
    config(['crm.v2_enabled' => false]);
    $this->get(route('planner.tracking'))->assertOk()->assertSee('id="tracking_status_filter"', false);
});

it('adds and rolls back only the temporal planning index without changing appointments', function () {
    $before = $this->appointment->fresh()->getRawOriginal();
    $migration = require database_path('migrations/2026_10_28_140000_add_appointment_planning_window_index.php');
    expect(Schema::hasIndex('appointments', 'appointments_planning_window_index'))->toBeTrue();
    $migration->down();
    expect(Schema::hasIndex('appointments', 'appointments_planning_window_index'))->toBeFalse();
    $migration->up();
    expect(Schema::hasIndex('appointments', 'appointments_planning_window_index'))->toBeTrue()
        ->and($this->appointment->fresh()->getRawOriginal())->toBe($before);
});
