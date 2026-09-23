<?php

use App\Jobs\AssignGlobalPlusTechnicianJob;
use App\Jobs\CreateGlobalPlusDemandJob;
use App\Models\Appointment;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use App\Services\GlobalPlus\GlobalPlusAppointmentService;
use App\Services\GlobalPlus\GlobalPlusAssignmentPendingException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Bus::fake([CreateGlobalPlusDemandJob::class, AssignGlobalPlusTechnicianJob::class]);
    $this->runGlobalPlusJobs = function (): void {
        $operation = data_get($this->row->refresh()->global_plus_payload, 'workflow.id');
        $creation = Bus::dispatched(CreateGlobalPlusDemandJob::class)->first(fn ($job) => $job->operationId === $operation);
        $assignment = Bus::dispatched(AssignGlobalPlusTechnicianJob::class)->first(fn ($job) => $job->operationId === $operation);
        if ($creation) {
            try {
                $creation->handle(app(GlobalPlusAppointmentService::class));
            } catch (Throwable $exception) {
                $creation->failed($exception);

                return;
            }
            $assignment = unserialize($creation->chained[0]);
        }
        if ($assignment) {
            for ($attempt = 1; $attempt <= $assignment->tries; $attempt++) {
                try {
                    $assignment->handle(app(GlobalPlusAppointmentService::class));
                    break;
                } catch (Throwable $exception) {
                    if ($attempt === $assignment->tries) {
                        $assignment->failed($exception);
                    }
                }
            }
        }
    };
    $this->submitAndRun = function (string $routeName, array $payload) {
        $response = $this->postJson(route($routeName, $this->row), $payload);
        if (! $response->isSuccessful()) {
            return $response;
        }
        $response->assertAccepted();
        ($this->runGlobalPlusJobs)();

        return $this->getJson(route('manager.lots.appointments.global-plus.status', $this->row));
    };
    Http::preventStrayRequests();
    config(['services.global_plus.api_url' => 'https://global-plus.test', 'services.global_plus.api_key' => 'fake', 'services.global_plus.bureau_id' => 1035]);
    $actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $tech = User::factory()->create(['role' => 2, 'email' => 'tech@example.test']);
    $service = Service::create(['type' => 'BAR EN 101', 'name' => 'BAR EN 101', 'average_duration_minutes' => 90]);
    $appointment = Appointment::create([
        'technician_id' => $tech->id, 'service_id' => $service->id, 'created_by' => $actor->id,
        'customer_first_name' => 'Camille', 'customer_last_name' => 'Martin', 'customer_phone' => '0600000000',
        'address' => '12 Rue Inspection', 'latitude' => 49.5, 'longitude' => 2.5,
        'starts_at' => '2026-10-01 10:00:00', 'ends_at' => '2026-10-01 11:30:00', 'duration_minutes' => 90,
    ]);
    $lot = Lot::create(['name' => 'Lot client', 'delegataire' => 'Delegataire TC', 'type' => Lot::TYPE_FULL_CONTROL, 'created_by' => $actor->id]);
    $this->row = LotAppointment::create([
        'lot_id' => $lot->id, 'appointment_id' => $appointment->id, 'service_id' => $service->id,
        'status' => LotAppointment::STATUS_PLACED, 'processing_mode' => LotAppointment::PROCESSING_MODE_PHYSICAL,
        'internal_reference' => 'ALVEA-ACT-1616542/OP-2261616', 'external_reference' => '16013', 'source' => 'coffrac',
        'customer_name' => 'Beneficiaire SAS', 'company_name' => 'Beneficiaire SAS',
        'customer_first_name' => 'Camille', 'customer_last_name' => 'Martin', 'customer_email' => 'client@example.test',
        'address' => '12 Rue Inspection', 'postal_code' => '60110', 'city' => 'Esches',
        'beneficiary_address' => '10 Rue Siege', 'beneficiary_postal_code' => '75002', 'beneficiary_city' => 'Paris',
        'installer_siren' => '348808007', 'installer_name' => 'Nouveau nom installateur',
    ]);
    $this->payload = ['client_address_id' => 700, 'client_delegataire_confirmed' => true, 'installer_address_id' => 901, 'controller_id' => 2198, 'version_formulaire_id' => 3310, 'send_documents' => false];
    $this->remote = ['id' => 8123, 'idDemande' => 5637, 'idControleur' => 2198, 'dateIntervention' => '2026-10-01T10:00:00', 'dateInterventionEnd' => '2026-10-01T11:30:00'];
    $this->interventionList = 'default';
    $this->interventionListStatus = 200;
    $this->verificationForbidden = false;
    $this->remoteReadCount = 0;
    $this->globalPlusLogs = new TestHandler;
    Log::channel('global_plus')->getLogger()->pushHandler($this->globalPlusLogs);
    $this->clients = [['clientId' => 1234, 'adresseClient' => ['id' => 700, 'raisonSociale' => 'Delegataire Global', 'adresse' => '1 Rue Delegataire']]];
    $this->forbiddenPath = null;
    Http::fake(fn ($request) => $this->forbiddenPath !== null && str_ends_with($request->url(), $this->forbiddenPath) ? Http::response('', 403) : match ($request->url()) {
        'https://global-plus.test/api/Auth/token' => Http::response(['token' => 'fake-token']),
        'https://global-plus.test/api/Client/Liste' => Http::response($this->clients),
        'https://global-plus.test/api/Entreprise/Liste' => Http::response([['idEntreprise' => 42, 'adresseEntreprise' => ['id' => 901, 'raisonSociale' => 'Ancien nom installateur', 'siren' => '348 808 007']]]),
        'https://global-plus.test/api/Auth/Controllers' => Http::response([['id' => 2198, 'email' => 'tech@example.test', 'etat' => true]]),
        'https://global-plus.test/api/VersionFormulaire/GetVersionFormulaires/true' => Http::response([['versionFormulaireId' => 3310, 'id' => 31, 'libelle' => 'BAR EN 101', 'actif' => true]]),
        'https://global-plus.test/api/Demande' => Http::response('"5637"'),
        'https://global-plus.test/api/Intervention/ByDemande/5637' => $this->verificationForbidden && ++$this->remoteReadCount > 1 ? Http::response([], 403) : Http::response($this->interventionList === 'default' ? [$this->remote] : $this->interventionList, $this->interventionListStatus),
        'https://global-plus.test/api/Intervention/Patch/8123' => Http::response(null, 204),
        'https://global-plus.test/api/Demande/changeDemandFiles/5637' => Http::response(['result' => true]),
        default => throw new RuntimeException('Unexpected request: '.$request->url()),
    });
    $this->actingAs($actor);
});

