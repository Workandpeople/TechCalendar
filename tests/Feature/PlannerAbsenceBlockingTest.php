<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Services\Crm\AppointmentSlotAvailability;
use App\Services\TechnicianAbsenceBlocking;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->technicians = User::factory()->count(2)->create(['role' => 2, 'admin' => false]);
    $this->payload = ['technician_ids' => $this->technicians->modelKeys(), 'date' => '2026-11-02', 'period' => 'day', 'reason' => ' Formation '];
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

function absenceBlockingAppointment($test, array $attributes = []): Appointment
{
    $service = Service::create(['type' => 'COFFRAC', 'name' => 'Visite', 'average_duration_minutes' => 60]);

    return Appointment::forceCreate($attributes + [
        'service_id' => $service->id, 'technician_id' => $test->technicians->last()->id,
        'created_by' => $test->actor->id, 'customer_first_name' => 'Client', 'customer_last_name' => 'TEST',
        'customer_phone' => '0600000000', 'address' => '10 rue du Test, Paris',
        'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 10:00:00',
        'duration_minutes' => 60, 'status' => Appointment::STATUS_SCHEDULED,
    ]);
}

it('lets managers planners and administrators atomically block active technicians', function (int $role, bool $admin) {
    $actor = User::factory()->create(['role' => $role, 'admin' => $admin]);
    $this->actingAs($actor)->postJson(route('planner.absences.store'), $this->payload)
        ->assertCreated()->assertJsonPath('count', 2)->assertJsonCount(2, 'absences');
    expect(TechnicianAbsence::count())->toBe(2);
    foreach (TechnicianAbsence::all() as $absence) {
        expect($absence->created_by)->toBe($actor->id)
            ->and($absence->starts_at->format('Y-m-d H:i:s'))->toBe('2026-11-02 00:00:00')
            ->and($absence->ends_at->format('Y-m-d H:i:s'))->toBe('2026-11-02 23:59:59')
            ->and($absence->reason)->toBe('Formation');
    }
})->with([[0, false], [1, false], [0, true], [2, true]]);

it('uses explicit local half day boundaries including daylight saving dates', function (string $date, string $period, string $start, string $end, string $offset) {
    $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['date' => $date, 'period' => $period]))
        ->assertCreated()->assertJsonPath('absences.0.starts_at', $date.'T'.$start.$offset);
    $absence = TechnicianAbsence::firstOrFail();
    expect($absence->starts_at->format('H:i:s'))->toBe($start)->and($absence->ends_at->format('H:i:s'))->toBe($end);
})->with([
    ['2026-11-02', 'morning', '00:00:00', '11:59:59', '+01:00'],
    ['2026-11-02', 'afternoon', '12:00:00', '23:59:59', '+01:00'],
    ['2026-03-29', 'day', '00:00:00', '23:59:59', '+01:00'],
    ['2026-10-25', 'afternoon', '12:00:00', '23:59:59', '+01:00'],
]);

it('allows adjacent morning and afternoon absences but rejects duplicate submissions', function () {
    $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['period' => 'morning']))->assertCreated();
    $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['period' => 'afternoon']))->assertCreated();
    $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['period' => 'afternoon']))
        ->assertUnprocessable()->assertJsonValidationErrors('absence_block')->assertJsonCount(2, 'conflicts');
    expect(TechnicianAbsence::count())->toBe(4);
});

it('rejects the entire batch and details an existing multi day absence without overwriting it', function () {
    $existing = TechnicianAbsence::create([
        'technician_id' => $this->technicians->last()->id, 'created_by' => $this->actor->id,
        'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-03 23:59:59', 'reason' => 'Congés existants',
    ]);
    $before = $existing->fresh()->getRawOriginal();
    $this->postJson(route('planner.absences.store'), $this->payload)->assertUnprocessable()
        ->assertJsonValidationErrors('absence_block')->assertJsonPath('conflicts.0.type', 'absence')
        ->assertJsonPath('conflicts.0.id', $existing->id)->assertJsonPath('conflicts.0.reason', 'Congés existants')
        ->assertJsonPath('conflicts.0.technician_id', $this->technicians->last()->id);
    expect(TechnicianAbsence::count())->toBe(1)->and($existing->fresh()->getRawOriginal())->toBe($before);
});

