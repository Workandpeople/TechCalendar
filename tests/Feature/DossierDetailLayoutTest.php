<?php

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    $this->manager = User::factory()->create(['role' => 0]);
    $this->dossier = Dossier::create(['reference' => 'LAYOUT-TEST', 'company_name' => 'Entreprise exemple', 'address' => 'Rue du Test', 'city' => 'Paris', 'postal_code' => '75001']);
});

it('keeps the familiar Coffrac columns with unique sections and protected forms', function () {
    $this->dossier->activities()->create(['kind' => 'legacy_comment', 'body' => 'COMMENT-ONLY']);
    $this->dossier->activities()->create(['kind' => 'post_visit', 'body' => 'CONTROL-COMMENT']);
    $this->dossier->activities()->create(['kind' => 'updated', 'body' => 'HISTORY-ONLY']);
    $response = $this->actingAs($this->manager)->get(route('v2.dossiers.show', $this->dossier))->assertOk();
    $response->assertDontSee('Liaison existante')->assertDontSee('Corriger le rattachement')->assertDontSee('Toutes les dates source Coffrac');
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);

    foreach (['documents', 'commentaires', 'synthese', 'capcee'] as $id) {
        expect($xpath->query('//div[@class="gc-dossier-main"]//*[@id="'.$id.'"]')->length)->toBe(1);
    }
    foreach (['prestations', 'rendez-vous', 'intervenants', 'historique'] as $id) {
        expect($xpath->query('//aside[@class="gc-dossier-side"]//*[@id="'.$id.'"]')->length)->toBe(1);
    }
    expect($xpath->query('//*[@id="commentaires"]')->item(0)->textContent)->toContain('COMMENT-ONLY', 'CONTROL-COMMENT')->not->toContain('HISTORY-ONLY');
    expect($xpath->query('//*[@id="historique"]')->item(0)->textContent)->toContain('HISTORY-ONLY')->not->toContain('COMMENT-ONLY', 'CONTROL-COMMENT');
    expect($xpath->query('//*[@id="documents"]//table/thead/tr/th')->length)->toBe(4);
    foreach (['document', 'comment_body'] as $id) {
        expect($xpath->query('//*[@id="'.$id.'"]')->length)->toBe(1);
    }
    foreach ($xpath->query('//form[@method="POST"]') as $form) {
        expect($xpath->query('.//input[@name="_token"]', $form)->length)->toBe(1);
    }
    expect($xpath->query('//*[@id="documents-dossier" or @id="ajouter-commentaire"]//input[@name="client_visible"][@checked]')->length)->toBe(0);
    $response->assertSeeInOrder(['id="documents"', 'id="commentaires"', 'id="synthese"'], false);
    Http::assertNothingSent();
});

it('returns to comments after posting a client only note', function () {
    $this->actingAs($this->manager)->post(route('v2.dossiers.comments.store', $this->dossier), ['body' => 'Note de suivi'])
        ->assertRedirect(route('v2.dossiers.show', $this->dossier).'#commentaires');
    expect($this->dossier->comments()->sole()->client_visible)->toBeTrue()
        ->and($this->dossier->comments()->sole()->delegataire_visible)->toBeFalse();
});

it('keeps dense comments and documents accessible with protected sharing and deletion dialogs', function () {
    $comment = $this->dossier->activities()->create(['kind' => 'comment', 'body' => '<p>FIRST-LINE</p><p>FULL-COMMENT</p>']);
    $document = $this->dossier->documents()->create(['name' => 'COMPACT-FILE', 'description' => 'FULL-DESCRIPTION',
        'path' => 'test.pdf', 'mime_type' => 'application/pdf', 'size' => 2048]);
    $response = $this->actingAs($this->manager)->get(route('v2.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('FULL-COMMENT')->assertSee('FULL-DESCRIPTION')->assertDontSee('<p>FULL-COMMENT</p>', false);
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent(), LIBXML_NONET);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//li[contains(@class,"gc-comment-row")]//*[@data-comment-excerpt]')->length)->toBe(1)
        ->and($xpath->query('//li[contains(@class,"gc-comment-row")]//*[@data-excerpt-body]')->length)->toBe(1)
        ->and($xpath->query('//li[contains(@class,"gc-comment-row")]//button[@data-excerpt-toggle and @hidden]')->length)->toBe(1)
        ->and($xpath->query('//li[contains(@class,"gc-comment-row")]/a[contains(@class,"gc-compact-action")][@aria-label]')->length)->toBe(1)
        ->and($xpath->query('//dialog[@id="supprimer-commentaire-'.$comment->id.'"]//input[@name="_method"][@value="DELETE"]')->length)->toBe(1)
        ->and($xpath->query('//table[contains(@class,"gc-dossier-documents-table")]//details/summary')->length)->toBe(1)
        ->and($xpath->query('//a[contains(@class,"gc-sharing-compact")][@aria-label]')->length)->toBe(2)
        ->and($xpath->query('//a[@data-document-open][@href="'.route('v2.documents.download', ['document' => $document, 'preview' => 1]).'"]')->length)->toBe(1);
});

it('reopens an invalid document form with its name', function () {
    // Match the error bag stored by the application's JSON session serializer.
    $errors = ['default' => ['format' => ':message', 'messages' => ['document' => ['Le fichier est obligatoire.']]]];
    $response = $this->actingAs($this->manager)->withSession(['errors' => $errors, '_old_input' => ['name' => 'Justificatif']])
        ->get(route('v2.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('value="Justificatif"', false);
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent(), LIBXML_NONET);
    expect((new DOMXPath($dom))->query('//dialog[@id="documents-dossier"][@data-dialog-reopen]')->length)->toBe(1);
});
