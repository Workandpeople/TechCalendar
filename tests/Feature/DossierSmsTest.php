<?php

use App\Jobs\SendDossierSms;
use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierSms;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Sms\DossierSmsProcessor;
use App\Services\Sms\DossierSmsService;
use App\Services\Sms\SmsConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['crm.v2_enabled' => true, 'simulation.enabled' => false, 'services.smsfactor.enabled' => true,
        'services.smsfactor.mode' => 'simulate', 'services.smsfactor.sender' => 'Genius', 'services.smsfactor.token' => 'fake-sms-secret',
        'services.smsfactor.queue_connection' => 'database', 'services.smsfactor.queue' => 'default']);
    $this->manager = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->dossier = Dossier::create(['reference' => 'SMS-TEST', 'company_name' => 'Client Test', 'phone' => '06 12 34 56 78', 'address' => '10 rue Test', 'postal_code' => '75001', 'city' => 'Paris']);
    $this->smsInput = ['recipient' => '06 12 34 56 78', 'body' => 'Bonjour {client_name}', 'appointment_id' => null];
    $this->actingAs($this->manager);
    $this->queueSms = function (array $overrides = []) {
        $service = app(DossierSmsService::class);
        $preview = $service->preview($this->manager, $this->dossier, [...$this->smsInput, ...$overrides]);

        return $service->queue($this->manager, $this->dossier, $preview['preview_token']);
    };
});