afterEach(function () {
    Http::assertNotSent(fn ($request) => $request->method() === 'GET'
        && (str_contains($request->url(), 'ListInterventions') || preg_match('#/api/(?:Demande|Intervention)/\d+(?:$|\?)#', $request->url())));
});

it('matches by SIREN and sends beneficiary, inspection, client and reference to their distinct destinations', function () {
    $service = app(GlobalPlusAppointmentService::class);
    expect($service->referenceDataFor($this->row)['suggested_installer_address_id'])->toBe(901);
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'created');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://global-plus.test/api/Demande'
        && $request['subTitle'] === 'ALVEA-ACT-1616542/OP-2261616'
        && $request['title'] === 'Lot Lot client'
        && $request['client']['id'] === 700 && $request['client']['raisonSociale'] === 'Delegataire Global'
        && $request['lieuInspection']['adresse'] === '12 Rue Inspection'
        && $request['lieuInspection']['codePostal'] === '60110'
        && $request['beneficiaire']['adresse'] === '10 Rue Siege'
        && $request['beneficiaire']['codePostal'] === '75002'
        && $request['beneficiaire']['prenom'] === 'Camille' && $request['beneficiaire']['nom'] === 'Martin'
        && $request['beneficiaire']['email'] === 'client@example.test'
        && collect(['client', 'lieuInspection', 'beneficiaire', 'entreprise'])->every(fn ($field) => $request[$field]['civilite'] === 'M.')
    );
    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request->url() === 'https://global-plus.test/api/Intervention/Patch/8123'
        && $request->data()[0] === ['op' => 'replace', 'path' => '/idControleur', 'value' => 2198]);
    expect($this->row->refresh()->global_plus_intervention_id)->toBe('8123');
});