it('reports every absence and active appointment conflict and never edits appointments', function () {
    $appointment = absenceBlockingAppointment($this);
    $before = $appointment->fresh()->getRawOriginal();
    TechnicianAbsence::create(['technician_id' => $this->technicians->first()->id,
        'starts_at' => '2026-11-02 15:00:00', 'ends_at' => '2026-11-02 16:00:00']);
    $this->postJson(route('planner.absences.store'), $this->payload)->assertUnprocessable()
        ->assertJsonCount(2, 'conflicts')->assertJsonPath('conflicts.1.type', 'appointment')
        ->assertJsonPath('conflicts.1.id', $appointment->id);
    expect(TechnicianAbsence::count())->toBe(1)->and($appointment->fresh()->getRawOriginal())->toBe($before);
});

it('detects appointments crossing the selected period including midnight', function (string $period, string $start, string $end) {
    absenceBlockingAppointment($this, ['starts_at' => $start, 'ends_at' => $end]);
    $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['period' => $period]))
        ->assertUnprocessable()->assertJsonPath('conflicts.0.type', 'appointment');
    expect(TechnicianAbsence::count())->toBe(0)->and(Appointment::count())->toBe(1);
})->with([
    ['morning', '2026-11-01 23:30:00', '2026-11-02 00:30:00'],
    ['morning', '2026-11-02 11:30:00', '2026-11-02 12:30:00'],
    ['afternoon', '2026-11-02 11:30:00', '2026-11-02 12:30:00'],
    ['afternoon', '2026-11-02 23:30:00', '2026-11-03 00:30:00'],
]);

it('does not conflict with appointments that only touch a half day boundary', function (string $period, string $start, string $end) {
    $appointment = absenceBlockingAppointment($this, ['starts_at' => $start, 'ends_at' => $end]);
    $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['period' => $period]))->assertCreated();
    expect($appointment->fresh()->trashed())->toBeFalse();
})->with([
    ['morning', '2026-11-02 12:00:00', '2026-11-02 13:00:00'],
    ['afternoon', '2026-11-02 11:00:00', '2026-11-02 12:00:00'],
    ['day', '2026-11-01 23:00:00', '2026-11-02 00:00:00'],
    ['day', '2026-11-03 00:00:00', '2026-11-03 01:00:00'],
]);

it('ignores non operational appointments without deleting them', function (string $state) {
    $appointment = absenceBlockingAppointment($this, ['status' => $state === 'deleted' ? 'scheduled' : $state]);
    if ($state === 'deleted') {
        $appointment->delete();
    }
    $before = Appointment::withTrashed()->findOrFail($appointment->id)->getRawOriginal();
    $this->postJson(route('planner.absences.store'), $this->payload)->assertCreated();
    expect(Appointment::withTrashed()->findOrFail($appointment->id)->getRawOriginal())->toBe($before);
})->with(['problem', 'cancelled', 'deleted']);

it('does not block on an archived dossier appointment', function () {
    $appointment = absenceBlockingAppointment($this);
    $dossier = Dossier::create(['reference' => 'ABSENCE-ARCHIVE', 'created_by' => $this->actor->id, 'source' => 'manual',
        'address' => '10 rue du Test', 'postal_code' => '75001', 'city' => 'Paris']);
    $operation = $dossier->operations()->create(['service_id' => $appointment->service_id]);
    $appointment->forceFill(['dossier_operation_id' => $operation->id])->save();
    $dossier->delete();
    $this->postJson(route('planner.absences.store'), $this->payload)->assertCreated();
    expect($appointment->fresh()->trashed())->toBeFalse();
});

it('accepts empty reasons and does not trust a supplied creator', function (mixed $reason) {
    $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['reason' => $reason, 'created_by' => $this->technicians->first()->id]))->assertCreated();
    expect(TechnicianAbsence::firstOrFail()->reason)->toBeNull()->and(TechnicianAbsence::firstOrFail()->created_by)->toBe($this->actor->id);
})->with([null, '', '   ']);

