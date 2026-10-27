<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalAppointmentRequest;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use App\Services\SidebarNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->planner = User::factory()->create(['admin' => false, 'role' => 1]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Inspection test', 'average_duration_minutes' => 60]);
    $this->dossier = Dossier::create(['reference' => 'TEST-NOTIFICATIONS', 'address' => '1 rue du Test', 'city' => 'Lyon']);
    $this->lot = Lot::create(['name' => 'Lot test', 'type' => Lot::TYPE_HYBRID_LOCATION_CONTACT, 'created_by' => $this->planner->id, 'status' => Lot::STATUS_IN_PROGRESS]);
    $this->operation = function (string $status = 'PRISE DE RDV') {
        $operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
        $operation->forceFill(['legacy_status' => $status])->save();

        return $operation;
    };
    $this->appointment = function ($operation) {
        return Appointment::forceCreate([
            'service_id' => $this->service->id, 'technician_id' => $this->planner->id, 'created_by' => $this->planner->id,
            'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '0100000000',
            'address' => '1 rue du Test', 'starts_at' => today()->addDay()->setTime(10, 0), 'ends_at' => today()->addDay()->setTime(11, 0),
            'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED, 'dossier_operation_id' => $operation->id,
        ]);
    };
});

it('counts distinct native pending dossiers including replacements without adding lot or legacy copies', function () {
    ($this->operation)();
    ($this->operation)();
    foreach (['RDV ATTENTE VISITE', 'DOSSIERS FACTURER', 'PROBLÈME RENDEZ-VOUS', 'DOSSIER ANNULE'] as $status) {
        ($this->operation)($status);
    }
    ($this->appointment)(($this->operation)());
    LotAppointment::create(['lot_id' => $this->lot->id, 'customer_name' => 'Dossier du lot', 'status' => LotAppointment::STATUS_PENDING]);
    ExternalAppointmentRequest::create(['source' => 'coffrac', 'external_reference' => 'legacy-copy', 'status' => ExternalAppointmentRequest::STATUS_PENDING]);

    expect(app(SidebarNotifications::class)->forUser($this->planner)['pending'])->toBe(1);
    Http::assertNothingSent();
});

it('counts only lot rows still eligible for processing', function (array $attributes, bool $archived, int $expected) {
    config(['crm.v2_enabled' => false]);
    if ($archived) {
        $this->lot->update(['status' => Lot::STATUS_ARCHIVED]);
    }
    LotAppointment::create(array_merge([
        'lot_id' => $this->lot->id, 'customer_name' => 'Dossier du lot', 'status' => LotAppointment::STATUS_PENDING,
    ], $attributes));

    expect(app(SidebarNotifications::class)->forUser($this->planner)['pending'])->toBe($expected);
})->with([
    'pending without GPS' => [[], false, 1],
    'awaiting correction' => [['status' => LotAppointment::STATUS_NEEDS_REVIEW], false, 1],
    'do not place' => [['status' => LotAppointment::STATUS_NOT_PLACED], false, 0],
    'placed' => [['status' => LotAppointment::STATUS_PLACED], false, 0],
    'completed contact' => [['status' => LotAppointment::STATUS_CONTACT_PROCESSED], false, 0],
    'contact mode' => [['processing_mode' => LotAppointment::PROCESSING_MODE_CONTACT], false, 0],
    'satisfactory contact' => [['contact_satisfaction' => true], false, 0],
    'unsatisfactory contact' => [['contact_satisfaction' => false], false, 0],
    'physical mode' => [['processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL, 'contact_satisfaction' => true], false, 1],
    'excluded row' => [['excluded_from_lot_stats' => true], false, 0],
    'archived lot' => [[], true, 0],
]);

it('excludes linked lot rows even if their old status still says pending', function () {
    config(['crm.v2_enabled' => false]);
    $appointment = ($this->appointment)(($this->operation)());
    LotAppointment::create(['lot_id' => $this->lot->id, 'customer_name' => 'Dossier du lot', 'status' => LotAppointment::STATUS_PENDING, 'appointment_id' => $appointment->id]);
    expect(app(SidebarNotifications::class)->forUser($this->planner)['pending'])->toBe(0);
});

it('updates the count on the next page load without a stale cache', function () {
    $operation = ($this->operation)();
    $notifications = app(SidebarNotifications::class);
    expect($notifications->forUser($this->planner)['pending'])->toBe(1);
    $appointment = ($this->appointment)($operation);
    expect($notifications->forUser($this->planner)['pending'])->toBe(1);
    $appointment->delete();
    expect($notifications->forUser($this->planner)['pending'])->toBe(1);
    $operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    expect($notifications->forUser($this->planner)['pending'])->toBe(0);
});

it('does not query pending dossiers for users without the planning section', function (int $role) {
    $user = User::factory()->create(['admin' => false, 'role' => $role]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $count = app(SidebarNotifications::class)->forUser($user)['pending'];
    $queries = collect(DB::getQueryLog())->pluck('query')->implode('\n');
    DB::disableQueryLog();

    expect($count)->toBe(0)->and($queries)->not->toContain('dossier_operations', 'lot_appointments', 'external_appointment_requests');
})->with([0, 2]);

it('counts the local legacy backlog outside v2 respecting ignored references', function () {
    config(['crm.v2_enabled' => false, 'services.coffrac.ignored_references' => [' ignored ']]);
    ($this->operation)();
    foreach (['pending', 'placed', 'problem', 'archived'] as $status) {
        ExternalAppointmentRequest::create(['source' => 'coffrac', 'external_reference' => $status, 'status' => $status]);
    }
    ExternalAppointmentRequest::create(['source' => 'coffrac', 'external_reference' => 'ignored', 'status' => 'pending']);
    ExternalAppointmentRequest::create(['source' => 'other', 'external_reference' => 'other', 'status' => 'pending']);
    $appointment = ($this->appointment)(($this->operation)());
    ExternalAppointmentRequest::create(['source' => 'coffrac', 'external_reference' => 'linked', 'status' => 'pending', 'appointment_id' => $appointment->id]);

    expect(app(SidebarNotifications::class)->forUser($this->planner)['pending'])->toBe(1);
    Http::assertNothingSent();
});
