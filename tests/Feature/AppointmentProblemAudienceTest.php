<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\Service;
use App\Models\User;
use App\Services\CoffracAppointmentService;
use App\Services\Crm\ClientAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Test', 'average_duration_minutes' => 60]);
    $this->dossier = Dossier::create(['reference' => 'PROBLEM-AUDIENCE', 'address' => 'Paris', 'city' => 'Paris']);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

function problemAudienceEndpoint($test, string $surface): string
{
    if ($surface === 'pending') {
        return route('planner.book.crm-appointments.problem', 'dossier-'.$test->operation->id);
    }
    if ($surface === 'dossier') {
        return route('v2.dossiers.operations.problem', [$test->dossier, $test->operation]);
    }
    $appointment = Appointment::forceCreate(['dossier_operation_id' => $test->operation->id,
        'technician_id' => $test->actor->id, 'service_id' => $test->service->id, 'created_by' => $test->actor->id,
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'duration_minutes' => 60,
        'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'customer_phone' => '', 'address' => 'Paris', 'status' => 'scheduled']);
    $test->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();

    return route('planner.tracking.appointments.problem', $appointment);
}

it('saves the chosen audience and an optional callback together on every problem surface', function (string $surface, string $audience, bool $client, bool $delegate) {
    $url = problemAudienceEndpoint($this, $surface);
    $response = $this->postJson($url, ['version' => $this->dossier->fresh()->version,
        'comment' => 'Le client sera disponible demain.', 'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_DOCUMENT,
        'recall_date' => '2026-11-12', 'recall_time' => '10:30', 'audience' => $audience]);
    if ($surface === 'dossier') {
        $response->assertRedirect()->assertSessionHasNoErrors();
    } else {
        $response->assertOk();
    }
    $comment = $this->dossier->comments()->sole();
    expect($comment->client_visible)->toBe($client)->and($comment->delegataire_visible)->toBe($delegate)
        ->and($comment->body)->toContain('Le client sera disponible demain.', 'Rappel prévu le 12/11/2026 à 10:30.')
        ->and($comment->changed_fields['recall_date'])->toBe('2026-11-12')
        ->and(app(ClientAccess::class)->commentVisibleToClient($comment, 'client'))->toBe($client)
        ->and(app(ClientAccess::class)->commentVisibleToClient($comment, 'delegataire'))->toBe($delegate);
    $history = $this->dossier->activities()->where('kind', 'status_changed')->sole();
    expect($history->client_visible)->toBeFalse()->and($history->delegataire_visible)->toBeFalse();
    if ($surface === 'placed') {
        expect(Appointment::sole()->external_payload['techcalendar_problem']['client_visible'])->toBe($client);
    }
})->with(['pending', 'placed', 'dossier'])->with([
    ['internal', false, false], ['client', true, false], ['shared', true, true],
]);

it('defaults stale planning forms to client only and accepts a date without an hour for other reasons', function () {
    $this->postJson(problemAudienceEndpoint($this, 'pending'), [
        'comment' => 'Rappeler le client', 'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_DOCUMENT, 'recall_date' => '2026-11-12',
    ])->assertOk();
    $comment = $this->dossier->comments()->sole();
    expect($comment->client_visible)->toBeTrue()->and($comment->delegataire_visible)->toBeFalse()
        ->and($comment->body)->toContain('Rappel prévu le 12/11/2026.');
});

it('rejects invalid audience and incomplete or malformed reminders atomically', function (array $invalid, array $fields) {
    $url = problemAudienceEndpoint($this, 'placed');
    $this->postJson($url, ['comment' => 'Rappeler le client', 'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_DOCUMENT, ...$invalid])
        ->assertUnprocessable()->assertJsonValidationErrors($fields);
    expect($this->dossier->comments()->count())->toBe(0)->and(Appointment::sole()->status)->toBe('scheduled');
})->with([
    [['audience' => 'everyone'], ['audience']],
    [['recall_time' => '10:30'], ['recall_date']],
    [['recall_date' => '2026-02-31'], ['recall_date']],
    [['recall_date' => '2026-11-12', 'recall_time' => '25:00'], ['recall_time']],
    [['problem_type' => CoffracAppointmentService::PROBLEM_TYPE_CALLBACK], ['recall_date', 'recall_time']],
]);

it('renders three independent audience pickers with client selected on planning pages', function () {
    foreach (['planner.book' => ['booking_crm_problem_audience', 'booking_problem_audience'], 'planner.tracking' => ['tracking_problem_audience']] as $route => $ids) {
        $response = $this->get(route($route))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent(), LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        foreach ($ids as $id) {
            expect($xpath->query('//select[@id="'.$id.'"]/option')->length)->toBe(3)
                ->and($xpath->query('//select[@id="'.$id.'"]/option[@selected]')->item(0)->getAttribute('value'))->toBe('client');
        }
    }
});

it('does not grant external users the right to declare problems', function () {
    $url = problemAudienceEndpoint($this, 'placed');
    $this->actingAs(User::factory()->create(['admin' => false, 'role' => 3]))->postJson($url, [
        'comment' => 'Essai externe', 'problem_type' => CoffracAppointmentService::PROBLEM_TYPE_DOCUMENT, 'audience' => 'shared',
    ])->assertForbidden();
    expect($this->dossier->comments()->count())->toBe(0);
});