it('rejects invalid payloads before creating any absence', function (array $changes, string $field) {
    $this->postJson(route('planner.absences.store'), array_replace($this->payload, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(TechnicianAbsence::count())->toBe(0);
})->with([
    [['technician_ids' => []], 'technician_ids'],
    [['technician_ids' => '1,2'], 'technician_ids'],
    [['technician_ids' => ['one']], 'technician_ids.0'],
    [['technician_ids' => [1, 1]], 'technician_ids.0'],
    [['technician_ids' => array_fill(0, 201, 1)], 'technician_ids'],
    [['technician_ids' => ['arbitrary' => 1]], 'technician_ids'],
    [['date' => '2026-02-30'], 'date'],
    [['date' => '2026-11-02T10:00'], 'date'],
    [['period' => 'night'], 'period'],
    [['reason' => str_repeat('x', 256)], 'reason'],
]);

it('rejects the whole selection when one target is no longer an eligible active technician', function (string $state) {
    $technician = $this->technicians->last();
    match ($state) {
        'deleted' => $technician->delete(),
        'manager' => $technician->update(['role' => 0]),
        'planner' => $technician->update(['role' => 1]),
        'external' => $technician->update(['role' => 3]),
        'admin' => $technician->update(['admin' => true]),
        'missing' => $technician->forceDelete(),
    };
    $this->postJson(route('planner.absences.store'), $this->payload)->assertUnprocessable()->assertJsonValidationErrors('technician_ids');
    expect(TechnicianAbsence::count())->toBe(0);
})->with(['deleted', 'manager', 'planner', 'external', 'admin', 'missing']);

it('lists only active internal non administrator technicians and no private contact details', function () {
    User::factory()->create(['role' => 0]);
    User::factory()->create(['role' => 2, 'admin' => true]);
    User::factory()->create(['role' => 3]);
    User::factory()->create(['role' => 2])->delete();
    $externalClient = ExternalDelegataire::create(['source' => 'native', 'kind' => 'client', 'external_id' => 'absence-test', 'name' => 'Client']);
    User::factory()->create(['role' => 2, 'external_client_id' => $externalClient->id]);
    $response = $this->getJson(route('planner.absences.technicians'))->assertOk()->assertJsonCount(2, 'technicians')
        ->assertJsonPath('max_technicians', 200)->assertHeader('Cache-Control', 'no-store, private');
    expect(collect($response->json('technicians'))->pluck('id')->sort()->values()->all())->toBe($this->technicians->modelKeys());
    expect(array_keys($response->json('technicians.0')))->toBe(['id', 'label']);
});

it('protects both endpoints and the service with the same authorization', function (string $state) {
    $actor = User::factory()->create(['role' => $state === 'external' ? 3 : ($state === 'technician' ? 2 : 0), 'admin' => false,
        'must_change_password' => $state === 'password']);
    if ($state === 'deleted') {
        $actor->delete();
    }
    $this->actingAs($actor)->getJson(route('planner.absences.technicians'))->assertForbidden();
    $this->actingAs($actor)->postJson(route('planner.absences.store'), $this->payload)->assertForbidden();
    expect(fn () => app(TechnicianAbsenceBlocking::class)->block($actor, $this->payload))->toThrow(AuthorizationException::class);
    expect(TechnicianAbsence::count())->toBe(0);
})->with(['technician', 'external', 'password', 'deleted']);

it('requires authentication on both endpoints', function () {
    auth()->logout();
    $this->getJson(route('planner.absences.technicians'))->assertUnauthorized();
    $this->postJson(route('planner.absences.store'), $this->payload)->assertUnauthorized();
});

it('is not available outside v2', function () {
    config(['crm.v2_enabled' => false]);
    $this->getJson(route('planner.absences.technicians'))->assertNotFound();
    $this->postJson(route('planner.absences.store'), $this->payload)->assertNotFound();
    expect(Gate::allows('create', TechnicianAbsence::class))->toBeFalse();
});

it('validates direct service calls and rolls back the first insert if a subsequent write fails', function () {
    $blocking = app(TechnicianAbsenceBlocking::class);
    expect(fn () => $blocking->block($this->actor, array_replace($this->payload, ['period' => 'night'])))
        ->toThrow(ValidationException::class);
    Event::listen('eloquent.creating: '.TechnicianAbsence::class, function (TechnicianAbsence $absence) {
        if ($absence->technician_id === $this->technicians->last()->id) {
            expect(TechnicianAbsence::count())->toBe(1);
            throw new RuntimeException('Simulated second insert failure');
        }
    });
    try {
        expect(fn () => $blocking->block($this->actor, $this->payload))->toThrow(RuntimeException::class, 'Simulated second insert failure');
    } finally {
        Event::forget('eloquent.creating: '.TechnicianAbsence::class);
    }
    expect(TechnicianAbsence::count())->toBe(0);
});

it('preserves validation errors for a non JSON caller', function () {
    absenceBlockingAppointment($this);
    $this->from(route('planner.tracking'))->post(route('planner.absences.store'), $this->payload)
        ->assertRedirect(route('planner.tracking'))->assertSessionHasErrors('absence_block');
    expect(TechnicianAbsence::count())->toBe(0);
});

it('makes successful blocks visible to the existing v2 placement availability checks', function () {
    $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['period' => 'morning']))->assertCreated();
    $availability = app(AppointmentSlotAvailability::class);
    $date = now()->setDate(2026, 11, 2);
    $checks = $availability->inspect($this->technicians->first(), $date->copy()->setTime(9, 0), $date->copy()->setTime(10, 0), []);
    expect($checks['conflicts'][0]['type'])->toBe('absence');
    $checks = $availability->inspect($this->technicians->first(), $date->copy()->setTime(12, 0), $date->copy()->setTime(13, 0), []);
    expect($checks['conflicts'])->toBe([]);
});

