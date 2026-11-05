<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'ALVEA',
        'name' => 'ALVEA', 'kind' => 'client', 'is_active' => true,
        'report_download_naming' => ExternalDelegataire::REPORT_DOWNLOAD_OUTCOME_PREFIX]);
    $this->delegataire = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'OTHER',
        'name' => 'Autre délégataire', 'kind' => 'delegataire', 'is_active' => true]);
    $this->dossier = Dossier::create(['reference' => 'NAMING', 'company_name' => 'BATIMENT CLIBERT DENIS',
        'address' => 'Rue Test', 'city' => 'Paris', 'client_id' => $this->organisation->id, 'delegataire_id' => $this->delegataire->id]);
    $this->operation = $this->dossier->operations()->create();
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS', 'control_outcome' => 'unverifiable'])->save();
    $this->accounts = ['staff' => $this->staff];
    foreach (['client' => $this->organisation, 'delegataire' => $this->delegataire] as $kind => $organisation) {
        $this->accounts[$kind] = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
            'external_client_id' => $organisation->id, 'portal_enabled' => true, 'must_change_password' => false,
            'portal_permissions' => ClientAccess::defaults($kind)]);
    }
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

function opinionDownloadFixture($test, string $source, ?string $outcome, bool $report = true): array
{
    $title = 'Rapport de contrôle - ALVEA-ACT-1575343_OP-2195345';
    $content = file_get_contents(__DIR__.'/../Fixtures/documents/preview.pdf');
    if ($source === 'native') {
        $path = 'crm/dossiers/'.$test->dossier->id.'/report.pdf';
        Storage::disk('local')->put($path, $content);
        $document = $test->dossier->documents()->create(['name' => $title, 'path' => $path, 'size' => strlen($content),
            'mime_type' => 'application/pdf', 'document_type' => $report ? 'report' : 'free',
            'dossier_operation_id' => $test->operation->id, 'control_outcome' => $outcome,
            'control_reference' => $report ? 'ALVEA-ACT-1575343/OP-2195345' : null,
            'client_visible' => true, 'delegataire_visible' => true]);
        $urls = ['staff' => route('v2.documents.download', $document),
            'client' => route('client.documents.download', $document), 'delegataire' => route('client.documents.download', $document)];
    } else {
        $path = 'collected/report.pdf';
        Storage::disk('local')->put($path, $content);
        Storage::disk('local')->put('manifest.list0', "report.pdf\0");
        config(['crm.collected_documents_root' => Storage::disk('local')->path('collected'),
            'crm.collected_documents_manifest' => Storage::disk('local')->path('manifest.list0')]);
        DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 456,
            'dossier_id' => $test->dossier->id, 'client_visible_override' => true, 'delegataire_visible_override' => true,
            'payload' => json_encode(['name' => $title, 'path' => 'report.pdf', 'status' => $outcome,
                'reference_controle' => $report ? 'ALVEA-ACT-1575343/OP-2195345' : null])]);
        $urls = ['staff' => route('v2.dossiers.collected-documents.download', [$test->dossier, 456]),
            'client' => route('client.dossiers.archive', [$test->dossier, 456]),
            'delegataire' => route('client.dossiers.archive', [$test->dossier, 456])];
    }

    return [$urls, $path, $title];
}

it('names current and archived reports by their own opinion for every authorized downloader', function (string $source, string $outcome, string $prefix, string $actor) {
    [$urls, $path, $title] = opinionDownloadFixture($this, $source, $outcome);
    $expected = $prefix.' - ALVEA-ACT-1575343_OP-2195345 - BATIMENT CLIBERT DENIS.pdf';
    $this->actingAs($this->accounts[$actor]);
    foreach (['GET', 'HEAD'] as $method) {
        $this->call($method, $urls[$actor])->assertOk()->assertDownload($expected);
        $response = $this->call($method, $urls[$actor].'?preview=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')->toContain($prefix.' - ALVEA-ACT-1575343_OP-2195345');
    }
    expect(Storage::disk('local')->exists($path))->toBeTrue();
    if ($source === 'native') {
        expect($this->dossier->documents()->sole()->name)->toBe($title);
    } else {
        expect(json_decode(DB::table('legacy_records')->where('source_id', 456)->value('payload'), true)['name'])->toBe($title);
    }
})->with(['native', 'archive'])->with([
    ['satisfactory', 'S'], ['unsatisfactory', 'NS'], ['unverifiable', 'NV'],
    ['Satisfaisant', 'S'], ['Non satisfaisant', 'NS'], ['Non vérifiable', 'NV'],
])->with(['staff', 'client', 'delegataire']);