it('suggests only the lot delegataire and rejects a different existing client', function () {
    $this->row->lot->update(['delegataire' => 'DÉLÉGATAIRE GLOBAL']);
    $this->clients[] = ['adresseClient' => ['id' => 701, 'raisonSociale' => 'Beneficiaire SAS']];
    $references = app(GlobalPlusAppointmentService::class)->referenceDataFor($this->row);
    expect($references['suggested_client_address_id'])->toBe(700)
        ->and($references['matching_client_address_ids'])->toBe([700]);
    $this->payload['client_address_id'] = 701;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertUnprocessable()->assertJsonPath('message', 'Le client Global+ sélectionné ne correspond pas au délégataire du lot : DÉLÉGATAIRE GLOBAL.');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Demande'));
});

it('matches the delegataire company name without matching the beneficiary company', function () {
    ExternalDelegataire::create(['source' => 'coffrac', 'external_id' => '1', 'name' => 'Delegataire TC', 'company_name' => 'Delegataire Global', 'is_active' => true]);
    $this->clients[] = ['adresseClient' => ['id' => 701, 'raisonSociale' => 'Beneficiaire SAS']];
    expect(app(GlobalPlusAppointmentService::class)->referenceDataFor($this->row)['suggested_client_address_id'])->toBe(700);
    unset($this->payload['client_delegataire_confirmed']);
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk();
});

it('does not silently match a beneficiary when the delegataire is absent from Global', function () {
    $this->clients[] = ['adresseClient' => ['id' => 701, 'raisonSociale' => 'Beneficiaire SAS']];
    expect(app(GlobalPlusAppointmentService::class)->referenceDataFor($this->row)['suggested_client_address_id'])->toBeNull();
    unset($this->payload['client_delegataire_confirmed']);
    $this->postJson(route('planner.book.lots.appointments.global-plus.store', $this->row), $this->payload)
        ->assertUnprocessable()->assertJsonPath('message', 'Confirme que le client Global+ sélectionné correspond bien au délégataire du lot, et non au bénéficiaire ou à l’installateur.');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Demande'));
});

it('leaves ambiguous delegataire matches for explicit selection', function () {
    $this->row->lot->update(['delegataire' => 'Delegataire Global']);
    $this->clients[] = ['adresseClient' => ['id' => 701, 'raisonSociale' => 'Delegataire Global']];
    $references = app(GlobalPlusAppointmentService::class)->referenceDataFor($this->row);
    expect($references['suggested_client_address_id'])->toBeNull()->and($references['matching_client_address_ids'])->toBe([700, 701]);
});

it('identifies a forbidden assignment step and retries without duplicate demand or token renewal', function ($path, $stage, $method) {
    $this->forbiddenPath = $path;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    $this->row->refresh();
    $diagnostic = $this->row->global_plus_payload['appointment_assignment'];
    expect($diagnostic['stage'])->toBe($stage)->and($diagnostic['http_status'])->toBe(403)
        ->and($diagnostic['http_method'])->toBe($method)->and($diagnostic['api_path'])->toBe($path)
        ->and($this->row->global_plus_error_message)->toContain($method.' '.$path, 'HTTP 403')
        ->and($diagnostic['patch_accepted_at'] !== null)->toBe($stage === 'verify_assignment');
    if ($stage === 'resolve_intervention') {
        expect($this->row->global_plus_intervention_id)->toBeNull();
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }
    $this->forbiddenPath = null;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'created');
    expect(Http::recorded(fn ($request) => $request->url() === 'https://global-plus.test/api/Demande'))->toHaveCount(1)
        ->and(Http::recorded(fn ($request) => str_ends_with($request->url(), '/Auth/token')))->toHaveCount(1)
        ->and(Http::recorded(fn ($request) => str_ends_with($request->url(), '/Intervention/ByDemande/5637')))->toHaveCount(3);
})->with([
    ['/api/Intervention/ByDemande/5637', 'resolve_intervention', 'GET'],
    ['/api/Intervention/Patch/8123', 'assign_technician', 'PATCH'],
]);

