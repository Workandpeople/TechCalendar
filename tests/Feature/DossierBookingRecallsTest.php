<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierBooking;
use App\Services\Crm\DossierBookingRecalls;
use App\Services\Crm\DossierWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'mail.delivery_enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Europe/Paris'));
    $this->actor = User::factory()->create(['role' => 1, 'admin' => false, 'notification_mail_enabled' => false]);
    $this->service = Service::create(['name' => 'Test rappel', 'type' => 'COFRAC', 'average_duration_minutes' => 45]);
    $this->dossier = Dossier::create(['reference' => 'RECALL-FIXTURE', 'address' => 'Adresse fictive', 'city' => 'Paris']);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->recalls = app(DossierBookingRecalls::class);
    $this->report = function (array $changes = []): void {
        app(DossierWorkflow::class)->recordProblem($this->actor, $this->operation->fresh(), [
            'comment' => 'Rappeler mardi', 'problem_type' => 'Demande de rapl',
            'recall_date' => '2026-11-10', 'recall_time' => '10:00', 'client_visible' => false,
            ...$changes,
        ]);
    };
    $this->due = fn () => $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Europe/Paris'));
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('arms structured recalls from the dossier, pending booking and placed appointment surfaces', function (string $surface) {
    $data = ['version' => $this->dossier->fresh()->version, 'comment' => 'Rappeler mardi', 'problem_type' => 'Demande de rapl',
        'recall_date' => '2026-11-10', 'recall_time' => '10:00', 'audience' => 'internal'];
    $url = $surface === 'pending' ? route('planner.book.crm-appointments.problem', 'dossier-'.$this->operation->id)
        : route('v2.dossiers.operations.problem', [$this->dossier, $this->operation]);
    if ($surface === 'placed') {
        $appointment = Appointment::forceCreate(['dossier_operation_id' => $this->operation->id,
            'service_id' => $this->service->id, 'technician_id' => $this->actor->id, 'created_by' => $this->actor->id,
            'starts_at' => '2026-11-06 10:00:00', 'ends_at' => '2026-11-06 11:00:00', 'duration_minutes' => 60,
            'customer_first_name' => 'Fictif', 'customer_last_name' => 'Test', 'customer_phone' => '', 'address' => 'Paris', 'status' => 'scheduled']);
        $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
        $url = route('planner.tracking.appointments.problem', $appointment);
    }
    $response = $this->actingAs($this->actor)->postJson($url, $data);
    $surface === 'dossier' ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertOk();
    $source = $this->dossier->activities()->where('kind', 'status_changed')->sole();
    expect($this->operation->fresh()->booking_recall_at)->toBe('2026-11-10 10:00:00')
        ->and((int) $this->operation->fresh()->booking_recall_activity_id)->toBe($source->id);
})->with(['dossier', 'pending', 'placed']);

it('reopens at the exact Paris deadline and remains idempotent without external notifications', function () {
    ($this->report)();
    $version = $this->dossier->fresh()->version;
    $source = $this->operation->fresh()->booking_recall_activity_id;
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:59:59', 'Europe/Paris'));
    expect($this->recalls->process(true)['reopened'])->toBe(0);
    ($this->due)();
    $before = $this->dossier->activities()->count();
    expect($this->recalls->process()['ready'])->toBe(1)->and($this->operation->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS')
        ->and($this->dossier->activities()->count())->toBe($before)->and($this->dossier->fresh()->version)->toBe($version);
    Event::fake(['eloquent.updated: '.DossierOperation::class]);
    expect($this->recalls->process(true)['reopened'])->toBe(1)
        ->and($this->operation->fresh()->legacy_status)->toBe('PRISE DE RDV')
        ->and($this->operation->fresh()->booking_recall_at)->toBeNull()
        ->and($this->operation->fresh()->booking_recall_activity_id)->toBe($source)
        ->and($this->dossier->fresh()->version)->toBe($version + 1);
    $activity = $this->dossier->activities()->latest('id')->first();
    expect($activity->changed_fields['action'])->toBe('booking_recall_due')->and($activity->client_visible)->toBeFalse()
        ->and($activity->delegataire_visible)->toBeFalse()->and($activity->changed_fields['recall_at'])->toBe('2026-11-10T10:00:00+01:00');
    Event::assertNotDispatched('eloquent.updated: '.DossierOperation::class);
    expect($this->recalls->process(true)['reopened'])->toBe(0)
        ->and(app(DossierBooking::class)->pending($this->actor)->pluck('dossier_operation_id')->all())->toContain($this->operation->id);
});

it('uses Paris wall time independently of the application timezone including summer time', function (string $date, string $utc) {
    config(['app.timezone' => 'UTC']);
    ($this->report)(['recall_date' => $date]);
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC')->subSecond());
    expect($this->recalls->process(true)['reopened'])->toBe(0);
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    expect($this->recalls->process(true)['reopened'])->toBe(1);
})->with([['2026-11-10', '2026-11-10 09:00:00'], ['2027-07-01', '2027-07-01 08:00:00']]);

it('uses the start of the Paris day for an optional date without an hour', function (?string $hour) {
    ($this->report)(['problem_type' => 'Problème document', 'recall_time' => $hour]);
    expect($this->operation->fresh()->booking_recall_at)->toBe('2026-11-10 00:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-10 00:00:00', 'Europe/Paris'));
    expect($this->recalls->process(true)['reopened'])->toBe(1);
})->with([null, '']);

