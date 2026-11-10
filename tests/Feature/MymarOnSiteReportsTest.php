<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\MymarBookingRequest;
use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarBookingClient;
use App\Services\Mymar\MymarBookingCommand;
use App\Services\Mymar\MymarBookingOutbox;
use App\Services\Mymar\MymarBookingReaderSettings;
use App\Services\Mymar\MymarObservationSync;
use App\Services\Tech\MymarOnSiteReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
    if (! Schema::hasTable('mymar_booking_requests')) {
        (require base_path('tools/deploy/mymar/booking/inactive-migrations/2026_10_31_110000_create_mymar_booking_requests_table.php'))->up();
    }
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'must_change_password' => false]);
    $this->office = User::factory()->create(['role' => 0, 'admin' => false, 'must_change_password' => false]);
    $this->config = ['guard_enabled' => true, 'writes_enabled' => true,
        'bindings' => [['source_technician_id' => 5, 'cofrac_user_id' => $this->tech->id]]];
    $this->mock(MymarActionSettings::class, function ($mock) {
        $mock->shouldReceive('load')->andReturnUsing(fn () => $this->config);
        $mock->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    });
    $this->mock(MymarBookingReaderSettings::class, function ($mock) {
        $mock->shouldReceive('load')->andReturn(['read_enabled' => true]);
        $mock->shouldReceive('token')->andReturn(str_repeat('0123456789abcdef', 4));
    });
    $this->mock(MymarObservationSync::class)->shouldReceive('run')->andReturn(['status' => 'observing']);
    $this->source = ['format' => 'cofrac.mymar-scheduled-booking', 'version' => 1,
        'dossier_id' => 123, 'rdv_id' => 456, 'expected_revision' => str_repeat('a', 64),
        'technician_id' => 5, 'starts_at' => '2026-11-10T10:00:00+01:00', 'duration_minutes' => 60,
        'customer' => 'Beneficiaire MAR fictif', 'address' => '1 rue de test, 75001 Paris'];
    $this->uuid = (string) Str::uuid();
    $this->receipt = fn () => ['format' => 'cofrac.mymar-booking-result', 'version' => 1,
        'request_id' => $this->uuid, 'action' => 'on_site_problem', 'dossier_id' => 123, 'status_id' => 15,
        'rdv_id' => null, 'starts_at' => null, 'duration_minutes' => null, 'source_committed' => true];
    $this->mode = 'success';
    $this->posts = 0;
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/scheduled-bookings/')) {
            return Http::response($this->source);
        }
        if ($request->method() === 'POST' && $this->mode === 'timeout') {
            $this->posts++;
            throw new ConnectionException('PRIVATE_GATEWAY_ERROR_CANARY');
        }
        if ($this->mode === 'rejected') {
            return Http::response(['error' => 'booking_source_changed', 'source_committed' => false, 'outcome' => 'rejected'], 409);
        }

        return Http::response(($this->receipt)());
    });
    $this->payload = fn (array $extra = []) => [...[
        'request_id' => $this->uuid, 'snapshot' => app(MymarOnSiteReports::class)->snapshot($this->source, $this->tech),
        'reason' => 'absent', 'comment' => 'Commentaire complet privé au suivi Cofrac <script>test</script>',
        'photo' => UploadedFile::fake()->image('presence.png', 80, 60),
    ], ...$extra];
    $this->url = route('tech.mar.on-site.store', 456);
    $this->actingAs($this->tech);
});

