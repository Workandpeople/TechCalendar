<?php

use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierRegistry;
use App\Services\DocumentUpload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->accounts = [];
    foreach (['client', 'delegataire'] as $kind) {
        $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'CORRECTION-'.$kind,
            'kind' => $kind, 'name' => 'Test '.$kind, 'is_active' => true]);
        $this->accounts[$kind] = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
            'external_client_id' => $organisation->id, 'portal_enabled' => true,
            'portal_permissions' => ClientAccess::defaults($kind), 'must_change_password' => false]);
    }
    $this->dossier = Dossier::create(['reference' => 'CORRECTION-DOCS', 'address' => 'Rue du Test', 'city' => 'Paris',
        'client_id' => $this->accounts['client']->external_client_id,
        'delegataire_id' => $this->accounts['delegataire']->external_client_id]);
    $this->operation = $this->dossier->operations()->create(['legacy_service_name' => 'Prestation test']);
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->uploadUrl = route('client.dossiers.upload', $this->dossier);
    $this->responseUrl = route('client.dossiers.recontrol', [$this->dossier, $this->operation]);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('lets assigned clients upload free corrections with existing or new permissions without changing state', function (bool $legacy) {
    $account = $this->accounts['client'];
    $permissions = $account->portal_permissions;
    $permissions['upload_documents'] = false;
    if ($legacy) {
        unset($permissions['upload_corrections']);
    }
    $account->forceFill(['portal_permissions' => $permissions])->save();
    $private = $this->dossier->documents()->create(['name' => 'PRIVATE-SECRET', 'path' => 'secret.pdf',
        'mime_type' => 'application/pdf', 'size' => 12, 'client_visible' => false, 'delegataire_visible' => false]);
    $this->actingAs($account)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('id="client-document-upload"', false)->assertSee('href="#client-document-upload"', false)
        ->assertSee('Justificatif (1 Go maximum)')->assertDontSee('PRIVATE-SECRET');
    $this->get(route('client.documents.download', $private))->assertNotFound();
    foreach (['Photo corrigée', 'Justificatif complémentaire'] as $name) {
        $this->postJson($this->uploadUrl, ['name' => $name, 'document' => UploadedFile::fake()->image('preuve.jpg'),
            'client_visible' => false, 'delegataire_visible' => true, 'document_type' => 'report'])->assertRedirect();
        $document = $this->dossier->documents()->where('name', $name)->sole();
        expect($document->author_id)->toBe($account->id)->and($document->client_visible)->toBeTrue()
            ->and($document->delegataire_visible)->toBeFalse()
            ->and($document->document_type)->toBe('free');
        Storage::disk('local')->assertExists($document->path);
        $this->get(route('client.documents.download', $document))->assertDownload();
    }
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES')
        ->and($this->dossier->fresh()->version)->toBe(1)
        ->and($this->dossier->activities()->where('kind', 'post_visit')->count())->toBe(0);
})->with([true, false]);

it('atomically attaches a client correction with the explicit recontrol request', function () {
    $account = $this->accounts['client'];
    $account->forceFill(['portal_permissions' => [...$account->portal_permissions, 'upload_documents' => false]])->save();
    $this->actingAs($account)->postJson($this->responseUrl, ['version' => 1,
        'document_name' => 'Correction finale', 'document' => UploadedFile::fake()->image('final.jpg')])->assertRedirect();
    $document = $this->dossier->documents()->sole();
    $activity = $this->dossier->activities()->where('kind', 'post_visit')->sole();
    expect($this->operation->fresh()->legacy_status)->toBe('RECONTROLE')
        ->and($activity->changed_fields['response_document_id'])->toBe($document->id)
        ->and($document->author_id)->toBe($account->id);
    $response = $this->get(route('client.dossiers.show', $this->dossier))->assertOk();
    $upload = ['name' => 'Complément', 'document' => UploadedFile::fake()->image('late.jpg')];
    $response->assertSee('id="client-document-upload"', false);
    $this->postJson($this->uploadUrl, $upload)->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('RECONTROLE');
    $this->get(route('client.documents.download', $document))->assertDownload();
});