it('replaces or removes an earlier recall when another problem is recorded', function (bool $remove) {
    ($this->report)();
    ($this->report)(['recall_date' => $remove ? null : '2026-11-20', 'recall_time' => $remove ? null : '11:00', 'problem_type' => 'Problème document']);
    ($this->due)();
    expect($this->recalls->process(true)['reopened'])->toBe(0)
        ->and($this->operation->fresh()->booking_recall_at)->toBe($remove ? null : '2026-11-20 11:00:00');
})->with([false, true]);

it('does not overwrite later manual status decisions or restore an obsolete recall', function (string $status) {
    ($this->report)();
    app(DossierWorkflow::class)->changeStatus($this->actor, $this->dossier->fresh(), $this->operation->fresh(),
        ['version' => $this->dossier->fresh()->version, 'status' => $status]);
    ($this->due)();
    expect($this->recalls->process(true)['reopened'])->toBe(0)->and($this->operation->fresh()->booking_recall_at)->toBeNull();
    if ($status !== 'problem') {
        app(DossierWorkflow::class)->changeStatus($this->actor, $this->dossier->fresh(), $this->operation->fresh(),
            ['version' => $this->dossier->fresh()->version, 'status' => 'problem']);
    }
    expect($this->recalls->backfill(true)['armed'])->toBe(0)->and($this->recalls->process(true)['reopened'])->toBe(0);
})->with(['awaiting_visit', 'visited', 'rt_control', 'report', 'cancelled']);

it('never overwrites deleted dossiers, superseded history, or scheduled appointments even if the status is inconsistent', function (string $case) {
    ($this->report)();
    if ($case === 'deleted') {
        $this->dossier->delete();
    } elseif ($case === 'history') {
        $this->dossier->activities()->create(['kind' => 'status_changed', 'body' => 'Nouvelle décision',
            'changed_fields' => ['operation_id' => $this->operation->id, 'status' => 'PROBLÈME RENDEZ-VOUS']]);
    } else {
        Appointment::forceCreate(['dossier_operation_id' => $this->operation->id, 'service_id' => $this->service->id,
            'technician_id' => $this->actor->id, 'created_by' => $this->actor->id, 'starts_at' => '2026-11-12 10:00:00',
            'ends_at' => '2026-11-12 11:00:00', 'duration_minutes' => 60, 'customer_first_name' => 'Fictif',
            'customer_last_name' => 'Test', 'customer_phone' => '', 'address' => 'Paris', 'status' => 'scheduled']);
    }
    ($this->due)();
    expect($this->recalls->process()['skipped'])->toBe(1)->and($this->operation->fresh()->booking_recall_at)->not->toBeNull();
    expect($this->recalls->process(true)['reopened'])->toBe(0)->and($this->operation->fresh()->booking_recall_at)->toBeNull()
        ->and($this->operation->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS');
})->with(['deleted', 'history', 'scheduled']);

it('preserves the original problem appointment, comments, documents and other operations', function () {
    ($this->report)();
    $other = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $other->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'Information ajoutée après le rappel.']);
    $comments = $this->dossier->comments()->get()->toArray();
    $appointment = Appointment::forceCreate(['dossier_operation_id' => $this->operation->id, 'service_id' => $this->service->id,
        'technician_id' => $this->actor->id, 'created_by' => $this->actor->id, 'starts_at' => '2026-11-01 10:00:00',
        'ends_at' => '2026-11-01 11:00:00', 'duration_minutes' => 60, 'customer_first_name' => 'Fictif',
        'customer_last_name' => 'Test', 'customer_phone' => '', 'address' => 'Paris', 'status' => 'problem']);
    $before = $appointment->fresh()->getAttributes();
    ($this->due)();
    expect($this->recalls->process(true)['reopened'])->toBe(1)->and($appointment->fresh()->getAttributes())->toBe($before)
        ->and($other->fresh()->legacy_status)->toBe('RAPPORT GENIUS')->and($this->dossier->comments()->get()->toArray())->toBe($comments)
        ->and($this->dossier->documents()->count())->toBe(0);
});