it('requires an explicit Global client before creating a demand', function () {
    unset($this->payload['client_address_id']);
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('client_address_id');
    Http::assertNothingSent();
});

it('warns about unconfirmed assignment and retries without creating another demand even after document sync', function ($mismatch) {
    $expected = $this->remote;
    $this->remote[$mismatch] = $mismatch === 'idControleur' ? null : '2026-10-01T14:00:00';
    $service = app(GlobalPlusAppointmentService::class);
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.can_create_global_plus', true)
        ->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    $service->syncDocuments($this->row->refresh());
    expect($this->row->refresh()->global_plus_status)->toBe('appointment_failed');
    $this->remote = $expected;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'created');
    expect(Http::recorded(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://global-plus.test/api/Demande'))->toHaveCount(1);
})->with(['idControleur', 'dateIntervention']);

it('does not assign an ambiguous intervention', function () {
    $this->interventionList = [$this->remote, ['id' => 9999, 'idDemande' => 5637]];
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk();
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
});

it('blocks concurrent creation requests', function () {
    $lock = Cache::lock('global_plus:lot_appointment:'.$this->row->id, 300);
    $lock->get();
    try {
        ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
            ->assertUnprocessable()->assertJsonPath('message', 'Un envoi Global+ est déjà en cours pour ce dossier.');
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

it('retries only assignment without requiring or changing the existing client', function ($routeName) {
    $this->forbiddenPath = '/api/Intervention/Patch/8123';
    ($this->submitAndRun)($routeName, $this->payload)->assertOk();
    $this->forbiddenPath = null;
    ($this->submitAndRun)($routeName, ['controller_id' => 2198])->assertOk();
    expect(data_get($this->row->refresh()->global_plus_payload, 'last_request.client.id'))->toBe(700);
    Http::assertSentCount(11);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Demande')))->toHaveCount(1);
})->with(['manager.lots.appointments.global-plus.store', 'planner.book.lots.appointments.global-plus.store']);

it('queues creation then delayed assignment and rejects duplicate submissions while pending', function () {
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)
        ->assertAccepted()->assertJsonPath('appointment.global_plus_processing', true)
        ->assertJsonPath('appointment.can_create_global_plus', false);
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)->assertAccepted();
    Bus::assertChained([CreateGlobalPlusDemandJob::class, AssignGlobalPlusTechnicianJob::class]);
    Bus::assertDispatchedTimes(CreateGlobalPlusDemandJob::class, 1);
    Http::assertNotSent(fn ($request) => in_array($request->method(), ['PATCH', 'POST']) && ! str_ends_with($request->url(), '/Auth/token'));
    $creation = Bus::dispatched(CreateGlobalPlusDemandJob::class)->first();
    expect($creation->connection)->toBe('database')->and($creation->tries)->toBe(1);
    $assignment = unserialize($creation->chained[0]);
    expect($assignment->delay)->toBe(10)->and($assignment->backoff())->toBe([15, 30, 60, 120]);
    $creation->handle(app(GlobalPlusAppointmentService::class));
    expect($this->row->refresh()->global_plus_demand_id)->toBe('5637')
        ->and($this->row->global_plus_status)->toBe('appointment_pending');
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    $assignment->handle(app(GlobalPlusAppointmentService::class));
    $this->getJson(route('manager.lots.appointments.global-plus.status', $this->row))
        ->assertOk()->assertJsonPath('appointment.global_plus_processing', false)
        ->assertJsonPath('appointment.global_plus_status', 'created');
    $creation->handle(app(GlobalPlusAppointmentService::class));
    $assignment->handle(app(GlobalPlusAppointmentService::class));
    expect(Http::recorded(fn ($request) => $request->url() === 'https://global-plus.test/api/Demande'))->toHaveCount(1);
    expect(Http::recorded(fn ($request) => $request->method() === 'PATCH'))->toHaveCount(1);
});

it('retries assignment when the created intervention is not immediately visible', function () {
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)->assertAccepted();
    $creation = Bus::dispatched(CreateGlobalPlusDemandJob::class)->first();
    $creation->handle(app(GlobalPlusAppointmentService::class));
    $assignment = unserialize($creation->chained[0]);
    $this->interventionList = [];
    expect(fn () => $assignment->handle(app(GlobalPlusAppointmentService::class)))
        ->toThrow(GlobalPlusAssignmentPendingException::class);
    expect($this->row->refresh()->global_plus_status)->toBe('appointment_pending');
    app(GlobalPlusAppointmentService::class)->syncDocuments($this->row);
    expect($this->row->refresh()->global_plus_status)->toBe('appointment_pending');
    $this->interventionList = 'default';
    $assignment->handle(app(GlobalPlusAppointmentService::class));
    expect($this->row->refresh()->global_plus_status)->toBe('created');
    expect(Http::recorded(fn ($request) => $request->url() === 'https://global-plus.test/api/Demande'))->toHaveCount(1);
});

it('preserves the remote demand and reports failure after assignment retries are exhausted', function () {
    $this->interventionList = [];
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_failed')
        ->assertJsonPath('appointment.can_create_global_plus', true);
    expect(Http::recorded(fn ($request) => str_ends_with($request->url(), '/Intervention/ByDemande/5637')))->toHaveCount(5);
    expect($this->row->refresh()->global_plus_demand_id)->toBe('5637');
    expect($this->row->global_plus_error_message)->toContain('Affectation arrêtée après les tentatives automatiques', 'Diagnostic :');
    expect(data_get($this->row->global_plus_payload, 'appointment_assignment.list_interventions.count'))->toBe(0);
    expect($this->globalPlusLogs->hasErrorThatContains('traitement arrêté'))->toBeTrue();
});

it('resolves the intervention directly through ByDemande for supported response shapes', function ($response) {
    $this->interventionList = $response;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_sent');
    expect(data_get($this->row->refresh()->global_plus_payload, 'appointment_assignment.resolution_source'))->toBe('intervention_by_demande');
    $calls = Http::recorded()->map(fn ($call) => $call[0]->method().' '.$call[0]->url())->values()->all();
    expect(array_search('GET https://global-plus.test/api/Intervention/ByDemande/5637', $calls, true))
        ->toBeLessThan(array_search('PATCH https://global-plus.test/api/Intervention/Patch/8123', $calls, true));
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://global-plus.test/api/Intervention/ByDemande/5637');
})->with([
    'list' => [[['id' => 8123, 'idDemande' => 5637]]],
    'object' => [['id' => 8123, 'idDemande' => 5637]],
    'items list' => [['items' => [['id' => 8123, 'idDemande' => 5637]]]],
    'data object' => [['data' => ['id' => 8123, 'idDemande' => 5637]]],
]);

it('uses the scoped route for an ID-only response without claiming confirmed assignment', function ($response) {
    $this->interventionList = $response;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_sent');
    expect(data_get($this->row->refresh()->global_plus_payload, 'appointment_assignment.list_interventions.missing_demand_id'))->toBe(1);
})->with([
    'object' => [['id' => 8123]],
    'items list' => [['items' => [['id' => 8123]]]],
]);

it('never patches an intervention explicitly belonging to another demand including stored IDs', function ($stored) {
    $this->remote['idDemande'] = 9999;
    if ($stored) {
        $this->row->update(['global_plus_demand_id' => '5637', 'global_plus_intervention_id' => '8123', 'global_plus_status' => 'appointment_failed']);
    }
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    expect(data_get($this->row->refresh()->global_plus_payload, 'appointment_assignment.stage'))->toBe('resolve_intervention');
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
})->with([false, true]);

it('rejects malformed mismatched or ambiguous lists rather than guessing an intervention', function ($list) {
    $this->interventionList = $list;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'ByDemande')))->toHaveCount(1);
})->with([
    'unknown envelope' => [['unexpected' => 'value']],
    'invalid id' => [[['id' => '8123-invalid', 'idDemande' => 5637]]],
    'wrong demand' => [[['id' => 8123, 'idDemande' => 9999]]],
    'ambiguous' => [[['id' => 8123, 'idDemande' => 5637], ['id' => 9999, 'idDemande' => 5637]]],
]);