afterEach(function () {
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('offers the mobile MAR form only after validating the current remote assignment', function () {
    $this->get(route('tech.mar.on-site.show', 456))->assertOk()->assertSee('Beneficiaire MAR fictif')
        ->assertSee('capture="environment"', false)->assertSee('data-appointment="mar-456"', false)->assertDontSee('name="audience"', false);
    Http::assertSentCount(1);
    $this->source['technician_id'] = 6;
    $this->get(route('tech.mar.on-site.show', 456))->assertForbidden()->assertDontSee('Beneficiaire MAR fictif');
});

it('records proof and outbox before POST then confirms status fifteen without creating native business data', function () {
    $this->postJson($this->url, ($this->payload)())->assertOk()->assertJsonPath('url', route('tech.mar.on-site.show', 456));
    $incident = TechnicianIncident::sole();
    expect($incident->source)->toBe('mymar')->and($incident->mymar_dossier_id)->toBe(123)->and($incident->mymar_rdv_id)->toBe(456)
        ->and($incident->dossier_id)->toBeNull()->and($incident->appointment_id)->toBeNull()->and($incident->document_id)->toBeNull()
        ->and($incident->planning_status)->toBe('pending')->and($incident->billing_status)->toBe('review')
        ->and($incident->sourceRequest->status)->toBe('confirmed')->and($incident->sourceRequest->result['status_id'])->toBe(15)
        ->and(Dossier::count())->toBe(0)->and(Appointment::count())->toBe(0);
    Storage::disk('local')->assertExists($incident->source_context['photo']['path']);
    expect(getimagesize(Storage::disk('local')->path($incident->source_context['photo']['path']))['mime'])->toBe('image/jpeg');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'http://127.0.0.1:19848/v1/booking-commands'
        && $r['action'] === 'on_site_problem' && $r['cofrac_actor_id'] === $this->tech->id && $r['technician_id'] === 5
        && str_contains($r['comment'], 'Signalement Cofrac #'.$incident->id) && ! str_contains($r['comment'], '<script>'));
    $this->get(route('tech.mar.on-site.show', 456))->assertOk()->assertSee('PB prise de RDV APT confirmé')
        ->assertSee('&lt;script&gt;test', false)->assertDontSee('<script>test', false);
});

it('keeps a timed out action uncertain and reconciles with GET only without another upload or POST', function () {
    $this->mode = 'timeout';
    $data = ($this->payload)();
    $this->postJson($this->url, $data)->assertOk();
    $record = MymarBookingRequest::sole();
    expect($record->status)->toBe('uncertain')->and(TechnicianIncident::count())->toBe(1);
    $this->get(route('tech.mar.on-site.show', 456))->assertOk()->assertSee('confirmation MYMAR en attente');
    $record->forceFill(['lease_until' => 0])->save();
    $this->get(route('tech.mar.on-site.show', 456))->assertOk()->assertSee('PB prise de RDV APT confirmé');
    $this->postJson($this->url, $data)->assertOk();
    expect(MymarBookingRequest::sole()->status)->toBe('confirmed')->and(TechnicianIncident::count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    expect($this->posts)->toBe(1);
});

it('preserves proof but never claims remote success after a proven rejection', function () {
    $this->mode = 'rejected';
    $this->postJson($this->url, ($this->payload)())->assertOk();
    expect(MymarBookingRequest::sole()->status)->toBe('rejected')->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    $this->get(route('tech.mar.on-site.show', 456))->assertOk()->assertSee('changement d’état MYMAR refusé');
});

it('rejects changed snapshots changed mappings and missing source scope before storing anything', function (string $kind) {
    $data = ($this->payload)();
    if ($kind === 'snapshot') {
        $this->source['expected_revision'] = str_repeat('b', 64);
    } elseif ($kind === 'mapping') {
        $this->config['bindings'] = [];
    } else {
        $this->source['technician_id'] = 6;
    }
    $response = $this->postJson($this->url, $data);
    $kind === 'snapshot' ? $response->assertUnprocessable() : $response->assertForbidden();
    expect(TechnicianIncident::count())->toBe(0)->and(MymarBookingRequest::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
    expect(Http::recorded(fn ($r) => $r->method() === 'POST'))->toHaveCount(0);
})->with(['snapshot', 'mapping', 'assignment']);

it('requires a valid photo reason and comment for other without contacting MYMAR', function (array $extra, string $field) {
    $this->postJson($this->url, ($this->payload)($extra))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(TechnicianIncident::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBeEmpty();
    Http::assertNothingSent();
})->with([[['photo' => null], 'photo'], [['reason' => 'unknown'], 'reason'], [['reason' => 'other', 'comment' => ''], 'comment'],
    [['request_id' => 'invalid'], 'request_id'], [['snapshot' => 'invalid'], 'snapshot']]);

it('keeps other technicians out of the proof and the global inbox but includes MAR for authorized planning and accounting', function () {
    $this->postJson($this->url, ($this->payload)())->assertOk();
    $incident = TechnicianIncident::sole();
    $photo = route('tech.mar.on-site.photo', $incident);
    $this->get($photo)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
    $this->get(route('planner.incidents'))->assertForbidden();
    $other = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->actingAs($other)->get($photo)->assertForbidden();
    $this->get(route('tech.mar.on-site.show', 456))->assertForbidden();
    $this->actingAs($this->office)->get(route('planner.incidents', ['q' => 'Beneficiaire Paris', 'source' => 'mymar']))
        ->assertOk()->assertSee('Beneficiaire MAR fictif')->assertSee('Déplacement à vérifier')->assertSee('PB prise de RDV APT confirmé');
    $this->get($photo)->assertOk();
    $this->patch(route('planner.incidents.update', $incident), ['section' => 'planning', 'version' => 1, 'status' => 'resolved', 'note' => 'Société prévenue'])->assertRedirect();
    expect($incident->fresh()->planning_status)->toBe('resolved')->and(DB::table('technician_incident_reviews')->count())->toBe(1);
    $this->patch(route('planner.incidents.update', $incident), ['section' => 'billing', 'version' => 2, 'status' => 'billable', 'note' => 'Déplacement validé'])->assertRedirect();
    expect($incident->fresh()->billing_status)->toBe('billable')->and(DB::table('technician_incident_reviews')->count())->toBe(2);
});

it('never allows a technician to enqueue a remote action without the matching persisted proof', function () {
    $client = new MymarBookingClient(Http::getFacadeRoot(), enabled: true, writesEnabled: true, token: str_repeat('0123456789abcdef', 4));
    $command = MymarBookingCommand::fromArray(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
        'request_id' => $this->uuid, 'action' => 'on_site_problem', 'dossier_id' => 123, 'rdv_id' => 456,
        'technician_id' => 5, 'cofrac_actor_id' => $this->tech->id, 'expected_revision' => str_repeat('a', 64), 'reason' => 'Absence', 'comment' => 'Absent']);
    expect(fn () => (new MymarBookingOutbox($client, true))->enqueue($command, $this->tech))->toThrow(RuntimeException::class, 'booking_author_not_allowed');
    expect(MymarBookingRequest::count())->toBe(0);
    Http::assertNothingSent();
});

it('denies disabled unmapped password gated and office users at the technician endpoint', function (string $kind) {
    if ($kind === 'disabled') {
        $this->tech->delete();
    } elseif ($kind === 'password') {
        $this->tech->update(['must_change_password' => true]);
    } elseif ($kind === 'office') {
        $this->actingAs($this->office);
    } else {
        config(['crm.v2_enabled' => false]);
    }
    $this->postJson($this->url, ($this->payload)())->assertStatus($kind === 'v1' ? 404 : 403);
    expect(TechnicianIncident::count())->toBe(0);
    Http::assertNothingSent();
})->with(['disabled', 'password', 'office', 'v1']);

it('does not dispatch queued commands from a GET or after a fresh assignment change', function () {
    $this->mode = 'timeout';
    $this->postJson($this->url, ($this->payload)())->assertOk();
    $record = MymarBookingRequest::sole();
    // Simulate a crash after the durable outbox commit but before its first dispatch.
    $record->forceFill(['status' => 'queued', 'attempts' => 0, 'lease_until' => null])->save();
    $this->get(route('tech.mar.on-site.show', 456))->assertOk()->assertSee('pas encore transmis');
    $this->source['technician_id'] = 6;
    $this->post(route('tech.mar.on-site.transmit', 456))->assertRedirect();
    expect($record->fresh()->status)->toBe('rejected');
    expect($this->posts)->toBe(1);
});

it('cleans only the new photo and rolls back the incident if another source command is still in flight', function () {
    $busyId = (string) Str::uuid();
    MymarBookingRequest::forceCreate(['id' => $busyId, 'author_id' => $this->office->id, 'source_dossier_id' => 123,
        'active_dossier_id' => 123, 'fingerprint' => str_repeat('b', 64), 'command' => [], 'status' => 'uncertain']);
    $this->postJson($this->url, ($this->payload)())->assertUnprocessable()->assertJsonValidationErrors('snapshot');
    expect(TechnicianIncident::count())->toBe(0)->and(MymarBookingRequest::count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
    expect(Http::recorded(fn ($r) => $r->method() === 'POST'))->toHaveCount(0);
});

it('refuses schema rollback once MAR proof exists rather than dropping it', function () {
    $this->postJson($this->url, ($this->payload)())->assertOk();
    $migration = require database_path('migrations/2026_11_10_120000_add_mymar_technician_incidents.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'signalements MAR a conserver')
        ->and(TechnicianIncident::count())->toBe(1)->and(Schema::hasTable('technician_incident_reviews'))->toBeTrue();
});
