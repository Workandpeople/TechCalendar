<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierLocation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false, 'services.mapbox.token' => null]);
    $this->travelTo(Carbon::parse('2026-01-01 12:00', 'Europe/Paris'));
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false, 'notification_mail_enabled' => false]);
    $this->technician = User::factory()->create(['role' => 2, 'admin' => false, 'notification_mail_enabled' => false,
        'latitude' => 48.85, 'longitude' => 2.35, 'address' => '10 rue du Test, 75001 Paris']);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Visite horaire', 'average_duration_minutes' => 60]);
    $this->technician->services()->attach($this->service);
    $this->dossier = Dossier::create(['reference' => 'TIME-TEST', 'first_name' => 'Camille', 'last_name' => 'HORAIRE',
        'phone' => '0600000000', 'address' => '10 rue du Test', 'postal_code' => '75001', 'city' => 'Paris']);
    $this->dossier->forceFill(['inspection_geocode' => DossierLocation::metadata($this->dossier, [
        'latitude' => 48.85, 'longitude' => 2.35, 'mapbox_id' => 'time-fixture',
    ])])->save();
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->booking = ['crm_appointment_id' => 'dossier-'.$this->operation->id, 'technician_id' => $this->technician->id,
        'starts_at' => '2026-01-12T10:00:00+01:00', 'duration_minutes' => 60];
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

dataset('paris appointment times', [
    'winter local' => ['2026-01-15T16:30', '2026-01-15', '+01:00'],
    'winter UTC' => ['2026-01-15T15:30:00.000Z', '2026-01-15', '+01:00'],
    'summer local' => ['2026-07-15T16:30', '2026-07-15', '+02:00'],
    'summer UTC' => ['2026-07-15T14:30:00Z', '2026-07-15', '+02:00'],
    'spring transition' => ['2026-03-29T16:30:00+02:00', '2026-03-29', '+02:00'],
    'autumn transition' => ['2026-10-25T16:30:00+01:00', '2026-10-25', '+01:00'],
    'foreign offset' => ['2026-07-15T10:30:00-04:00', '2026-07-15', '+02:00'],
]);

it('stores chosen Paris time and returns it after reloading the booking calendar', function (string $input, string $day, string $offset) {
    $this->postJson(route('planner.book.appointments.store'), [...$this->booking, 'starts_at' => $input])->assertCreated()
        ->assertJsonPath('appointment.start', $day.'T16:30:00'.$offset)->assertJsonPath('appointment.end', $day.'T17:30:00'.$offset);
    $appointment = Appointment::sole();
    expect($appointment->getRawOriginal('starts_at'))->toBe($day.' 16:30:00')
        ->and($appointment->getRawOriginal('ends_at'))->toBe($day.' 17:30:00');
    $this->postJson(route('planner.book.calendar-window'), [
        'replace_appointment_id' => $appointment->id, 'technician_ids' => [$this->technician->id],
        'start' => $day.'T00:00:00'.$offset, 'end' => $day.'T23:59:59'.$offset,
    ])->assertOk()->assertJsonPath('events.0.start', $day.'T16:30:00'.$offset)
        ->assertJsonPath('events.0.end', $day.'T17:30:00'.$offset);
})->with('paris appointment times');

it('persists a replacement hour instead of reverting to the original appointment', function (string $input, string $day, string $offset) {
    $this->postJson(route('planner.book.appointments.store'), $this->booking)->assertCreated();
    $appointment = Appointment::sole();
    $this->postJson(route('planner.book.appointments.store'), [
        'replace_appointment_id' => $appointment->id, 'technician_id' => $this->technician->id,
        'starts_at' => $input, 'duration_minutes' => 45,
    ])->assertOk()->assertJsonPath('appointment.start', $day.'T16:30:00'.$offset)
        ->assertJsonPath('appointment.end', $day.'T17:15:00'.$offset);
    expect(Appointment::count())->toBe(1)
        ->and($appointment->fresh()->getRawOriginal('starts_at'))->toBe($day.' 16:30:00')
        ->and($appointment->fresh()->getRawOriginal('ends_at'))->toBe($day.' 17:15:00');
})->with('paris appointment times');