it('prepares historical structured recalls read-only by default then arms them idempotently without reopening', function () {
    ($this->report)();
    $this->operation->forceFill(['booking_recall_at' => null, 'booking_recall_activity_id' => null])->saveQuietly();
    ($this->due)();
    $before = $this->operation->fresh()->getAttributes();
    expect($this->recalls->backfill()['eligible'])->toBe(1)->and($this->operation->fresh()->getAttributes())->toBe($before)
        ->and($this->recalls->backfill(true)['armed'])->toBe(1)
        ->and($this->operation->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS')
        ->and($this->recalls->backfill(true)['armed'])->toBe(0)
        ->and($this->recalls->process(true)['reopened'])->toBe(1);
});

it('does not infer recalls from free text or from superseded or malformed history', function (string $case) {
    ($this->report)();
    $source = $this->dossier->activities()->where('kind', 'status_changed')->sole();
    $fields = $source->changed_fields;
    if ($case === 'malformed') {
        $fields['recall_date'] = '2026-02-31';
    }
    if ($case === 'no_operation') {
        unset($fields['operation_id']);
    }
    if ($case === 'no_date') {
        unset($fields['recall_date']);
    }
    $source->update(['changed_fields' => $fields]);
    if ($case === 'superseded') {
        $this->dossier->activities()->create(['kind' => 'status_changed', 'body' => 'Autre problème sans rappel',
            'changed_fields' => ['operation_id' => $this->operation->id, 'status' => 'PROBLÈME RENDEZ-VOUS']]);
    }
    $this->operation->forceFill(['booking_recall_at' => null, 'booking_recall_activity_id' => null])->saveQuietly();
    expect($this->recalls->backfill(true)['armed'])->toBe(0)->and($this->recalls->process(true)['reopened'])->toBe(0);
})->with(['malformed', 'no_operation', 'no_date', 'superseded']);

it('rejects impossible DST times atomically instead of moving the deadline silently', function () {
    expect(fn () => ($this->report)(['recall_date' => '2027-03-28', 'recall_time' => '02:30']))->toThrow(ValidationException::class);
    expect($this->operation->fresh()->legacy_status)->toBe('PRISE DE RDV')->and($this->dossier->activities()->count())->toBe(0);
});

it('waits for the winter occurrence of a repeated autumn hour without consuming the recall early', function () {
    ($this->report)(['recall_date' => '2027-10-31', 'recall_time' => '02:30']);
    $this->travelTo(CarbonImmutable::parse('2027-10-31 00:30:00', 'UTC'));
    expect($this->recalls->process(true)['reopened'])->toBe(0)->and($this->operation->fresh()->booking_recall_at)->not->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2027-10-31 01:30:00', 'UTC'));
    expect($this->recalls->process(true)['reopened'])->toBe(1);
});

it('offers safe CLI defaults, bounded batches and a backfill cursor', function () {
    ($this->report)();
    ($this->due)();
    $this->artisan('dossiers:process-recalls')->expectsOutputToContain('"dry_run":true')->assertSuccessful();
    expect($this->operation->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS');
    $this->artisan('dossiers:process-recalls', ['--apply' => true])->expectsOutputToContain('"reopened":1')->assertSuccessful();
    foreach ([0, 5001, 'oops'] as $limit) {
        $this->artisan('dossiers:process-recalls', ['--limit' => $limit])->assertFailed();
    }
    $this->artisan('dossiers:process-recalls', ['--backfill' => true, '--after-id' => $this->operation->id])
        ->expectsOutputToContain('"examined":0')->assertSuccessful();
    config(['crm.v2_enabled' => false]);
    $this->artisan('dossiers:process-recalls', ['--apply' => true])->assertFailed();
    config(['crm.v2_enabled' => true, 'simulation.enabled' => true]);
    $this->artisan('dossiers:process-recalls', ['--apply' => true])->assertFailed();
});

it('uses the due-date index instead of scanning the activity history every minute', function () {
    $plan = DB::select('EXPLAIN QUERY PLAN SELECT id FROM dossier_operations WHERE booking_recall_at <= ? ORDER BY booking_recall_at, id LIMIT 200', ['2026-11-10 10:00:00']);
    expect(json_encode($plan))->toContain('dossier_operations_booking_recall_due');
});