it('previews without sending or queuing and reuses the legacy templates safely', function () {
    DB::table('legacy_records')->insert(['source_table' => 'sms_tempalte', 'source_id' => 1, 'payload' => json_encode(['titre' => '<Modèle>', 'message' => '<p>Bonjour {client_name}</p>'])]);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('SMS au bénéficiaire')->assertSee('&lt;Modèle&gt;', false)->assertDontSee('fake-sms-secret');
    $this->postJson(route('v2.dossiers.sms.preview', $this->dossier), $this->smsInput)->assertOk()
        ->assertJsonPath('recipient', '+33612345678')->assertJsonPath('body', 'Bonjour Client Test')->assertJsonPath('mode', 'simulate');
    expect(DossierSms::count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('reads UTF32 archived SMS templates and skips damaged rows without reactivating sending', function () {
    $encode = fn (string $text) => base64_encode(mb_convert_encoding($text, 'UTF-32BE', 'UTF-8'));
    $valid = ['titre_base64' => $encode('Rappel confirmé'), 'titre_charset' => 'utf32',
        'message_base64' => $encode('Bonjour {client_name}'), 'message_charset' => 'utf32'];
    $rows = [$valid, [...$valid, 'message_base64' => 'invalid'], [...$valid, 'titre_charset' => 'unknown'],
        [...$valid, 'message_base64' => $encode('<p></p>')], [...$valid, 'message' => 'Conflicting text'],
        [...$valid, 'message_base64' => $encode(str_repeat('a', 1501))]];
    foreach ($rows as $index => $row) {
        DB::table('legacy_records')->insert(['source_table' => 'sms_tempalte', 'source_id' => $index + 1, 'payload' => json_encode($row)]);
    }
    config(['services.smsfactor.enabled' => false]);
    expect(app(DossierSmsService::class)->templates())->toBe([['id' => 1, 'name' => 'Rappel confirmé', 'body' => 'Bonjour {client_name}']])
        ->and(config('services.smsfactor.enabled'))->toBeFalse()
        ->and(DB::table('dossier_sms')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('requires an explicit confirmation and an untampered preview for the same actor and dossier', function () {
    $service = app(DossierSmsService::class);
    $token = $service->preview($this->manager, $this->dossier, $this->smsInput)['preview_token'];
    $url = route('v2.dossiers.sms.store', $this->dossier);
    $this->postJson($url, ['preview_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
    $this->postJson($url, ['preview_token' => 'invalid', 'confirmed' => true])->assertUnprocessable();
    $other = Dossier::create(['reference' => 'SMS-OTHER', 'address' => 'Autre adresse', 'city' => 'Paris']);
    $this->postJson(route('v2.dossiers.sms.store', $other), ['preview_token' => $token, 'confirmed' => true])->assertUnprocessable();
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->actingAs($planner)->postJson($url, ['preview_token' => $token, 'confirmed' => true])->assertUnprocessable();
    $this->travel(21)->minutes();
    $this->actingAs($this->manager)->postJson($url, ['preview_token' => $token, 'confirmed' => true])->assertUnprocessable();
    expect(DossierSms::count())->toBe(0);
    Http::assertNothingSent();
});

it('locks the approved text and encrypts it while deduplicating repeated submissions', function () {
    $preview = app(DossierSmsService::class)->preview($this->manager, $this->dossier, $this->smsInput);
    $url = route('v2.dossiers.sms.store', $this->dossier);
    $payload = ['preview_token' => $preview['preview_token'], 'confirmed' => true, 'body' => 'tampered', 'recipient' => '+33999999999'];
    $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect(route('v2.dossiers.show', $this->dossier).'#sms');
    $this->post($url, $payload)->assertSessionHasNoErrors();
    $sms = DossierSms::sole();
    expect($sms->body)->toBe('Bonjour Client Test')->and($sms->recipient)->toBe('+33612345678')
        ->and($sms->getRawOriginal('body'))->not->toContain('Bonjour Client Test')
        ->and($sms->toArray())->not->toHaveKeys(['body', 'recipient']);
    expect($this->dossier->activities()->sole()->client_visible)->toBeFalse();
    Queue::assertPushed(SendDossierSms::class, fn ($job) => $job->smsId === $sms->id && $job->connection === 'database' && $job->queue === 'default');
    Http::assertNothingSent();
    expect(fn () => ($this->queueSms)())->toThrow(ValidationException::class, 'déjà été confirmé');
});

it('renders the exact selected appointment instead of the first dossier operation', function () {
    $service = Service::create(['name' => 'Prestation', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $tech = User::factory()->create(['first_name' => 'Tech', 'last_name' => 'Choisi', 'role' => 2]);
    $operation = $this->dossier->operations()->create(['service_id' => $service->id]);
    $attributes = ['service_id' => $service->id, 'technician_id' => $tech->id, 'created_by' => $this->manager->id,
        'customer_first_name' => 'Jean', 'customer_last_name' => 'Test', 'customer_phone' => '0600000000',
        'address' => 'Test', 'latitude' => 48, 'longitude' => 2, 'duration_minutes' => 60,
        'starts_at' => '2026-10-20 10:00:00', 'ends_at' => '2026-10-20 11:00:00'];
    $operation->appointments()->create($attributes);
    $chosen = $operation->appointments()->create([...$attributes, 'starts_at' => '2026-10-22 14:00:00', 'ends_at' => '2026-10-22 15:00:00']);
    $this->postJson(route('v2.dossiers.sms.preview', $this->dossier), [...$this->smsInput, 'appointment_id' => $chosen->id, 'body' => '{date_rdv} / {technicien}'])
        ->assertOk()->assertJsonPath('body', '22/10/2026 de 14:00 à 15:00 / Tech Choisi');
    $foreign = Appointment::create($attributes);
    $this->postJson(route('v2.dossiers.sms.preview', $this->dossier), [...$this->smsInput, 'appointment_id' => $foreign->id])->assertNotFound();
    $this->postJson(route('v2.dossiers.sms.preview', $this->dossier), [...$this->smsInput, 'body' => '{date_rdv}'])->assertUnprocessable();
    $this->postJson(route('v2.dossiers.sms.preview', $this->dossier), [...$this->smsInput, 'body' => '{inconnue}'])->assertUnprocessable();
});

it('denies SMS preview creation and history to external users and technicians', function (int $role) {
    $user = User::factory()->create(['role' => $role, 'admin' => false]);
    if ($role === 3) {
        $client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'sms-client', 'name' => 'Client externe', 'is_active' => true]);
        $user->forceFill(['external_client_id' => $client->id, 'portal_enabled' => true])->save();
    }
    $this->actingAs($user);
    $this->postJson(route('v2.dossiers.sms.preview', $this->dossier), $this->smsInput)->assertForbidden();
    $this->postJson(route('v2.dossiers.sms.store', $this->dossier), ['preview_token' => 'fake', 'confirmed' => true])->assertForbidden();
    $this->getJson(route('v2.dossiers.sms.status', $this->dossier))->assertForbidden();
    Http::assertNothingSent();
})->with([2, 3]);

it('runs a simulated send exactly once with server side credentials and correlation id', function () {
    $sms = ($this->queueSms)();
    Http::fake(['https://api.smsfactor.com/send/simulate' => Http::response(['status' => 1, 'sent' => 1, 'cost' => 1])]);
    $processor = app(DossierSmsProcessor::class);
    $processor->process($sms->id);
    $processor->process($sms->id);
    (new SendDossierSms($sms->id))->handle($processor);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer fake-sms-secret')
        && $request['sms']['message']['pushtype'] === 'alert'
        && $request['sms']['recipients']['gsm'][0]['value'] === '33612345678'
        && $request['sms']['recipients']['gsm'][0]['gsmsmsid'] === $sms->submission_token);
    expect($sms->fresh()->status)->toBe('simulated')
        ->and($this->dossier->activities()->where('client_visible', true)->count())->toBe(0);
    $this->getJson(route('v2.dossiers.sms.status', $this->dossier))->assertOk()->assertJsonPath('active', false)->assertDontSee('fake-sms-secret');
});

it('distinguishes accepted live sending from delivery', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.smsfactor.mode' => 'live']);
    $sms = ($this->queueSms)();
    Http::fake(['https://api.smsfactor.com/send' => Http::response(['status' => 1, 'sent' => 1, 'ticket' => '123456'])]);
    app(DossierSmsProcessor::class)->process($sms->id);
    expect($sms->fresh()->status)->toBe('accepted')->and($sms->fresh()->statusLabel())->toContain('livraison non confirmée');
});

it('handles refusal uncertainty filtering and malformed responses without retry or raw response leaks', function (array|string $response, int $http, string $expected) {
    Log::spy();
    $sms = ($this->queueSms)();
    Http::fake(['*' => Http::response($response, $http)]);
    app(DossierSmsProcessor::class)->process($sms->id);
    app(DossierSmsProcessor::class)->process($sms->id);
    Http::assertSentCount(1);
    expect($sms->fresh()->status)->toBe($expected)->and($sms->fresh()->last_error)->not->toContain('secret-response');
    $this->postJson(route('v2.dossiers.sms.resume', [$this->dossier, $sms]))->assertUnprocessable();
})->with([
    'provider refusal' => [['status' => -2, 'message' => 'secret-response'], 200, 'rejected'],
    'auth refusal' => [['message' => 'secret-response'], 403, 'rejected'],
    'filtered' => [['status' => 1, 'sent' => 0, 'blacklisted' => 1], 200, 'rejected'],
    'provider uncertainty' => [['status' => -8, 'message' => 'secret-response'], 200, 'unknown'],
    'server error' => ['secret-response', 500, 'unknown'],
    'malformed JSON' => ['<html>secret-response</html>', 200, 'unknown'],
    'missing confirmation' => [['status' => 1], 200, 'unknown'],
    'redirect' => ['', 302, 'unknown'],
]);

it('treats timeout as unknown and does not repeat the call on job retries', function () {
    $sms = ($this->queueSms)();
    Http::fake(fn () => throw new ConnectionException('secret-response'));
    app(DossierSmsProcessor::class)->process($sms->id);
    expect($sms->fresh()->status)->toBe('unknown')->and($sms->fresh()->last_error)->not->toContain('secret-response');
    Http::fake(fn () => throw new RuntimeException('should not call'));
    app(DossierSmsProcessor::class)->process($sms->id);
    Http::assertNothingSent();
});

it('blocks queued sends after permission removal configuration change or excessive age', function (string $change) {
    $sms = ($this->queueSms)();
    match ($change) {
        'user' => $this->manager->delete(),
        'mode' => config(['services.smsfactor.mode' => 'live']),
        'disabled' => config(['services.smsfactor.enabled' => false]),
        'sender' => config(['services.smsfactor.sender' => 'Other']),
        'age' => $this->travel(25)->hours(),
    };
    app(DossierSmsProcessor::class)->process($sms->id);
    expect($sms->fresh()->status)->toBe('blocked');
    Http::assertNothingSent();
})->with(['user', 'mode', 'disabled', 'sender', 'age']);

it('keeps queued confirmations recoverable when dispatch fails', function () {
    $service = app(DossierSmsService::class);
    $preview = $service->preview($this->manager, $this->dossier, $this->smsInput);
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue-secret'));
    $sms = $service->queue($this->manager, $this->dossier, $preview['preview_token']);
    expect($sms->status)->toBe('queued')->and($sms->last_error)->toContain('Queue indisponible')->not->toContain('queue-secret');
    Http::assertNothingSent();
});

it('exposes configuration diagnostics without secrets or network requests', function () {
    $this->artisan('sms:check')->expectsOutputToContain('Mode effectif : simulate')->assertSuccessful();
    config(['services.smsfactor.sender' => 'GenuisControle']);
    expect(app(SmsConfiguration::class)->unavailable())->toContain('11 caractères');
    $this->artisan('sms:check')->assertFailed();
    Http::assertNothingSent();
});

it('forces provider simulation in local demo and simulation datasets', function (string $environment, bool $simulation) {
    app()->detectEnvironment(fn () => $environment);
    config(['services.smsfactor.mode' => 'live', 'simulation.enabled' => $simulation]);
    expect(app(SmsConfiguration::class)->mode())->toBe('simulate');
})->with([['demo', false], ['local', false], ['testing', true]]);