it('saves successive tracking edits and returns the selected Paris hour', function (string $input, string $day, string $offset) {
    $this->postJson(route('planner.book.appointments.store'), $this->booking)->assertCreated();
    $appointment = Appointment::sole();
    $url = route('planner.tracking.appointments.details', $appointment);
    $this->patchJson($url, ['starts_at' => $input, 'duration_minutes' => 45, 'address' => $appointment->address,
        'updated_at' => $appointment->updated_at->toIso8601String()])->assertOk()
        ->assertJsonPath('event.start', $day.'T16:30:00'.$offset)->assertJsonPath('event.end', $day.'T17:15:00'.$offset);
    expect($appointment->fresh()->getRawOriginal('starts_at'))->toBe($day.' 16:30:00');
    $this->travel(2)->seconds();
    $this->patchJson($url, ['starts_at' => $day.'T16:45', 'duration_minutes' => 30, 'address' => $appointment->address,
        'updated_at' => $appointment->fresh()->updated_at->toIso8601String()])->assertOk()
        ->assertJsonPath('event.start', $day.'T16:45:00'.$offset)->assertJsonPath('event.end', $day.'T17:15:00'.$offset);
    $this->postJson(route('planner.tracking.events'), ['technician_ids' => [$this->technician->id],
        'start' => $day, 'end' => $day.'T23:59:59'.$offset])->assertOk()->assertJsonPath('events.0.start', $day.'T16:45:00'.$offset);
})->with('paris appointment times');

