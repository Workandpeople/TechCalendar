<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointment;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierLotReference;
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
    $this->manager = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->technician = User::factory()->create(['role' => 2, 'admin' => false]);
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'lot-reference-test',
        'kind' => 'client', 'name' => 'Organisation de test', 'is_active' => true, 'portal_categories' => ['awaiting_visit']]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'portal_enabled' => true, 'external_client_id' => $this->organisation->id, 'portal_permissions' => ClientAccess::defaults('client')]);
    $this->service = Service::create(['name' => 'Inspection', 'type' => 'COFRAC', 'average_duration_minutes' => 60]);
    $this->makeDossier = function (string $label, ?string $reference = null, ?string $lotName = null): Dossier {
        $dossier = Dossier::create(['reference' => 'DOS-'.bin2hex(random_bytes(5)), 'company_name' => $label,
            'legacy_lot_name' => $reference, 'client_id' => $this->organisation->id, 'address' => '1 rue Test', 'city' => 'Lyon']);
        $operation = $dossier->operations()->create(['service_id' => $this->service->id]);
        $operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
        if ($lotName !== null) {
            $appointment = Appointment::forceCreate(['dossier_operation_id' => $operation->id, 'service_id' => $this->service->id,
                'technician_id' => $this->technician->id, 'created_by' => $this->manager->id, 'customer_first_name' => 'Test',
                'customer_last_name' => 'Client', 'customer_phone' => '0600000000', 'address' => '1 rue Test',
                'starts_at' => '2026-11-03 10:00:00', 'ends_at' => '2026-11-03 11:00:00', 'duration_minutes' => 60,
                'status' => Appointment::STATUS_SCHEDULED]);
            $lot = Lot::create(['name' => $lotName, 'type' => Lot::TYPE_FULL_CONTROL]);
            LotAppointment::create(['lot_id' => $lot->id, 'appointment_id' => $appointment->id, 'customer_name' => $label]);
        }

        return $dossier->refresh();
    };
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('uses the same imported or linked lot reference internally and for both external types', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $imported = ($this->makeDossier)('Dossier import', 'LOT IMPORT 0317');
    $linked = ($this->makeDossier)('Dossier lie', null, 'LOT NATIF 0420');
    if ($kind === 'delegataire') {
        Dossier::query()->update(['delegataire_id' => $this->organisation->id, 'client_id' => null]);
    }
    $this->actingAs($this->manager)->get(route('v2.dossiers.index'))->assertOk()->assertSee('LOT IMPORT 0317')->assertSee('LOT NATIF 0420');
    $this->get(route('v2.dossiers.show', $linked))->assertOk()->assertSee('LOT NATIF 0420');
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()->assertSee('LOT IMPORT 0317')->assertSee('LOT NATIF 0420');
    $this->get(route('client.dossiers.show', $linked))->assertOk()->assertSee('LOT NATIF 0420');
    expect(DossierLotReference::value($imported))->toBe('LOT IMPORT 0317');
})->with(['client', 'delegataire']);

it('filters imported and native lot names literally and keeps the organisation scope', function () {
    ($this->makeDossier)('Dossier cible import', 'LOT %_ SPECIAL');
    ($this->makeDossier)('Dossier cible natif', null, 'LOT %_ SPECIAL');
    ($this->makeDossier)('Dossier autre', 'LOT XY SPECIAL');
    $private = ($this->makeDossier)('Dossier prive', 'LOT %_ SPECIAL');
    $private->update(['client_id' => null]);
    $this->actingAs($this->external)->get(route('client.dossiers', ['lot' => '%_']))->assertOk()
        ->assertSee('Dossier cible import')->assertSee('Dossier cible natif')->assertDontSee('Dossier autre')->assertDontSee('Dossier prive')
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 2);
    $this->actingAs($this->manager)->get(route('v2.dossiers.index', ['lot' => '%_']))->assertOk()
        ->assertSee('Dossier cible import')->assertSee('Dossier cible natif')->assertDontSee('Dossier autre')
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 3);
});

it('does not grant access to a forbidden category through a matching lot', function () {
    $private = ($this->makeDossier)('Categorie interdite', null, 'LOT SECRET');
    $private->operations()->first()->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    $this->actingAs($this->external)->get(route('client.dossiers', ['lot' => 'SECRET']))->assertOk()->assertDontSee('LOT SECRET')
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 0);
});

it('saves an editable lot reference with version checking but never creates or reassigns a lot', function () {
    $dossier = ($this->makeDossier)('Reference modifiable', null, 'LOT EXISTANT');
    $row = LotAppointment::sole();
    $this->actingAs($this->manager)->get(route('v2.dossiers.edit', $dossier))->assertOk()->assertSee('name="legacy_lot_name"', false);
    $data = ['version' => $dossier->version, 'company_name' => 'Reference modifiable', 'address' => '1 rue Test', 'city' => 'Lyon', 'legacy_lot_name' => 'LOT SAISI 009'];
    $this->put(route('v2.dossiers.update', $dossier), $data)->assertSessionHasNoErrors();
    expect($dossier->fresh()->legacy_lot_name)->toBe('LOT SAISI 009')->and(Lot::count())->toBe(1)->and($row->fresh()->lot_id)->toBe($row->lot_id);
    $this->put(route('v2.dossiers.update', $dossier), [...$data, 'legacy_lot_name' => 'OBSOLETE'])->assertSessionHasErrors('version');
    expect($dossier->fresh()->legacy_lot_name)->toBe('LOT SAISI 009');
    $this->put(route('v2.dossiers.update', $dossier), [...$data, 'version' => $dossier->fresh()->version, 'legacy_lot_name' => null])->assertSessionHasNoErrors();
    expect(DossierLotReference::value($dossier->fresh()))->toBe('LOT EXISTANT');
});

it('rejects malformed filters and overlong lot references without SQL mutations', function () {
    $this->actingAs($this->manager)->getJson(route('v2.dossiers.index', ['lot' => ['invalid']]))->assertUnprocessable();
    $this->actingAs($this->external)->getJson(route('client.dossiers', ['lot' => str_repeat('x', 257)]))->assertUnprocessable();
    expect(Dossier::count())->toBe(0);
});
