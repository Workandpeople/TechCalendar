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
        'request_id' => $this->uuid, 'action' => 'on_site_rebook', 'dossier_id' => 123, 'status_id' => 14,
        'rdv_id' => null, 'starts_at' => null, 'duration_minutes' => null, 'source_committed' => true];
    $this->mode = 'success';
    $this->posts = 0;
    $this->unsupportedPort = null;
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/v1/capabilities')) {
            return parse_url($request->url(), PHP_URL_PORT) === $this->unsupportedPort
                ? Http::response(['error' => 'booking_endpoint_not_found'], 404)
                : Http::response(['format' => 'cofrac.mymar-booking-capabilities', 'version' => 1, 'on_site_rebook' => true]);
        }
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
    $this->legacy = function (string $state, string $action = 'on_site_rebook') {
        $this->postJson($this->url, ($this->payload)())->assertOk();
        $incident = TechnicianIncident::sole();
        $context = $incident->source_context;
        unset($context['status_decision_pending']);
        $incident->update(['source_context' => $context]);
        $command = MymarBookingCommand::fromArray(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
            'request_id' => $this->uuid, 'action' => $action, 'dossier_id' => 123, 'rdv_id' => 456,
            'technician_id' => 5, 'cofrac_actor_id' => $this->tech->id, 'expected_revision' => str_repeat('a', 64), 'reason' => 'Absence', 'comment' => 'Absent']);

        return MymarBookingRequest::forceCreate(['id' => $this->uuid, 'author_id' => $this->tech->id, 'source_dossier_id' => 123,
            'active_dossier_id' => in_array($state, ['queued', 'uncertain']) ? 123 : null, 'fingerprint' => $command->fingerprint(), 'command' => $command->payload,
            'status' => $state, 'result' => null, 'lease_until' => 0]);
    };

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

it('records local proof and alerts without creating an outbox or changing MYMAR', function () {
    $this->postJson($this->url, ($this->payload)())->assertOk();
    $incident = TechnicianIncident::sole();
    expect($incident->source)->toBe('mymar')->and($incident->source_context['status_decision_pending'])->toBeTrue()
        ->and($incident->planning_status)->toBe('pending')->and($incident->billing_status)->toBe('review')
        ->and(MymarBookingRequest::count())->toBe(0)->and(Dossier::count())->toBe(0)->and(Appointment::count())->toBe(0);
    Storage::disk('local')->assertExists($incident->source_context['photo']['path']);
    expect(getimagesize(Storage::disk('local')->path($incident->source_context['photo']['path']))['mime'])->toBe('image/jpeg');
    expect(Http::recorded(fn ($r) => $r->method() !== 'GET'))->toHaveCount(0);
    $this->get(route('tech.mar.on-site.show', 456))->assertOk()->assertSee('statut MYMAR conservé')->assertDontSee('<script>test', false);
});

it('rejects native historical proof fields on MAR without sending any source command', function (string $field, mixed $value) {
    $this->postJson($this->url, ($this->payload)([$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(TechnicianIncident::count())->toBe(0)->and(MymarBookingRequest::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
    Http::assertNothingSent();
})->with([['evidence_only', true], ['report_context', '{"version":1}'], ['context_signature', str_repeat('a', 64)],
    ['visit_date', '2026-11-06'], ['visit_time', '10:00']]);

it('accepts proof while writer is disabled and ignores unsolicited receipts', function () {
    $this->config['writes_enabled'] = false;
    $this->postJson($this->url, ($this->payload)())->assertOk();
    expect(MymarBookingRequest::count())->toBe(0);
    Http::assertSentCount(1);
});

it('does not require write capabilities to retain proof', function (int $port) {
    $this->unsupportedPort = $port;
    $this->postJson($this->url, ($this->payload)())->assertOk();
    expect(TechnicianIncident::count())->toBe(1)->and(MymarBookingRequest::count())->toBe(0);
    Http::assertSentCount(1);
})->with([19847, 19848]);

it('never dispatches a legacy automatic command that was not sent before upgrade', function () {
    $record = ($this->legacy)('queued', 'on_site_problem');
    $this->post(route('tech.mar.on-site.transmit', 456))->assertRedirect();
    expect($record->fresh()->status)->toBe('rejected')->and(TechnicianIncident::count())->toBe(1);
    expect(Http::recorded(fn ($r) => $r->method() === 'POST'))->toHaveCount(0);
});

it('reconciles old uncertain actions with GET only without another upload or POST', function () {
    $record = ($this->legacy)('uncertain');
    $this->get(route('tech.mar.on-site.show', 456))->assertOk()->assertSee('retour en Prise de RDV après travaux confirmé');
    expect($record->fresh()->status)->toBe('confirmed')->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    expect(Http::recorded(fn ($r) => $r->method() === 'POST'))->toHaveCount(0);
});

it('preserves old rejected proof without claiming success', function () {
    ($this->legacy)('rejected');
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
        ->assertOk()->assertSee('Beneficiaire MAR fictif')->assertSee('Déplacement à vérifier')->assertSee('statut MYMAR conservé');
    $this->get($photo)->assertOk();
    $this->patch(route('planner.incidents.update', $incident), ['section' => 'planning', 'version' => 1, 'status' => 'resolved', 'note' => 'Société prévenue'])->assertRedirect();
    expect($incident->fresh()->planning_status)->toBe('resolved')->and(DB::table('technician_incident_reviews')->count())->toBe(1);
    $this->patch(route('planner.incidents.update', $incident), ['section' => 'billing', 'version' => 2, 'status' => 'billable', 'note' => 'Déplacement validé'])->assertRedirect();
    expect($incident->fresh()->billing_status)->toBe('billable')->and(DB::table('technician_incident_reviews')->count())->toBe(2);
});

it('never allows a technician to enqueue a remote action without the matching persisted proof', function () {
    $client = new MymarBookingClient(Http::getFacadeRoot(), enabled: true, writesEnabled: true, token: str_repeat('0123456789abcdef', 4));
    $command = MymarBookingCommand::fromArray(['format' => 'cofrac.mymar-booking-command', 'version' => 1,
        'request_id' => $this->uuid, 'action' => 'on_site_rebook', 'dossier_id' => 123, 'rdv_id' => 456,
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

it('does not dispatch a legacy queued command during a GET', function () {
    $record = ($this->legacy)('queued');
    $this->get(route('tech.mar.on-site.show', 456))->assertOk();
    expect($record->fresh()->status)->toBe('queued');
    expect(Http::recorded(fn ($r) => $r->method() === 'POST'))->toHaveCount(0);
});

it('retains independent proof even if a previous administrative command is still in flight', function () {
    $busyId = (string) Str::uuid();
    MymarBookingRequest::forceCreate(['id' => $busyId, 'author_id' => $this->office->id, 'source_dossier_id' => 123,
        'active_dossier_id' => 123, 'fingerprint' => str_repeat('b', 64), 'command' => [], 'status' => 'uncertain']);
    $this->postJson($this->url, ($this->payload)())->assertOk();
    expect(TechnicianIncident::count())->toBe(1)->and(MymarBookingRequest::count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    expect(Http::recorded(fn ($r) => $r->method() === 'POST'))->toHaveCount(0);
});

it('refuses schema rollback once MAR proof exists rather than dropping it', function () {
    $this->postJson($this->url, ($this->payload)())->assertOk();
    $migration = require database_path('migrations/2026_11_10_120000_add_mymar_technician_incidents.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'signalements MAR a conserver')
        ->and(TechnicianIncident::count())->toBe(1)->and(Schema::hasTable('technician_incident_reviews'))->toBeTrue();
});
