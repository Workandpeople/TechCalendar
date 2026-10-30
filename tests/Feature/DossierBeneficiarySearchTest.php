<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\User;
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
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'BENEFICIARY-SEARCH',
        'kind' => 'client', 'name' => 'Organisation test', 'is_active' => true, 'portal_categories' => ['report']]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'portal_enabled' => true,
        'external_client_id' => $this->organisation->id, 'portal_permissions' => ClientAccess::defaults('client')]);
    $this->makeDossier = function (array $attributes = [], string $status = 'RAPPORT GENIUS'): Dossier {
        $dossier = Dossier::forceCreate(['reference' => 'TEST-'.Dossier::withTrashed()->count(),
            'client_id' => $this->organisation->id, 'delegataire_id' => $this->organisation->id,
            'address' => 'Rue Test', 'city' => 'Paris', ...$attributes]);
        $dossier->operations()->create()->forceFill(['legacy_status' => $status])->save();

        return $dossier;
    };
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('finds exactly the displayed imported beneficiary in client and delegataire portals', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $dossier = ($this->makeDossier)(['legacy_label' => 'MARTIN Jean Paul', 'company_name' => null, 'first_name' => null, 'last_name' => null]);
    ($this->makeDossier)(['legacy_label' => 'DUPONT Marie']);
    $this->actingAs($this->external)->get(route('client.dossiers', ['q' => $dossier->beneficiaryLabel()]))->assertOk()
        ->assertSee('MARTIN Jean Paul')->assertDontSee('DUPONT Marie')
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->sole()->is($dossier))
        ->assertViewHas('categories', fn ($categories) => $categories['all']['count'] === 1 && $categories['report']['count'] === 1);
})->with(['client', 'delegataire']);

it('finds complete beneficiary names stored in separate columns in either name order', function (string $search, bool $internal) {
    $dossier = ($this->makeDossier)(['first_name' => 'Jean Paul', 'last_name' => 'Martin']);
    ($this->makeDossier)(['first_name' => 'Jean Paul', 'last_name' => 'Dupont']);
    ($this->makeDossier)(['first_name' => 'Marie', 'last_name' => 'Martin']);
    $this->actingAs($internal ? $this->staff : $this->external)->get(route($internal ? 'v2.dossiers.index' : 'client.dossiers', ['q' => $search]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->sole()->is($dossier));
})->with(['Jean Paul Martin', 'Martin Jean Paul', 'paul martin', 'Martin Jean', "  Jean\t Paul\n Martin  ", "Jean\u{00a0}Paul\u{202f}Martin"])->with([false, true]);

it('normalizes pasted beneficiary spacing without interpreting SQL wildcard characters', function () {
    $dossier = ($this->makeDossier)(['legacy_label' => 'Entreprise 100%_! Martin']);
    ($this->makeDossier)(['legacy_label' => 'Entreprise 100ABC! Martin']);
    $this->actingAs($this->external)->get(route('client.dossiers', ['q' => "Entreprise\u{00a0}100%_!  Martin"]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->sole()->is($dossier));
    $this->get(route('client.dossiers', ['q' => "Martin%' OR 1=1 --"]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 0);
});

it('searches every permitted dossier rather than only the displayed pagination page', function () {
    $match = ($this->makeDossier)(['legacy_label' => 'Beneficiaire ancien']);
    foreach (range(1, 27) as $index) {
        ($this->makeDossier)(['company_name' => 'Autre beneficiaire '.$index]);
    }
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 28 && ! $rows->contains('id', $match->id));
    $this->get(route('client.dossiers', ['q' => 'Beneficiaire ancien']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->sole()->is($match));
});

it('preserves organisation category and deletion restrictions for all beneficiary matches', function (string $kind) {
    $this->organisation->update(['kind' => $kind]);
    $match = ($this->makeDossier)(['first_name' => 'Jean', 'last_name' => 'Martin']);
    ($this->makeDossier)(['first_name' => 'Jean', 'last_name' => 'Martin', 'client_id' => null, 'delegataire_id' => null]);
    ($this->makeDossier)(['first_name' => 'Jean', 'last_name' => 'Martin'], 'PRISE DE RDV');
    ($this->makeDossier)(['first_name' => 'Jean', 'last_name' => 'Martin'])->delete();
    $this->actingAs($this->external)->get(route('client.dossiers', ['q' => 'Jean Martin', 'status' => 'report']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->sole()->is($match))
        ->assertViewHas('categories', fn ($categories) => $categories['all']['count'] === 1 && $categories['report']['count'] === 1);
    $this->get(route('client.dossiers', ['q' => 'Jean Martin', 'status' => 'pending']))->assertForbidden();
})->with(['client', 'delegataire']);