it('records a forbidden scoped list explicitly without retries or another creation', function () {
    $this->interventionList = [];
    $this->interventionListStatus = 403;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    $diagnostic = data_get($this->row->refresh()->global_plus_payload, 'appointment_assignment');
    expect($diagnostic['http_status'])->toBe(403)
        ->and($diagnostic['api_path'])->toBe('/api/Intervention/ByDemande/5637');
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'ByDemande')))->toHaveCount(1);
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
});

it('keeps the dedicated diagnostics free of client fields document content and secrets', function () {
    $this->interventionList = [['id' => 0, 'idDemande' => 5637, 'email' => 'private@example.test', 'token' => 'PRIVATE-TOKEN', 'adresseJson' => 'PERSONAL-ADDRESS', 'fileContent' => 'PRIVATE-DOCUMENT']];
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)->assertOk();
    $logs = json_encode($this->globalPlusLogs->getRecords());
    expect($logs)->toContain('workflow_id', 'attempt', 'invalid', 'http_status', 'ByDemande')
        ->not->toContain('PERSONAL-ADDRESS', 'PRIVATE-DOCUMENT', 'private@example.test', 'PRIVATE-TOKEN', 'fake-token', 'client@example.test', 'Camille');
});

it('retries an empty or not yet available ByDemande response without recreating the demand', function ($response, $status) {
    $this->interventionList = $response;
    $this->interventionListStatus = $status;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'ByDemande')))->toHaveCount(5);
    expect(Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/Demande')))->toHaveCount(1);
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
})->with([
    'null' => [null, 200],
    'not found yet' => [[], 404],
    'no content yet' => [null, 204],
]);

