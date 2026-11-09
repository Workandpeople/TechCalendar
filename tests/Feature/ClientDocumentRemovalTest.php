<?php

use App\Models\CapceeAutomaticSubmission;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierDocument;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\ClientPortal;
use App\Services\Crm\DossierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'REMOVAL',
        'kind' => 'client', 'name' => 'Client test', 'is_active' => true]);
    $this->client = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->organisation->id, 'portal_enabled' => true,
        'must_change_password' => false, 'portal_permissions' => ClientAccess::defaults('client')]);
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->dossier = Dossier::create(['reference' => 'CLIENT-REMOVAL', 'client_id' => $this->organisation->id,
        'company_name' => 'Beneficiaire test', 'address' => 'Rue test', 'city' => 'Lyon']);
    $this->operation = $this->dossier->operations()->create();
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->makeDocument = function (array $values = []): DossierDocument {
        $path = 'crm/dossiers/'.$this->dossier->id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        return $this->dossier->documents()->create([...[
            'author_id' => $this->client->id, 'name' => 'Facture client', 'document_type' => 'free',
            'path' => $path, 'mime_type' => 'application/pdf', 'size' => 13,
            'client_visible' => true, 'delegataire_visible' => true,
        ], ...$values]);
    };
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('withdraws only its own attachment with a confirmation dialog and a private atomic audit trail', function () {
    $this->actingAs($this->client)->post(route('client.dossiers.upload', $this->dossier), [
        'name' => 'Facture client', 'document' => UploadedFile::fake()->createWithContent('facture.pdf', '%PDF-1.4 test'),
    ])->assertSessionHasNoErrors();
    $document = DossierDocument::sole();
    $before = $this->dossier->fresh()->getAttributes();
    $operation = $this->operation->fresh()->getAttributes();
    $url = route('client.documents.destroy', $document);
    $page = $this->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('Supprimer Facture client')->assertSee('Supprimer votre document ?')
        ->assertSee($url, false);
    $dom = new DOMDocument;
    @$dom->loadHTML($page->getContent());
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//dialog[@id="client-document-remove-'.$document->id.'"]//form/input[@name="_method"][@value="DELETE"]')->length)->toBe(1)
        ->and($xpath->query('//dialog[@id="client-document-remove-'.$document->id.'"]//form/input[@name="_token"]')->length)->toBe(1);

    $this->delete($url)->assertRedirect(route('client.dossiers.show', $this->dossier).'#client-documents')
        ->assertSessionHas('status', 'Votre document a été supprimé du dossier.');
    $removed = DossierDocument::withTrashed()->findOrFail($document->id);
    expect($removed->trashed())->toBeTrue()->and($removed->client_visible)->toBeFalse()->and($removed->delegataire_visible)->toBeFalse()
        ->and($this->dossier->fresh()->getAttributes())->toBe($before)
        ->and($this->operation->fresh()->getAttributes())->toBe($operation);
    Storage::disk('local')->assertExists($document->path);
    $audit = $this->dossier->activities()->where('kind', 'document_removed')->sole();
    expect($audit->author_id)->toBe($this->client->id)->and($audit->client_visible)->toBeFalse()
        ->and($audit->delegataire_visible)->toBeFalse()->and($audit->changed_fields['document_id'])->toBe($document->id);
    $this->get(route('client.documents.download', $document))->assertNotFound();
    $this->delete($url)->assertNotFound();
    $this->get(route('client.dossiers.show', $this->dossier))->assertOk()->assertDontSee('Facture client');
});

it('works in every accessible workflow stage without changing it', function (string $status) {
    $this->operation->forceFill(['legacy_status' => $status])->save();
    $document = ($this->makeDocument)();
    $this->actingAs($this->client)->delete(route('client.documents.destroy', $document))->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe($status);
})->with(['PRISE DE RDV', 'RDV ATTENTE VISITE', 'RDV VISITE', 'NON CONFORMITES', 'RECONTROLE', 'RAPPORT GENIUS']);

it('never lets a client remove an internal another client or unattributed document', function (string $owner) {
    $author = match ($owner) {
        'internal' => $this->staff,
        'same_organisation' => User::factory()->create(['role' => User::ROLE_EXTERNAL, 'external_client_id' => $this->organisation->id]),
        'other_organisation' => User::factory()->create(['role' => User::ROLE_EXTERNAL]),
        default => null,
    };
    $document = ($this->makeDocument)(['author_id' => $author?->id]);
    $this->actingAs($this->client)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertDontSee('data-dialog-target="client-document-remove-'.$document->id.'"', false)->assertDontSee('Supprimer Facture client');
    $this->delete(route('client.documents.destroy', $document), ['author_id' => $this->client->id])->assertForbidden();
    expect($document->fresh()->trashed())->toBeFalse();
})->with(['internal', 'same_organisation', 'other_organisation', 'unknown']);