it('refuses nonexistent Paris hours on booking and editing without changing the appointment', function () {
    $this->postJson(route('planner.book.appointments.store'), [...$this->booking, 'starts_at' => '2026-03-29T02:30'])
        ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    expect(Appointment::count())->toBe(0);
    $this->postJson(route('planner.book.appointments.store'), $this->booking)->assertCreated();
    $appointment = Appointment::sole();
    $this->patchJson(route('planner.tracking.appointments.details', $appointment), [
        'starts_at' => '2026-03-29T02:30', 'duration_minutes' => 60, 'address' => $appointment->address,
    ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    expect($appointment->fresh()->getRawOriginal('starts_at'))->toBe('2026-01-12 10:00:00');
});

it('does not relabel stored 16h30 as UTC when the PHP default timezone differs', function () {
    $previous = date_default_timezone_get();
    try {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        $this->postJson(route('planner.book.appointments.store'), [...$this->booking, 'starts_at' => '2026-01-15T16:30'])->assertCreated();
        expect(Appointment::sole()->getRawOriginal('starts_at'))->toBe('2026-01-15 16:30:00');
        $this->postJson(route('planner.tracking.events'), ['technician_ids' => [$this->technician->id],
            'start' => '2026-01-15', 'end' => '2026-01-16'])->assertOk()
            ->assertJsonPath('events.0.start', '2026-01-15T16:30:00+01:00');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('normalizes UTC calendar boundaries before querying Paris wall times around midnight', function () {
    $this->postJson(route('planner.book.appointments.store'), [...$this->booking, 'starts_at' => '2026-07-16T00:30'])->assertCreated();
    $this->postJson(route('planner.tracking.events'), ['technician_ids' => [$this->technician->id],
        'start' => '2026-07-15T22:00:00.000Z', 'end' => '2026-07-15T23:00:00.000Z'])->assertOk()
        ->assertJsonCount(1, 'events')->assertJsonPath('events.0.start', '2026-07-16T00:30:00+02:00');
});

it('keeps stale edit protection after a successful hour change', function () {
    $this->postJson(route('planner.book.appointments.store'), $this->booking)->assertCreated();
    $appointment = Appointment::sole();
    $snapshot = $appointment->updated_at->toIso8601String();
    $this->travel(2)->seconds();
    $url = route('planner.tracking.appointments.details', $appointment);
    $this->patchJson($url, ['starts_at' => '2026-01-15T16:30', 'duration_minutes' => 45,
        'address' => $appointment->address, 'updated_at' => $snapshot])->assertOk();
    $this->patchJson($url, ['starts_at' => '2026-01-15T17:30', 'duration_minutes' => 45,
        'address' => $appointment->address, 'updated_at' => $snapshot])->assertUnprocessable()->assertJsonValidationErrors('appointment');
    expect($appointment->fresh()->getRawOriginal('starts_at'))->toBe('2026-01-15 16:30:00');
});

it('refuses a DST occurrence that the current database schema cannot retain', function (string $input) {
    $this->postJson(route('planner.book.appointments.store'), [...$this->booking, 'starts_at' => $input])
        ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    expect(Appointment::count())->toBe(0);
})->with(['2026-10-25T02:30:00+02:00', '2026-10-25T01:30:00+02:00']);

it('uses the offset-preserving replacement flow from both modify pages', function (string $route, int $role, string $start) {
    $this->travelTo(Carbon::parse($start)->subDay()->setTime(12, 0));
    $this->actor->forceFill(['role' => $role])->save();
    $this->postJson(route('planner.book.appointments.store'), [...$this->booking, 'starts_at' => $start])->assertCreated();
    $appointment = Appointment::sole();
    $this->get(route($route, ['replace_appointment_id' => $appointment->id]))->assertOk()
        ->assertViewHas('bookingMode', 'replace')
        ->assertViewHas('initialReplaceAppointmentId', (string) $appointment->id)
        ->assertSee('window.TechCalendarDateTime.formatAppointmentInput(value)', false)
        ->assertSee('window.TechCalendarDateTime.serializeAppointmentInput(payload.starts_at)', false);
    $this->postJson(route('planner.book.analyze'), ['replace_appointment_id' => $appointment->id])->assertOk()
        ->assertJsonPath('events.0.start', $start);
    $edited = Carbon::parse($start)->addDay()->setTime(16, 45)->toIso8601String();
    $this->postJson(route('planner.book.appointments.store'), [
        'replace_appointment_id' => $appointment->id, 'technician_id' => $this->technician->id,
        'starts_at' => $edited, 'duration_minutes' => 45,
    ])->assertOk()->assertJsonPath('appointment.start', $edited);
    expect($appointment->fresh()->getRawOriginal('starts_at'))->toBe(Carbon::parse($edited)->format('Y-m-d H:i:s'));
})->with([
    'planner winter' => ['planner.appointments.modify', 1, '2026-01-15T16:30:00+01:00'],
    'planner summer' => ['planner.appointments.modify', 1, '2026-07-15T16:30:00+02:00'],
    'manager winter' => ['manager.appointments.modify', 0, '2026-01-15T16:30:00+01:00'],
    'manager summer' => ['manager.appointments.modify', 0, '2026-07-15T16:30:00+02:00'],
]);

it('does not rewrite historical wall times or infer a lost UTC offset when reading calendars', function () {
    $this->postJson(route('planner.book.appointments.store'), $this->booking)->assertCreated();
    $appointment = Appointment::sole();
    // Simulate a pre-fix row: the original request offset is no longer recoverable.
    DB::table('appointments')->where('id', $appointment->id)->update([
        'starts_at' => '2026-01-15 15:30:00', 'ends_at' => '2026-01-15 16:30:00',
    ]);
    $before = DB::table('appointments')->where('id', $appointment->id)->first(['starts_at', 'ends_at', 'updated_at']);
    $this->postJson(route('planner.tracking.events'), ['technician_ids' => [$this->technician->id],
        'start' => '2026-01-15', 'end' => '2026-01-16'])->assertOk()
        ->assertJsonPath('events.0.start', '2026-01-15T15:30:00+01:00');
    $this->postJson(route('planner.book.calendar-window'), [
        'replace_appointment_id' => $appointment->id, 'technician_ids' => [$this->technician->id],
        'start' => '2026-01-15', 'end' => '2026-01-16',
    ])->assertOk()->assertJsonPath('events.0.start', '2026-01-15T15:30:00+01:00');
    $after = DB::table('appointments')->where('id', $appointment->id)->first(['starts_at', 'ends_at', 'updated_at']);
    expect($after)->toEqual($before);
});
