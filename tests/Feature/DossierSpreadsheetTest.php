<?php

use App\Models\Dossier;
use App\Models\DossierBilling;
use App\Models\User;
use App\Services\Crm\DossierBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exports all filtered rows across pages in the selected order as safe Excel strings', function () {
    config(['crm.v2_enabled' => true]);
    $actor = User::factory()->create(['role' => 0]);
    for ($i = 0; $i < 27; $i++) {
        $dossier = Dossier::create(['reference' => 'EXPORT-'.$i, 'company_name' => sprintf('Client %02d', $i), 'address' => '=HYPERLINK("https://bad.test")', 'city' => 'Ville <test>', 'postal_code' => '01234', 'phone' => '0600000000']);
        $dossier->operations()->create()->forceFill(['legacy_status' => $i === 26 ? 'RDV VISITE' : 'PRISE DE RDV'])->save();
        $dossier->activities()->create(['kind' => 'status_changed', 'body' => 'Changement métier',
            'changed_fields' => ['status' => 'PRISE DE RDV']])->forceFill(['created_at' => '2026-10-01 10:30:00'])->save();
        $dossier->activities()->create(['kind' => 'comment', 'body' => 'Commentaire plus récent']);
    }
    $response = $this->actingAs($actor)->get(route('v2.dossiers.export', [
        'q' => 'EXPORT-', 'status' => 'pending', 'sort' => 'client', 'direction' => 'desc', 'page' => 2, 'per_page' => 25,
    ]))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $file = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    try {
        file_put_contents($file, $response->streamedContent());
        $zip = new ZipArchive;
        expect($zip->open($file))->toBeTrue();
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $part) {
            expect(simplexml_load_string($zip->getFromName($part)))->not->toBeFalse();
        }
        $dom = new DOMDocument;
        expect($dom->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET))->toBeTrue();
        $xml = new DOMXPath($dom);
        $xml->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        expect($xml->query('//s:row')->length)->toBe(27);
        expect($xml->query('//s:row[2]/s:c[6]')->item(0)->textContent)->toBe('Client 25');
        expect($xml->query('//s:row[27]/s:c[6]')->item(0)->textContent)->toBe('Client 00');
        expect($xml->query('//s:row[2]/s:c[8]')->item(0)->textContent)->toBe('01234');
        expect($xml->query('//s:row[2]/s:c[3]')->item(0)->textContent)->toBe('01/10/2026 10:30');
        expect($xml->query('//s:row[2]/s:c[4]')->item(0)->textContent)->toBe('Prise de RDV');
        expect($xml->query('//s:row[2]/s:c[7]')->item(0)->textContent)->toBe('=HYPERLINK("https://bad.test")');
        expect($xml->query('//s:f')->length)->toBe(0);
        expect($dom->textContent)->not->toContain('Client 26', 'EXPORT-');
        $zip->close();
    } finally {
        unlink($file);
    }
});

it('applies the same filter validation and internal permissions to exports', function () {
    config(['crm.v2_enabled' => true]);
    $url = route('v2.dossiers.export');
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['role' => 3, 'admin' => false]))->getJson($url)->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 0]))->getJson($url.'?sort=sql-injection')->assertUnprocessable();
    config(['crm.v2_enabled' => false]);
    $this->getJson($url)->assertNotFound();
});

it('exports the original marking date independently of the current status and leaves unknown dates empty', function () {
    config(['crm.v2_enabled' => true]);
    $actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $dossiers = [];
    foreach (['A-NATIVE', 'B-UNDATED', 'C-UNBILLED', 'D-MULTIPLE'] as $name) {
        $dossier = Dossier::create(['reference' => $name, 'company_name' => $name, 'address' => 'Rue test', 'city' => 'Paris']);
        $dossier->operations()->create()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
        $dossiers[$name] = $dossier;
    }
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(9, 17, 42));
    app(DossierBillingService::class)->mark($actor, $dossiers['A-NATIVE'], null, ['version' => 1]);
    $this->travel(4)->days();
    $dossiers['A-NATIVE']->operations()->update(['legacy_status' => 'NON CONFORMITES']);
    DossierBilling::create(['dossier_id' => $dossiers['B-UNDATED']->id,
        'dossier_operation_id' => $dossiers['B-UNDATED']->operations()->sole()->id, 'source' => 'coffrac', 'marked_at' => null]);
    $multiple = $dossiers['D-MULTIPLE'];
    foreach (['2024-07-01 10:15:00', '2023-02-03 14:20:00'] as $index => $date) {
        $operation = $index === 0 ? $multiple->operations()->sole() : $multiple->operations()->create();
        $operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
        DossierBilling::create(['dossier_id' => $multiple->id, 'dossier_operation_id' => $operation->id,
            'source' => 'coffrac', 'marked_at' => $date]);
    }
    $response = $this->actingAs($actor)->get(route('v2.dossiers.export', ['sort' => 'client', 'direction' => 'asc']))->assertOk();
    $file = tempnam(sys_get_temp_dir(), 'xlsx-billing-test-');
    try {
        file_put_contents($file, $response->streamedContent());
        $zip = new ZipArchive;
        expect($zip->open($file))->toBeTrue();
        $dom = new DOMDocument;
        expect($dom->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'), LIBXML_NONET))->toBeTrue();
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        expect($xpath->query('//s:row[1]/s:c[17]')->item(0)->textContent)->toBe('Date du passage en facturé')
            ->and($xpath->query('//s:autoFilter')->item(0)->getAttribute('ref'))->toBe('A1:Q5');
        foreach (['A-NATIVE' => ['Facturé', '20/10/2026 09:17'], 'B-UNDATED' => ['Facturé', ''],
            'C-UNBILLED' => ['Non facturé', ''], 'D-MULTIPLE' => ['Facturé', '03/02/2023 14:20']] as $name => [$label, $date]) {
            $row = $xpath->query('//s:row[s:c[6]="'.$name.'"]')->item(0);
            expect($row)->not->toBeNull()
                ->and($xpath->query('s:c[16]', $row)->item(0)->textContent)->toBe($label)
                ->and($xpath->query('s:c[17]', $row)->item(0)->textContent)->toBe($date);
        }
        $zip->close();
    } finally {
        unlink($file);
    }
});