it('distinguishes a verification refusal after an accepted patch', function () {
    $this->verificationForbidden = true;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    $diagnostic = data_get($this->row->refresh()->global_plus_payload, 'appointment_assignment');
    expect($diagnostic['stage'])->toBe('verify_assignment')->and($diagnostic['patch_accepted_at'])->not->toBeNull();
});

it('stores the actual worker attempt with the resolution diagnostics', function () {
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)->assertAccepted();
    $creation = Bus::dispatched(CreateGlobalPlusDemandJob::class)->first();
    $creation->handle(app(GlobalPlusAppointmentService::class));
    $assignment = unserialize($creation->chained[0]);
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(3);
    $assignment->setJob($queueJob);
    $this->interventionList = [];
    expect(fn () => $assignment->handle(app(GlobalPlusAppointmentService::class)))->toThrow(GlobalPlusAssignmentPendingException::class);
    $diagnostic = data_get($this->row->refresh()->global_plus_payload, 'appointment_assignment');
    expect($diagnostic['attempt'])->toBe(3)->and($diagnostic['max_attempts'])->toBe(5)
        ->and($diagnostic['list_interventions']['count'])->toBe(0);
});

it('does not recreate a demand when its POST outcome is uncertain', function () {
    Http::fake(['https://global-plus.test/api/Demande' => Http::failedConnection()]);
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'creation_uncertain')
        ->assertJsonPath('appointment.can_create_global_plus', false);
    Bus::assertDispatchedTimes(CreateGlobalPlusDemandJob::class, 1);
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)->assertUnprocessable();
    Bus::assertDispatchedTimes(CreateGlobalPlusDemandJob::class, 1);
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
});

it('does not let an obsolete assignment job overwrite a newer workflow', function () {
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)->assertAccepted();
    $creation = Bus::dispatched(CreateGlobalPlusDemandJob::class)->first();
    $creation->handle(app(GlobalPlusAppointmentService::class));
    $assignment = unserialize($creation->chained[0]);
    $this->row->refresh()->update(['global_plus_payload' => array_replace_recursive($this->row->global_plus_payload, ['workflow' => ['id' => 'newer-operation']])]);
    $assignment->handle(app(GlobalPlusAppointmentService::class));
    $assignment->failed(new RuntimeException('Old failure'));
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    expect($this->row->refresh()->global_plus_status)->toBe('appointment_pending');
});