it('refuses non-free private and already used evidence even when the client is the author', function (string $protection) {
    $document = ($this->makeDocument)();
    match ($protection) {
        'report', 'visit' => $document->update(['document_type' => $protection]),
        'private' => $document->update(['client_visible' => false]),
        'control' => $this->operation->forceFill(['report_document_id' => $document->id])->save(),
        'visit_evidence', 'correction_evidence' => $this->dossier->activities()->create(['kind' => 'post_visit', 'body' => 'Justificatif utilise',
            'changed_fields' => [($protection === 'visit_evidence' ? 'visit_document_id' : 'response_document_id') => $document->id]]),
        'capcee' => CapceeAutomaticSubmission::create(['dossier_document_id' => $document->id,
            'delegataire_id' => $this->organisation->id, 'requested_by' => $this->staff->id,
            'submission_token' => (string) Str::uuid(), 'environment' => 'test']),
    };
    $this->actingAs($this->client)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertDontSee('Supprimer Facture client');
    $this->delete(route('client.documents.destroy', $document))->assertForbidden();
    expect($document->fresh()->trashed())->toBeFalse();
    Storage::disk('local')->assertExists($document->path);
})->with(['report', 'visit', 'private', 'control', 'visit_evidence', 'correction_evidence', 'capcee']);

it('applies current categories organisation access and write permission on direct requests', function (string $revocation) {
    $document = ($this->makeDocument)();
    match ($revocation) {
        'categories' => $this->organisation->update(['portal_categories' => ['report']]),
        'organisation' => $this->organisation->update(['is_active' => false]),
        'portal' => $this->client->forceFill(['portal_enabled' => false])->save(),
        'password' => $this->client->update(['must_change_password' => true]),
        'deleted' => $this->dossier->delete(),
        'assignment' => $this->dossier->update(['client_id' => ExternalDelegataire::create(['source' => 'native',
            'external_id' => 'OTHER', 'kind' => 'client', 'name' => 'Other', 'is_active' => true])->id]),
        'documents' => $this->client->forceFill(['portal_permissions' => [...ClientAccess::defaults('client'), 'documents' => false]])->save(),
        'write' => $this->client->forceFill(['portal_permissions' => [...ClientAccess::defaults('client'), 'upload_documents' => false, 'upload_corrections' => false]])->save(),
    };
    $response = $this->actingAs($this->client)->delete(route('client.documents.destroy', $document));
    $revocation === 'password' ? $response->assertRedirect(route('profile')) : $response->assertForbidden();
    expect($document->fresh()->trashed())->toBeFalse();
})->with(['categories', 'organisation', 'portal', 'password', 'deleted', 'assignment', 'documents', 'write']);

it('does not grant delegataires or internal accounts access to the client endpoint', function (string $role) {
    $document = ($this->makeDocument)();
    if ($role === 'delegataire') {
        $this->organisation->update(['kind' => 'delegataire']);
        $this->dossier->update(['delegataire_id' => $this->organisation->id]);
    }
    $this->actingAs($role === 'delegataire' ? $this->client : $this->staff)
        ->delete(route('client.documents.destroy', $document))->assertForbidden();
    expect($document->fresh()->trashed())->toBeFalse();
})->with(['delegataire', 'internal']);

it('rechecks dossier assignment under the mutation lock instead of trusting the loaded model', function () {
    $document = ($this->makeDocument)()->load('dossier');
    DB::table('dossiers')->where('id', $this->dossier->id)->update(['client_id' => null]);
    expect(fn () => app(DossierRegistry::class)->removeClientDocument($this->client, $document))->toThrow(HttpException::class);
    expect($document->fresh()->trashed())->toBeFalse();
});

it('rolls back withdrawal when the audit cannot be persisted', function () {
    $document = ($this->makeDocument)();
    DossierActivity::creating(function (DossierActivity $activity): void {
        if ($activity->kind === 'document_removed') {
            throw new RuntimeException('Intentional audit failure');
        }
    });
    try {
        $this->actingAs($this->client)->deleteJson(route('client.documents.destroy', $document))->assertServerError();
        expect($document->fresh()->trashed())->toBeFalse()->and($document->fresh()->client_visible)->toBeTrue();
        Storage::disk('local')->assertExists($document->path);
    } finally {
        DossierActivity::flushEventListeners();
    }
});

it('loads removable IDs for only the current page with a constant query count', function () {
    $this->client->load('externalClient');
    ($this->makeDocument)();
    $queries = function (): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = app(ClientPortal::class)->dossier($this->client, $this->dossier->fresh());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, $data];
    };
    [$before] = $queries();
    for ($index = 0; $index < 17; $index++) {
        ($this->makeDocument)();
    }
    [$after, $data] = $queries();
    expect($after)->toBe($before)->and($data['documents']->count())->toBe(15)
        ->and($data['removableDocumentIds'])->toHaveCount(15)
        ->and(array_diff($data['removableDocumentIds'], $data['documents']->getCollection()->modelKeys()))->toBe([]);
});

it('does not leak a remove action to internal or historical rows and escapes the confirmation name', function () {
    $document = ($this->makeDocument)(['name' => '<script>alert(1)</script>']);
    ($this->makeDocument)(['author_id' => $this->staff->id, 'name' => 'Piece interne']);
    DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 99,
        'dossier_id' => $this->dossier->id, 'payload' => json_encode(['name' => 'Piece historique', 'is_private' => 0])]);
    $response = $this->actingAs($this->client)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('Piece interne')->assertSee('Piece historique')->assertDontSee('<script>alert(1)</script>', false);
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//dialog[starts-with(@id,"client-document-remove-")]')->length)->toBe(1)
        ->and($xpath->query('//dialog[@id="client-document-remove-'.$document->id.'"]//script')->length)->toBe(0);
});
