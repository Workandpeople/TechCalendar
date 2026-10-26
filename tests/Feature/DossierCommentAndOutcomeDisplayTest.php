<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\PostVisitWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'crm.collected_documents_root' => '', 'crm.collected_documents_manifest' => '']);
    Http::preventStrayRequests();
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'OUTCOME',
        'name' => 'Client test', 'kind' => 'client', 'is_active' => true]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->client->id, 'portal_enabled' => true, 'must_change_password' => false,
        'portal_permissions' => ClientAccess::defaults('client')]);
    $this->dossier = Dossier::create(['reference' => 'TWO-LINES', 'company_name' => 'Test',
        'address' => 'Rue Test', 'city' => 'Paris', 'client_id' => $this->client->id]);
    $this->dossier->operations()->create()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    $this->xpath = function (string $html): DOMXPath {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);

        return new DOMXPath($dom);
    };
});

it('renders each comment once in a two line preview with an accessible optional toggle', function (bool $external) {
    $this->dossier->activities()->create(['kind' => 'comment', 'body' => "Premiere ligne\nDeuxieme ligne\nTroisieme ligne",
        'client_visible' => true]);
    $this->dossier->activities()->create(['kind' => 'comment', 'body' => '<script>PRIVATE-SCRIPT</script>', 'client_visible' => false]);
    $response = $this->actingAs($external ? $this->external : $this->staff)
        ->get(route($external ? 'client.dossiers.show' : 'v2.dossiers.show', $this->dossier))->assertOk();
    $xpath = ($this->xpath)($response->getContent());
    $selector = '//*[@id="'.($external ? 'client-comments' : 'commentaires').'"]';
    expect($xpath->query($selector.'//*[@data-comment-excerpt]')->length)->toBe($external ? 1 : 2)
        ->and($xpath->query($selector.'//details[contains(@class,"gc-content-excerpt")]')->length)->toBe(0);
    foreach ($xpath->query($selector.'//*[@data-comment-excerpt]') as $excerpt) {
        $body = $xpath->query('.//*[@data-excerpt-body]', $excerpt);
        $button = $xpath->query('.//button[@data-excerpt-toggle]', $excerpt)->item(0);
        expect($body->length)->toBe(1)
            ->and($button->hasAttribute('hidden'))->toBeTrue()
            ->and($button->getAttribute('aria-expanded'))->toBe('false')
            ->and($button->getAttribute('aria-controls'))->toBe($body->item(0)->getAttribute('id'));
    }
    $response->assertSee('Troisieme ligne')->assertDontSee('<script>PRIVATE-SCRIPT</script>', false);
    if ($external) {
        $response->assertDontSee('PRIVATE-SCRIPT');
    }
})->with([false, true]);

it('shows the opinion beside each native report name in internal and external views', function (bool $external) {
    foreach (array_keys(PostVisitWorkflow::OUTCOMES) as $outcome) {
        $this->dossier->documents()->create(['name' => 'REPORT-'.$outcome, 'path' => 'report-'.$outcome.'.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'document_type' => 'report', 'control_outcome' => $outcome, 'client_visible' => true]);
    }
    $this->dossier->documents()->create(['name' => 'FREE-DOCUMENT', 'path' => 'free.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'client_visible' => true]);
    $response = $this->actingAs($external ? $this->external : $this->staff)
        ->get(route($external ? 'client.dossiers.show' : 'v2.dossiers.show', $this->dossier))->assertOk();
    $xpath = ($this->xpath)($response->getContent());
    $selector = '//*[@id="'.($external ? 'client-documents' : 'documents').'"]';
    expect($xpath->query($selector.'//*[@data-report-outcome]')->length)->toBe(3);
    foreach (PostVisitWorkflow::OUTCOMES as $key => $label) {
        $badge = $xpath->query($selector.'//*[@data-report-outcome="'.$key.'"]')->item(0);
        $icon = $xpath->query('.//*[@role="img"]', $badge)->item(0);
        expect($icon->getAttribute('title'))->toBe($label)
            ->and($icon->getAttribute('aria-label'))->toBe($label)
            ->and($icon->getAttribute('tabindex'))->toBe('0')
            ->and($xpath->query('.//svg', $icon)->length)->toBe($key === 'unverifiable' ? 0 : 1);
        if ($key === 'unverifiable') {
            expect(trim($icon->textContent))->toBe('-');
        }
        expect($badge->parentNode->textContent)->toContain('REPORT-'.$key);
    }
})->with([false, true]);

it('normalizes known Coffrac labels without guessing an unknown opinion', function (mixed $value, ?string $expected) {
    $html = Blade::render('<x-report-outcome :outcome="$value" />', ['value' => $value]);
    $xpath = ($this->xpath)($html);
    expect($xpath->query('//*[@data-report-outcome]')->length)->toBe($expected === null ? 0 : 1);
    if ($expected !== null) {
        expect($xpath->query('//*[@data-report-outcome]')->item(0)->getAttribute('data-report-outcome'))->toBe($expected);
    }
})->with([
    ['SATISFAISANT', 'satisfactory'], [' NON   SATISFAISANT ', 'unsatisfactory'],
    ['Inaccessible / non vérifiable', 'unverifiable'], ['INACCESSIBLE', 'unverifiable'],
    ['Non vérifiable', 'unverifiable'], ['satisfactory', 'satisfactory'],
    [null, null], ['', null], ['Inconnu', null], ['Pas satisfaisant ?', null], [[], null], [1, null],
]);

it('also displays the recorded opinion beside a historical report without modifying the source', function () {
    $payload = json_encode(['name' => 'HISTORICAL-REPORT', 'status' => 'NON SATISFAISANT', 'reference_controle' => 'OLD-REF']);
    DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 543,
        'dossier_id' => $this->dossier->id, 'payload' => $payload]);
    $response = $this->actingAs($this->staff)->get(route('v2.dossiers.show', $this->dossier))->assertOk();
    $xpath = ($this->xpath)($response->getContent());
    $badge = $xpath->query('//*[@id="documents"]//*[@data-report-outcome="unsatisfactory"]')->item(0);
    expect($badge->parentNode->textContent)->toContain('HISTORICAL-REPORT')
        ->and(DB::table('legacy_records')->where('source_id', 543)->value('payload'))->toBe($payload);
});