it('does not rename ordinary documents or reports without an explicit supported opinion', function (string $source, ?string $outcome, bool $report) {
    [$urls, , $title] = opinionDownloadFixture($this, $source, $outcome, $report);
    $response = $this->actingAs($this->staff)->get($urls['staff'])->assertOk()->assertDownload(Str::ascii($title).' - BATIMENT CLIBERT DENIS.pdf');
    expect($response->headers->get('Content-Disposition'))->toContain(rawurlencode($title));
})->with(['native', 'archive'])->with([[null, true], ['', true], ['inconnu', true], ['satisfactory', false]]);

it('uses stable organisation settings on either assignment without changing other clients naming', function (string $relation) {
    [$urls, , $title] = opinionDownloadFixture($this, 'native', 'unsatisfactory');
    $this->organisation->update(['report_download_naming' => 'standard']);
    $this->actingAs($this->staff)->get($urls['staff'])->assertDownload(Str::ascii($title).' - BATIMENT CLIBERT DENIS.pdf');
    $organisation = $relation === 'client' ? $this->organisation : $this->delegataire;
    $organisation->update(['name' => 'Organisation renommée', 'report_download_naming' => 'outcome_prefix']);
    $this->get($urls['staff'])->assertDownload('NS - ALVEA-ACT-1575343_OP-2195345 - BATIMENT CLIBERT DENIS.pdf');
    $organisation->update(['report_download_naming' => 'standard']);
    $this->get($urls['staff'])->assertDownload(Str::ascii($title).' - BATIMENT CLIBERT DENIS.pdf');
})->with(['client', 'delegataire']);

it('keeps organisation naming configurable by managers but not external users', function () {
    $data = ['name' => 'ALVEA', 'kind' => 'client', 'is_active' => true];
    $this->actingAs($this->staff)->get(route('manager.clients.edit', $this->organisation))->assertOk()
        ->assertSee('Nommage des rapports téléchargés')->assertSee('name="report_download_naming"', false);
    $this->put(route('manager.clients.update', $this->organisation), [...$data, 'report_download_naming' => 'standard'])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($this->organisation->fresh()->report_download_naming)->toBe('standard');
    $this->put(route('manager.clients.update', $this->organisation), $data)->assertSessionHasNoErrors();
    expect($this->organisation->fresh()->report_download_naming)->toBe('standard');
    $this->putJson(route('manager.clients.update', $this->organisation), [...$data, 'report_download_naming' => 'arbitrary'])
        ->assertUnprocessable()->assertJsonValidationErrors('report_download_naming');
    $this->actingAs($this->accounts['client'])->putJson(route('manager.clients.update', $this->organisation),
        [...$data, 'report_download_naming' => 'outcome_prefix'])->assertForbidden();
    expect($this->organisation->fresh()->report_download_naming)->toBe('standard');
});

it('activates the requested policy for exact ALVEA organisation labels only during migration', function () {
    $this->organisation->update(['report_download_naming' => 'standard']);
    $rows = [];
    foreach ([' alvea ', 'ALVEA CEE', 'ALVEA Délégataire', 'ALVEA SERVICES', 'Autre client', 'Client ALVEA'] as $name) {
        $rows[$name] = ExternalDelegataire::create(['name' => $name, 'source' => 'native', 'external_id' => $name, 'kind' => 'client']);
    }
    $byCompany = ExternalDelegataire::create(['name' => 'Contact ALVEA', 'company_name' => 'ALVEA', 'source' => 'native', 'external_id' => 'COMPANY', 'kind' => 'delegataire']);
    (require database_path('migrations/2026_11_05_130000_add_organisation_report_download_naming.php'))->up();
    expect($this->organisation->fresh()->report_download_naming)->toBe('outcome_prefix')
        ->and($byCompany->fresh()->report_download_naming)->toBe('outcome_prefix');
    foreach ($rows as $name => $organisation) {
        expect($organisation->fresh()->report_download_naming)->toBe(in_array($name, [' alvea ', 'ALVEA CEE', 'ALVEA Délégataire'], true) ? 'outcome_prefix' : 'standard');
    }
    expect($this->delegataire->fresh()->report_download_naming)->toBe('standard');
});
