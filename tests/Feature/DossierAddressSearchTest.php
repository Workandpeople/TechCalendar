<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->dossier = Dossier::create(['reference' => 'ADDRESS-SEARCH', 'company_name' => 'Bénéficiaire recherché',
        'address' => '255 Chemin des Eyrauds', 'postal_code' => '04130', 'city' => 'Volx']);
    $this->dossier->operations()->create()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    foreach ([['255 Chemin des Eyrauds', '04130', 'Manosque'], ['255 Chemin des Eyrauds', '04100', 'Volx'],
        ['1 rue différente', '04130', 'Volx']] as $index => [$street, $postcode, $city]) {
        $other = Dossier::create(['reference' => 'DECOY-'.$index, 'company_name' => 'Autre bénéficiaire '.$index,
            'address' => $street, 'postal_code' => $postcode, 'city' => $city]);
        $other->operations()->create()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    }
    $this->actingAs($this->actor);
});

it('finds the complete intervention address across separate fields with common separators', function (string $search) {
    $this->get(route('v2.dossiers.index', ['q' => $search]))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->first()->is($this->dossier))
        ->assertViewHas('categories', fn ($categories) => $categories['all']['count'] === 1 && $categories['report']['count'] === 1);
})->with([
    '255 Chemin des Eyrauds 04130 Volx', '255 Chemin des Eyrauds, 04130 Volx',
    '255 Chemin des Eyrauds, 04130, Volx', " 255  Chemin\tdes Eyrauds\n04130   Volx ",
    "255\u{00a0}Chemin des Eyrauds\u{202f}04130 Volx", '255 Chemin des Eyrauds; 04130 Volx',
]);

it('preserves partial address postcode city and literal reference searches', function (string $search, int $expected) {
    expect(app(DossierRegistry::class)->matchingQuery($this->actor, ['q' => $search])->count())->toBe($expected);
})->with([['Chemin des Eyrauds', 3], ['04130 Volx', 2], ['04130', 3], ['Volx', 3], ['ADDRESS-SEARCH', 1],
    ['255 Chemin des Eyrauds 04130 Lyon', 0], ['255 Chemin des Eyrauds 75001 Volx', 0], ["Volx%' OR 1=1 --", 0], [', ;', 0]]);

it('treats SQL wildcard and escape characters literally in complete addresses', function () {
    $this->dossier->update(['address' => '10 voie 100%_!', 'postal_code' => '75001', 'city' => 'Paris']);
    Dossier::where('reference', 'DECOY-0')->update(['address' => '10 voie 100ABC!', 'postal_code' => '75001', 'city' => 'Paris']);
    expect(app(DossierRegistry::class)->matchingQuery($this->actor, ['q' => '10 voie 100%_!, 75001 Paris'])->pluck('id')->all())
        ->toBe([$this->dossier->id]);
});

it('supports absent postal codes and addresses whose locality is already included', function () {
    $this->dossier->update(['postal_code' => null]);
    expect(app(DossierRegistry::class)->matchingQuery($this->actor, ['q' => '255 Chemin des Eyrauds, Volx'])->count())->toBe(2);
    $this->dossier->update(['address' => '255 Chemin des Eyrauds, 04130 Volx', 'city' => '']);
    expect(app(DossierRegistry::class)->matchingQuery($this->actor, ['q' => '255 Chemin des Eyrauds 04130 Volx'])->pluck('id')->all())
        ->toBe([$this->dossier->id]);
});

it('searches long complete addresses rather than rejecting them at 120 characters', function () {
    $street = '255 Chemin des Eyrauds '.str_repeat('Résidence des Jardins ', 5);
    $this->dossier->update(['address' => trim($street)]);
    $this->get(route('v2.dossiers.index', ['q' => trim($street).', 04130 Volx']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->first()->is($this->dossier));
});

it('uses the complete address for exports without ignoring the other filters', function () {
    $query = ['q' => '255 Chemin des Eyrauds, 04130 Volx', 'status' => 'report'];
    $response = $this->get(route('v2.dossiers.export', $query))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'address-export-');
    try {
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        expect($sheet)->toContain('Bénéficiaire recherché')->not->toContain('Autre bénéficiaire');
        $zip->close();
    } finally {
        unlink($path);
    }
    expect(app(DossierRegistry::class)->matchingQuery($this->actor, [...$query, 'status' => 'pending'])->count())->toBe(0);
});

it('applies address search to external portals without exposing other assignments or deleted dossiers', function (string $kind) {
    $organisation = ExternalDelegataire::create(['source' => 'coffrac', 'external_id' => 'address-search-test', 'name' => 'Organisation de test', 'kind' => $kind, 'is_active' => true]);
    $external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $organisation->id,
        'portal_enabled' => true, 'must_change_password' => false, 'portal_permissions' => ClientAccess::defaults($kind)]);
    $this->dossier->update([$kind === 'client' ? 'client_id' : 'delegataire_id' => $organisation->id]);
    foreach ([false, true] as $deleted) {
        $other = Dossier::create(['reference' => 'PRIVATE-'.$deleted, 'company_name' => 'Dossier non accessible',
            'address' => $this->dossier->address, 'postal_code' => '04130', 'city' => 'Volx',
            'client_id' => $deleted ? $organisation->id : null, 'delegataire_id' => $deleted ? $organisation->id : null]);
        $other->operations()->create()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
        if ($deleted) {
            $other->delete();
        }
    }
    $this->actingAs($external)->get(route('client.dossiers', ['q' => '255 Chemin des Eyrauds, 04130 Volx']))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->first()->is($this->dossier))
        ->assertViewHas('categories', fn ($categories) => $categories['all']['count'] === 1 && $categories['report']['count'] === 1)
        ->assertDontSee('Dossier non accessible');
})->with(['client', 'delegataire']);