it('renders the reusable modal only for authorized actors without depending on page data', function () {
    $html = view('planner.partials.absence-block-modal')->render();
    expect($html)->toContain('planning-absence-modal', 'name="date"', 'name="period"', 'name="reason"', 'name="_token"', 'planning:absences-created');
    $this->actingAs($this->technicians->first());
    expect(trim(view('planner.partials.absence-block-modal')->render()))->toBe('');
});

it('always stores and serializes Paris wall times independently of the PHP and application timezone', function (string $date, string $period, string $start, string $end) {
    $previousTimezone = date_default_timezone_get();
    try {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['date' => $date, 'period' => $period]))
            ->assertCreated()->assertJsonPath('absences.0.starts_at', $start)->assertJsonPath('absences.0.ends_at', $end);
        expect(TechnicianAbsence::firstOrFail()->getRawOriginal('starts_at'))->toBe(str_replace('T', ' ', substr($start, 0, 19)))
            ->and(TechnicianAbsence::firstOrFail()->getRawOriginal('ends_at'))->toBe(str_replace('T', ' ', substr($end, 0, 19)));
        $this->postJson(route('planner.absences.store'), array_replace($this->payload, ['date' => $date, 'period' => $period]))
            ->assertUnprocessable()->assertJsonPath('conflicts.0.starts_at', $start)->assertJsonPath('conflicts.0.ends_at', $end);
    } finally {
        date_default_timezone_set($previousTimezone);
    }
})->with([
    ['2026-01-15', 'morning', '2026-01-15T00:00:00+01:00', '2026-01-15T11:59:59+01:00'],
    ['2026-07-15', 'afternoon', '2026-07-15T12:00:00+02:00', '2026-07-15T23:59:59+02:00'],
    ['2026-03-29', 'day', '2026-03-29T00:00:00+01:00', '2026-03-29T23:59:59+02:00'],
    ['2026-10-25', 'day', '2026-10-25T00:00:00+02:00', '2026-10-25T23:59:59+01:00'],
]);

it('defaults the modal to the Paris date even just after local midnight with UTC configured', function () {
    config(['app.timezone' => 'UTC']);
    $this->travelTo(Carbon::parse('2026-07-15T22:30:00Z'));
    try {
        $html = view('planner.partials.absence-block-modal')->render();
        expect($html)->toContain('value="2026-07-16"', 'Horaires locaux : Europe/Paris');
    } finally {
        $this->travelBack();
    }
});
