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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
    $this->remoteInterventions = [['id' => 8123, 'idDemande' => 5637]];
    $this->clients = [['clientId' => 1234, 'adresseClient' => ['id' => 700, 'raisonSociale' => 'Delegataire Global', 'adresse' => '1 Rue Delegataire']]];
    $this->forbiddenPath = null;
    Http::fake(fn ($request) => $this->forbiddenPath !== null && str_ends_with($request->url(), $this->forbiddenPath) ? Http::response('', 403) : match ($request->url()) {
        'https://global-plus.test/api/Auth/token' => Http::response(['token' => 'fake-token']),
        'https://global-plus.test/api/Client/Liste' => Http::response($this->clients),
        'https://global-plus.test/api/Entreprise/Liste' => Http::response([['idEntreprise' => 42, 'adresseEntreprise' => ['id' => 901, 'raisonSociale' => 'Ancien nom installateur', 'siren' => '348 808 007']]]),
        'https://global-plus.test/api/Auth/Controllers' => Http::response([['id' => 2198, 'email' => 'tech@example.test', 'etat' => true]]),
        'https://global-plus.test/api/VersionFormulaire/GetVersionFormulaires/true' => Http::response([['versionFormulaireId' => 3310, 'id' => 31, 'libelle' => 'BAR EN 101', 'actif' => true]]),
        'https://global-plus.test/api/Demande' => Http::response('"5637"'),
        'https://global-plus.test/api/Demande/5637' => Http::response(['id' => 5637, 'interventions' => $this->remoteInterventions]),
        'https://global-plus.test/api/Intervention/Patch/8123' => Http::response(null, 204),
        'https://global-plus.test/api/Intervention/8123' => Http::response($this->remote),
        'https://global-plus.test/api/Demande/changeDemandFiles/5637' => Http::response(['result' => true]),
        default => throw new RuntimeException('Unexpected request: '.$request->url()),
    });
    $this->actingAs($actor);
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
        ->and(Http::recorded(fn ($request) => str_ends_with($request->url(), '/Demande/5637')))->toHaveCount($stage === 'resolve_intervention' ? 2 : 1);
})->with([
    ['/api/Demande/5637', 'resolve_intervention', 'GET'],
    ['/api/Intervention/Patch/8123', 'assign_technician', 'PATCH'],
    ['/api/Intervention/8123', 'verify_assignment', 'GET'],
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
    $this->remoteInterventions[] = ['id' => 9999, 'idDemande' => 5637];
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
    Http::assertSentCount(10);
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
    $this->remoteInterventions = [];
    expect(fn () => $assignment->handle(app(GlobalPlusAppointmentService::class)))
        ->toThrow(GlobalPlusAssignmentPendingException::class);
    expect($this->row->refresh()->global_plus_status)->toBe('appointment_pending');
    app(GlobalPlusAppointmentService::class)->syncDocuments($this->row);
    expect($this->row->refresh()->global_plus_status)->toBe('appointment_pending');
    $this->remoteInterventions = [['id' => 8123, 'idDemande' => 5637]];
    $assignment->handle(app(GlobalPlusAppointmentService::class));
    expect($this->row->refresh()->global_plus_status)->toBe('created');
    expect(Http::recorded(fn ($request) => $request->url() === 'https://global-plus.test/api/Demande'))->toHaveCount(1);
});

it('preserves the remote demand and reports failure after assignment retries are exhausted', function () {
    $this->remoteInterventions = [];
    ($this->submitAndRun)('manager.lots.appointments.global-plus.store', $this->payload)
        ->assertOk()->assertJsonPath('appointment.global_plus_status', 'appointment_failed')
        ->assertJsonPath('appointment.can_create_global_plus', true);
    expect(Http::recorded(fn ($request) => str_ends_with($request->url(), '/Demande/5637')))->toHaveCount(5);
    expect($this->row->refresh()->global_plus_demand_id)->toBe('5637');
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
