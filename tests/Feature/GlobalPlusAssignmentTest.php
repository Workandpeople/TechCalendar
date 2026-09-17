<?php

use App\Models\Appointment;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use App\Services\GlobalPlus\GlobalPlusAppointmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
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
    $this->payload = ['client_address_id' => 700, 'installer_address_id' => 901, 'controller_id' => 2198, 'version_formulaire_id' => 3310, 'send_documents' => false];
    $this->remote = ['id' => 8123, 'idDemande' => 5637, 'idControleur' => 2198, 'dateIntervention' => '2026-10-01T10:00:00', 'dateInterventionEnd' => '2026-10-01T11:30:00'];
    $this->remoteInterventions = [['id' => 8123, 'idDemande' => 5637]];
    Http::fake(fn ($request) => match ($request->url()) {
        'https://global-plus.test/api/Auth/token' => Http::response(['token' => 'fake-token']),
        'https://global-plus.test/api/Client/Liste' => Http::response([['adresseClient' => ['id' => 700, 'raisonSociale' => 'Delegataire Global', 'adresse' => '1 Rue Delegataire']]]),
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
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)
        ->assertCreated()->assertJsonPath('warning', false)->assertJsonPath('appointment.global_plus_status', 'created');
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
        && collect(['client', 'lieuInspection', 'beneficiaire', 'entreprise'])->every(fn ($field) => $request[$field]['civilite'] === 'M')
    );
    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request->url() === 'https://global-plus.test/api/Intervention/Patch/8123'
        && $request->data()[0] === ['op' => 'replace', 'path' => '/idControleur', 'value' => 2198]);
    expect($this->row->refresh()->global_plus_intervention_id)->toBe('8123');
});

it('requires an explicit Global client before creating a demand', function () {
    unset($this->payload['client_address_id']);
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('client_address_id');
    Http::assertNothingSent();
});

it('warns about unconfirmed assignment and retries without creating another demand even after document sync', function ($mismatch) {
    $expected = $this->remote;
    $this->remote[$mismatch] = $mismatch === 'idControleur' ? null : '2026-10-01T14:00:00';
    $service = app(GlobalPlusAppointmentService::class);
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)
        ->assertCreated()->assertJsonPath('warning', true)->assertJsonPath('appointment.can_create_global_plus', true)
        ->assertJsonPath('appointment.global_plus_status', 'appointment_failed');
    $service->syncDocuments($this->row->refresh());
    expect($this->row->refresh()->global_plus_status)->toBe('appointment_failed');
    $this->remote = $expected;
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)
        ->assertCreated()->assertJsonPath('warning', false)->assertJsonPath('appointment.global_plus_status', 'created');
    expect(Http::recorded(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://global-plus.test/api/Demande'))->toHaveCount(1);
})->with(['idControleur', 'dateIntervention']);

it('does not assign an ambiguous intervention', function () {
    $this->remoteInterventions[] = ['id' => 9999, 'idDemande' => 5637];
    $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)
        ->assertCreated()->assertJsonPath('warning', true);
    Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
});

it('blocks concurrent creation requests', function () {
    $lock = Cache::lock('global_plus:lot_appointment:'.$this->row->id, 300);
    $lock->get();
    try {
        $this->postJson(route('manager.lots.appointments.global-plus.store', $this->row), $this->payload)
            ->assertUnprocessable()->assertJsonPath('message', 'Un envoi Global+ est déjà en cours pour ce dossier.');
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});
