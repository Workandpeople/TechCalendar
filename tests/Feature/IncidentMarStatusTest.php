<?php

use App\Models\MymarBookingRequest;
use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarBookingCommand;
use App\Services\Mymar\MymarBookingReaderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    if (! Schema::hasTable('mymar_booking_requests')) {
        (require base_path('tools/deploy/mymar/booking/inactive-migrations/2026_10_31_110000_create_mymar_booking_requests_table.php'))->up();
    }
    Mail::fake();
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->create(['role' => 0, 'admin' => true, 'must_change_password' => false]);
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->writes = true;
    $this->mock(MymarActionSettings::class, function ($m) {
        $m->shouldReceive('load')->andReturnUsing(fn () => ['guard_enabled' => true, 'writes_enabled' => $this->writes, 'bindings' => []]);
        $m->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    });
    $this->mock(MymarBookingReaderSettings::class, function ($m) {
        $m->shouldReceive('load')->andReturn(['read_enabled' => true]);
        $m->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    });
    $this->incident = TechnicianIncident::create(['source' => 'mymar', 'request_id' => (string) Str::uuid(), 'technician_id' => $this->tech->id,
        'mymar_dossier_id' => 123, 'mymar_rdv_id' => 456, 'reason' => 'absent', 'comment' => 'Client absent',
        'reported_at' => now(), 'appointment_starts_at' => now(), 'source_context' => ['customer' => 'Exemple MAR', 'address' => 'Adresse fictive',
            'starts_at' => '2026-11-20T10:00:00+01:00', 'status_decision_pending' => true, 'photo' => ['path' => 'kept.jpg']]]);
    $this->capable = true;
    $this->revision = str_repeat('a', 64);
    $this->mode = 'ok';
    $this->posts = 0;
    $this->command = null;
    Http::fake(function ($r) {
        if (str_ends_with($r->url(), '/capabilities')) {
            return Http::response(['format' => 'cofrac.mymar-booking-capabilities', 'version' => 1,
                'on_site_rebook' => true, 'incident_status_review' => $this->capable]);
        }
        if (str_contains($r->url(), '/incident-dossiers/')) {
            return Http::response(['format' => 'cofrac.mymar-incident-context', 'version' => 1,
                'dossier_id' => 123, 'status_id' => 16, 'expected_revision' => $this->revision,
                'statuses' => collect(MymarBookingCommand::REVIEW_STATUSES)->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values()->all()]);
        }
        if ($r->method() === 'POST') {
            $this->posts++;
            $this->command = $r->data();
            if ($this->mode === 'timeout') {
                throw new ConnectionException('PRIVATE_TRANSPORT_CANARY');
            }
            if ($this->mode === 'reject') {
                return Http::response(['error' => 'booking_source_changed', 'source_committed' => false, 'outcome' => 'rejected'], 409);
            }
        }

        return Http::response(['format' => 'cofrac.mymar-booking-result', 'version' => 1,
            'request_id' => $this->command['request_id'], 'action' => 'review_status', 'dossier_id' => 123,
            'status_id' => $this->mode === 'wrong' ? 15 : $this->command['target_status_id'],
            'rdv_id' => null, 'starts_at' => null, 'duration_minutes' => null, 'source_committed' => true]);
    });
    $this->actingAs($this->admin);
    $this->context = fn () => $this->getJson(route('planner.incidents.status.context', $this->incident))->assertOk()->json('context');
    $this->input = fn ($target = '14') => ['context' => ($this->context)(), 'target_status' => $target, 'planning_status' => 'resolved', 'note' => 'Société contactée'];
    $this->url = route('planner.incidents.status.update', $this->incident);
});
afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('only reads remote context when the admin explicitly opens a status decision', function () {
    $this->get(route('planner.incidents'))->assertOk()->assertSee('data-incident-status', false);
    Http::assertNothingSent();
    expect(($this->context)())->toBeString()->and($this->posts)->toBe(0)->and(MymarBookingRequest::count())->toBe(0);
});

it('confirms an explicit MAR status once and keeps evidence and accounting independent', function (string $target) {
    $input = ($this->input)($target);
    $this->postJson($this->url, $input)->assertOk()->assertJsonPath('state', 'confirmed');
    expect($this->posts)->toBe(1)->and($this->command['action'])->toBe('review_status')->and($this->command['target_status_id'])->toBe((int) $target)
        ->and($this->incident->fresh()->planning_status)->toBe('resolved')->and($this->incident->fresh()->billing_status)->toBe('review')
        ->and($this->incident->fresh()->source_context['photo']['path'])->toBe('kept.jpg')->and($this->incident->fresh()->statusRequest->status)->toBe('confirmed')
        ->and(DB::table('technician_incident_reviews')->count())->toBe(1);
    $stored = $this->incident->fresh()->source_context;
    $stored['status_review'] = array_reverse($stored['status_review'], true);
    $this->incident->update(['source_context' => $stored]);
    $this->postJson($this->url, $input)->assertOk()->assertJsonPath('state', 'confirmed');
    expect($this->posts)->toBe(1)->and(DB::table('technician_incident_reviews')->count())->toBe(1);
})->with(['14', '15', '21', '19']);