it('preserves the exact sent installer even when the directory no longer matches', function ($routeName) {
    $this->forbiddenPath = '/api/Intervention/Patch/8123';
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)->assertOk();
    $sent = data_get($this->row->refresh()->global_plus_payload, 'last_request.entreprise');
    $this->row->update(['installer_name' => 'Different company', 'installer_siren' => '111222333']);
    $this->getJson(route($routeName, $this->row))->assertOk()
        ->assertJsonPath('suggested_installer_address_id', null)
        ->assertJsonPath('existing_installer.address_id', 901)
        ->assertJsonPath('existing_installer.name', $sent['raisonSociale'])
        ->assertJsonPath('existing_installer.siren', $sent['siren']);
    $this->forbiddenPath = null;
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', ['controller_id' => 2198])->assertOk();
    expect(data_get($this->row->refresh()->global_plus_payload, 'last_request.entreprise'))->toBe($sent);
})->with(['manager.lots.appointments.global-plus.references', 'planner.book.lots.appointments.global-plus.references']);

it('restores manual installer details without silently replacing them with a directory match', function () {
    $this->payload = array_replace($this->payload, [
        'installer_address_id' => null, 'installer_name' => 'Manual installer',
        'installer_address' => '12 Rue Test', 'installer_postal_code' => '75002',
        'installer_city' => 'Paris', 'installer_phone' => '0612345678', 'installer_siren' => '348808007',
    ]);
    $this->forbiddenPath = '/api/Intervention/Patch/8123';
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)->assertOk();
    $references = app(GlobalPlusAppointmentService::class)->referenceDataFor($this->row->refresh());
    expect($references['existing_installer'])->toBe([
        'address_id' => 0, 'name' => 'Manual installer', 'siren' => '348808007',
        'address' => '12 Rue Test', 'postal_code' => '75002', 'city' => 'Paris', 'phone' => '0612345678',
    ])->and($references['suggested_installer_address_id'])->toBe(901);
});

it('keeps an ID-only successful PATCH distinct from a verified assignment after document sync', function () {
    $this->interventionList = [['id' => 8123]];
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)->assertOk()
        ->assertJsonPath('appointment.global_plus_status', 'appointment_sent')
        ->assertJsonPath('appointment.global_plus_status_label', 'Affectation envoyée, non vérifiée')
        ->assertJsonPath('appointment.global_plus_error_message', null)
        ->assertJsonPath('appointment.global_plus_processing', false)
        ->assertJsonPath('appointment.can_create_global_plus', false);
    $diagnostic = data_get($this->row->refresh()->global_plus_payload, 'appointment_assignment');
    expect($diagnostic['stage'])->toBe('accepted_unverified')
        ->and($diagnostic['patch_accepted_at'])->not->toBeNull()
        ->and($diagnostic['confirmed_at'])->toBeNull()
        ->and($diagnostic['verification']['controller_matches'])->toBeNull();
    expect(Http::recorded(fn ($request) => $request->method() === 'PATCH'))->toHaveCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request->data() === [
        ['op' => 'replace', 'path' => '/idControleur', 'value' => 2198],
        ['op' => 'replace', 'path' => '/dateIntervention', 'value' => '2026-10-01T10:00:00'],
        ['op' => 'replace', 'path' => '/dateInterventionEnd', 'value' => '2026-10-01T11:30:00'],
    ]);
    app(GlobalPlusAppointmentService::class)->syncDocuments($this->row);
    expect($this->row->refresh()->global_plus_status)->toBe('appointment_sent');
});

it('does not accept partial verification with an explicit controller mismatch', function () {
    $this->interventionList = [['id' => 8123, 'idControleur' => 9999]];
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)->assertOk()
        ->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    expect(data_get($this->row->refresh()->global_plus_payload, 'appointment_assignment.verification.controller_matches'))->toBeFalse();
});

it('rejects an obsolete stored intervention instead of patching it or another intervention', function () {
    $this->row->update(['global_plus_demand_id' => '5637', 'global_plus_intervention_id' => '9999', 'global_plus_status' => 'appointment_failed']);
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', ['controller_id' => 2198])->assertOk()
        ->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
});