it('refuses delegataire uploads in every state even with historical or forged grant flags', function (string $state) {
    $this->operation->forceFill(['legacy_status' => $state])->save();
    $account = $this->accounts['delegataire'];
    $account->forceFill(['portal_permissions' => array_fill_keys(array_keys(ClientAccess::PERMISSIONS), true)])->save();
    $this->actingAs($account)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertDontSee('id="client-document-upload"', false)->assertDontSee('name="document"', false);
    $this->postJson($this->uploadUrl, [
        'name' => 'Non autorisé', 'document' => UploadedFile::fake()->image('denied.jpg'),
    ])->assertForbidden();
    $this->postJson($this->responseUrl, ['version' => 1, 'document' => UploadedFile::fake()->image('denied.jpg')])->assertForbidden();
    expect(fn () => app(DossierRegistry::class)->addDocument($account, $this->dossier,
        UploadedFile::fake()->image('direct.jpg'), 'Interdit'))->toThrow(AuthorizationException::class);
    expect($this->dossier->documents()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['PRISE DE RDV', 'RDV ATTENTE VISITE', 'RDV VISITE', 'CONTROLE RT', 'NON CONFORMITES', 'RECONTROLE', 'RAPPORT GENIUS', 'DOSSIERS FACTURER', 'DOSSIER ANNULE']);

it('honors explicit correction revocation and requires document read access', function (string $kind, array $permissions) {
    $account = $this->accounts[$kind];
    $account->forceFill(['portal_permissions' => [...$account->portal_permissions, 'upload_documents' => false, ...$permissions]])->save();
    $this->actingAs($account)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertDontSee('id="client-document-upload"', false)->assertDontSee('Justificatif (1 Go maximum)');
    $file = ['name' => 'Interdit', 'document' => UploadedFile::fake()->image('denied.jpg')];
    $this->postJson($this->uploadUrl, $file)->assertForbidden();
    $this->postJson($this->responseUrl, ['version' => 1, ...$file])->assertForbidden();
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES')
        ->and($this->dossier->documents()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['client'])->with([[['upload_corrections' => false]], [['documents' => false]]]);

it('lets an assigned client upload in every visible state without changing state billing or sharing', function (string $state) {
    $this->operation->forceFill(['legacy_status' => $state])->save();
    $account = $this->accounts['client'];
    $this->actingAs($account)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('id="client-document-upload"', false);
    $this->postJson($this->uploadUrl, ['name' => 'Pièce complémentaire',
        'document' => UploadedFile::fake()->image('piece.jpg'), 'delegataire_visible' => true,
        'document_type' => 'report', 'control_outcome' => 'satisfactory'])->assertRedirect();
    $document = $this->dossier->documents()->sole();
    expect($this->operation->fresh()->legacy_status)->toBe($state)
        ->and($this->dossier->fresh()->version)->toBe(1)
        ->and($document->author_id)->toBe($account->id)->and($document->client_visible)->toBeTrue()
        ->and($document->delegataire_visible)->toBeFalse()->and($document->document_type)->toBe('free')
        ->and($document->control_outcome)->toBeNull()->and($this->dossier->billings()->count())->toBe(0);
    $this->get(route('client.documents.download', $document))->assertDownload();
    $this->actingAs($this->accounts['delegataire'])->get(route('client.documents.download', $document))->assertNotFound();
})->with(['PRISE DE RDV', 'Problème rendez- vous', 'RDV ATTENTE VISITE', 'RDV VISITE', 'CONTROLE RT',
    'NON CONFORMITES', 'RECONTROLE', 'RAPPORT GENIUS', 'DOSSIERS FACTURER', 'DOSSIER ANNULE']);

it('still refuses client uploads to deleted or category-blocked dossiers', function (bool $deleted) {
    if ($deleted) {
        $this->dossier->delete();
    } else {
        $this->accounts['client']->externalClient->update(['portal_categories' => ['pending']]);
    }
    $this->actingAs($this->accounts['client'])->postJson($this->uploadUrl, [
        'name' => 'Interdit', 'document' => UploadedFile::fake()->image('denied.jpg'),
    ])->assertStatus($deleted ? 404 : 403);
    expect($this->dossier->documents()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([true, false]);

it('refuses foreign dossiers and invalid uploads', function (string $kind) {
    $other = Dossier::create(['reference' => 'FOREIGN', 'address' => 'Rue', 'city' => 'Paris']);
    $other->operations()->create(['legacy_service_name' => 'Autre'])->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->actingAs($this->accounts[$kind])->postJson(route('client.dossiers.upload', $other), [
        'name' => 'Interdit', 'document' => UploadedFile::fake()->image('denied.jpg'),
    ])->assertForbidden();
    $this->postJson($this->uploadUrl, ['name' => 'Script', 'document' => UploadedFile::fake()->create('bad.php', 1, 'text/plain')])->assertUnprocessable();
    $this->postJson($this->uploadUrl, ['name' => 'Trop gros', 'document' => UploadedFile::fake()->create('big.pdf', DocumentUpload::MAX_KILOBYTES + 1, 'application/pdf')])->assertUnprocessable();
    expect($this->dossier->documents()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['client']);

it('rechecks state and assignment under the dossier lock before recording the document', function (string $change) {
    $stale = $this->dossier->fresh()->load('operations');
    if ($change === 'state') {
        $this->accounts['client']->externalClient->update(['portal_categories' => ['non_compliant']]);
        $this->operation->forceFill(['legacy_status' => 'RECONTROLE'])->save();
    } else {
        $this->dossier->update(['client_id' => null]);
    }
    expect(fn () => app(DossierRegistry::class)->addDocument($this->accounts['client'], $stale,
        UploadedFile::fake()->image('stale.jpg'), 'Ancien formulaire'))->toThrow(AuthorizationException::class);
    expect($this->dossier->documents()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['state', 'assignment']);

it('rolls back a correction-only upload when the response form is stale', function () {
    $this->actingAs($this->accounts['client'])->postJson($this->responseUrl, ['version' => 999,
        'document' => UploadedFile::fake()->image('stale.jpg')])->assertUnprocessable()->assertJsonValidationErrors('version');
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES')
        ->and($this->dossier->fresh()->version)->toBe(1)->and($this->dossier->documents()->count())->toBe(0)
        ->and($this->dossier->activities()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('lets staff revoke the correction right on an existing account', function () {
    $staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $account = $this->accounts['client'];
    $permissions = $account->portal_permissions;
    $permissions['upload_documents'] = false;
    unset($permissions['upload_corrections']);
    $account->forceFill(['portal_permissions' => $permissions])->save();
    $this->actingAs($staff)->get(route('manager.clients.edit', $account->externalClient))->assertOk()
        ->assertSee('name="permissions[upload_corrections]" value="1" checked', false);
    $this->put(route('manager.clients.accounts.update', [$account->externalClient, $account]), [
        'first_name' => 'Test', 'last_name' => 'Client', 'email' => $account->email,
        'portal_enabled' => true, 'permissions' => [...$permissions, 'upload_corrections' => false],
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect($account->fresh()->portal_permissions['upload_corrections'])->toBeFalse();
    $this->actingAs($account->fresh())->postJson($this->uploadUrl, ['name' => 'Interdit',
        'document' => UploadedFile::fake()->image('denied.jpg')])->assertForbidden();
});

it('does not grant correction access to disabled or password-blocked external accounts', function (string $restriction) {
    $account = $this->accounts['client'];
    match ($restriction) {
        'organisation' => $account->externalClient->update(['is_active' => false]),
        'account' => $account->forceFill(['portal_enabled' => false])->save(),
        'password' => $account->forceFill(['must_change_password' => true])->save(),
        'deleted' => $account->delete(),
    };
    expect($account->can('uploadDocument', $this->dossier))->toBeFalse();
    expect(fn () => app(DossierRegistry::class)->addDocument($account, $this->dossier,
        UploadedFile::fake()->image('denied.jpg'), 'Interdit'))->toThrow(AuthorizationException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['organisation', 'account', 'password', 'deleted']);

it('hides client-only rights for delegataires and normalizes old admin forms on save', function () {
    $staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $account = $this->accounts['delegataire'];
    $this->actingAs($staff)->get(route('manager.clients.edit', $account->externalClient))->assertOk()
        ->assertSee('name="permissions[documents]"', false)->assertSee('name="permissions[comments]"', false)
        ->assertDontSee('name="permissions[upload_documents]"', false)
        ->assertDontSee('name="permissions[upload_corrections]"', false);
    $this->put(route('manager.clients.accounts.update', [$account->externalClient, $account]), [
        'first_name' => 'Test', 'last_name' => 'Delegataire', 'email' => $account->email,
        'portal_enabled' => true, 'permissions' => array_fill_keys(array_keys(ClientAccess::PERMISSIONS), true),
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect($account->fresh()->portal_permissions)->toMatchArray([
        'create_dossiers' => false, 'upload_documents' => false, 'upload_corrections' => false,
        'documents' => true, 'comments' => true, 'lots' => true,
    ]);
});

it('forces free document metadata for clients even when called outside the controller', function () {
    $document = app(DossierRegistry::class)->addDocument($this->accounts['client'], $this->dossier,
        UploadedFile::fake()->image('free.jpg'), 'Document libre', metadata: [
            'document_type' => 'report', 'dossier_operation_id' => $this->operation->id,
            'control_reference' => 'FORGED', 'control_outcome' => 'satisfactory',
            'inspected_on' => '2026-10-27', 'capcee_emmy_reference' => 'FORGED',
        ]);
    expect($document->document_type)->toBe('free')->and($document->dossier_operation_id)->toBeNull()
        ->and($document->control_reference)->toBeNull()->and($document->control_outcome)->toBeNull()
        ->and($document->inspected_on)->toBeNull()->and($document->capcee_emmy_reference)->toBeNull()
        ->and($document->delegataire_visible)->toBeFalse();
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES');
});