it('keeps uncertain MAR decisions pending and reconciles by GET without a second POST', function () {
    $input = ($this->input)();
    $this->mode = 'timeout';
    $this->postJson($this->url, $input)->assertOk()->assertJsonPath('state', 'uncertain');
    expect($this->incident->fresh()->planning_status)->toBe('pending')->and($this->posts)->toBe(1);
    $this->getJson(route('planner.incidents.status.context', $this->incident))->assertOk()->assertJsonPath('context', null);
    MymarBookingRequest::sole()->forceFill(['lease_until' => 0])->save();
    $this->mode = 'ok';
    $this->postJson(route('planner.incidents.status.resume', $this->incident))->assertOk()->assertJsonPath('state', 'confirmed');
    expect($this->posts)->toBe(1)->and($this->incident->fresh()->planning_status)->toBe('resolved');
});

it('never accepts a receipt for a different target as a confirmed status', function () {
    $input = ($this->input)();
    $this->mode = 'wrong';
    $this->postJson($this->url, $input)->assertOk()->assertJsonPath('state', 'uncertain');
    expect($this->incident->fresh()->planning_status)->toBe('pending');
});

it('preserves followup and evidence after a proven remote rejection', function () {
    $input = ($this->input)();
    $this->mode = 'reject';
    $this->postJson($this->url, $input)->assertOk()->assertJsonPath('state', 'rejected');
    expect($this->incident->fresh()->planning_status)->toBe('pending')->and($this->incident->fresh()->billing_status)->toBe('review');
});

it('refuses changed source snapshots unknown targets and stale incident versions before enqueue', function (string $case) {
    $input = ($this->input)();
    if ($case === 'source') {
        $this->revision = str_repeat('b', 64);
    }
    if ($case === 'target') {
        $input['target_status'] = '1';
    }
    if ($case === 'incident') {
        $this->incident->increment('version');
    }
    $this->postJson($this->url, $input)->assertUnprocessable();
    expect(MymarBookingRequest::count())->toBe(0)->and($this->posts)->toBe(0);
})->with(['source', 'target', 'incident']);

it('requires both updated gateways and an enabled writer for an administrative decision', function (string $case) {
    if ($case === 'capability') {
        $this->capable = false;
    } else {
        $this->writes = false;
    }
    $this->getJson(route('planner.incidents.status.context', $this->incident))->assertStatus(503);
    expect(MymarBookingRequest::count())->toBe(0)->and($this->posts)->toBe(0);
})->with(['capability', 'writer']);

it('does not overwrite a newer followup or accounting decision during late MAR confirmation', function () {
    $input = ($this->input)();
    $this->mode = 'timeout';
    $this->postJson($this->url, $input)->assertOk()->assertJsonPath('state', 'uncertain');
    $this->incident->refresh()->update(['planning_status' => 'pending', 'planning_note' => 'Nouvelle décision manuelle', 'billing_status' => 'billable', 'version' => 3]);
    MymarBookingRequest::sole()->forceFill(['lease_until' => 0])->save();
    $this->mode = 'ok';
    $this->postJson(route('planner.incidents.status.resume', $this->incident))->assertOk()->assertJsonPath('state', 'confirmed');
    expect($this->incident->fresh()->planning_status)->toBe('pending')->and($this->incident->fresh()->planning_note)->toBe('Nouvelle décision manuelle')
        ->and($this->incident->fresh()->billing_status)->toBe('billable')->and($this->posts)->toBe(1);
});

it('retires a proven unsent legacy automatic action before an explicit admin decision', function () {
    $command = MymarBookingCommand::fromArray(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
        'request_id' => $this->incident->request_id, 'action' => 'on_site_rebook', 'cofrac_actor_id' => $this->tech->id,
        'dossier_id' => 123, 'rdv_id' => 456, 'technician_id' => 5, 'expected_revision' => $this->revision, 'comment' => 'Absence ancienne', 'reason' => 'Absent']);
    $legacy = MymarBookingRequest::forceCreate(['id' => $this->incident->request_id, 'author_id' => $this->tech->id, 'source_dossier_id' => 123,
        'active_dossier_id' => 123, 'command' => $command->payload, 'fingerprint' => $command->fingerprint(), 'status' => 'queued']);
    $input = ($this->input)();
    $this->postJson($this->url, $input)->assertOk()->assertJsonPath('state', 'confirmed');
    expect($legacy->fresh()->status)->toBe('rejected')->and($this->posts)->toBe(1)->and($this->command['action'])->toBe('review_status');
});

it('does not replace an uncertain legacy command with a new administrative write', function () {
    MymarBookingRequest::forceCreate(['id' => (string) Str::uuid(), 'author_id' => $this->admin->id, 'source_dossier_id' => 123,
        'active_dossier_id' => 123, 'command' => [], 'fingerprint' => str_repeat('a', 64), 'status' => 'uncertain']);
    $input = ($this->input)();
    $this->postJson($this->url, $input)->assertUnprocessable()->assertJsonValidationErrors('context');
    expect($this->posts)->toBe(0)->and(MymarBookingRequest::count())->toBe(1)->and($this->incident->fresh()->version)->toBe(1);
});

it('keeps the administrative status and internal accounting note out of the technician confirmation', function () {
    $input = ($this->input)('22');
    $this->postJson($this->url, $input)->assertOk()->assertJsonPath('state', 'confirmed');
    $this->actingAs($this->tech)->get(route('tech.mar.on-site.show', 456))->assertOk()->assertSee('Décision administrative confirmée dans MYMAR')
        ->assertDontSee('DEPLACEMENT A FACTURER')->assertDontSee('Société contactée');
});
