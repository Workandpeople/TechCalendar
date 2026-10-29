<?php

use App\Models\Dossier;
use App\Models\DossierBilling;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierArchiving;
use App\Services\Crm\DossierRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $this->actor = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'ARCHIVE', 'kind' => 'client', 'name' => 'Client archive', 'is_active' => true]);
    $this->external = User::factory()->create(['role' => 3, 'admin' => false, 'external_client_id' => $this->client->id,
        'portal_enabled' => true, 'portal_permissions' => ClientAccess::defaults('client')]);
    $this->dossier = Dossier::create(['reference' => 'DELETE-ME', 'client_id' => $this->client->id, 'company_name' => 'Dossier à supprimer', 'address' => '1 rue Test', 'city' => 'Paris']);
    $this->operation = $this->dossier->operations()->create();
    $this->operation->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    $this->actingAs($this->actor);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('soft deletes without losing billing documents raw states or history and restores the same dossier', function () {
    DossierBilling::forceCreate(['dossier_id' => $this->dossier->id, 'dossier_operation_id' => $this->operation->id,
        'marked_at' => now(), 'marked_by' => $this->actor->id, 'source' => 'native', 'evidence' => []]);
    $document = $this->dossier->documents()->create(['name' => 'Conserver', 'path' => 'retained.pdf', 'mime_type' => 'application/pdf', 'size' => 5, 'client_visible' => true]);
    Storage::disk('local')->put('retained.pdf', '%PDF-');
    $this->delete(route('v2.dossiers.destroy', $this->dossier), ['version' => 1, 'confirmed' => true, 'reason' => 'Doublon signalé'])
        ->assertRedirect(route('v2.dossiers.index', ['status' => 'unknown']));
    expect(Dossier::count())->toBe(0)->and(Dossier::withTrashed()->count())->toBe(1)
        ->and($this->dossier->refresh()->trashed())->toBeTrue()->and($this->dossier->version)->toBe(2)
        ->and($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT')
        ->and($this->dossier->billings()->count())->toBe(1)->and($this->dossier->documents()->count())->toBe(1);
    Storage::disk('local')->assertExists('retained.pdf');
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Restaurer le dossier')->assertDontSee('composer-sms');
    $this->get(route('v2.documents.download', $document))->assertOk();
    $registry = app(DossierRegistry::class);
    expect($registry->search($this->actor, [])->total())->toBe(0)
        ->and($registry->search($this->actor, ['status' => 'unknown'])->total())->toBe(1)
        ->and($registry->categories($this->actor, [])['rt_control']['count'])->toBe(0)
        ->and($registry->categories($this->actor, [])['unknown']['count'])->toBe(1);
    $this->post(route('v2.dossiers.restore', $this->dossier), ['version' => 2])->assertRedirect(route('v2.dossiers.show', $this->dossier));
    expect($this->dossier->refresh()->trashed())->toBeFalse()->and($this->dossier->version)->toBe(3)
        ->and($this->dossier->activities()->pluck('kind')->all())->toBe(['dossier_deleted', 'dossier_restored'])
        ->and($this->dossier->billings()->count())->toBe(1)->and($this->operation->refresh()->legacy_status)->toBe('CONTROLE RT');
    $this->postJson(route('v2.dossiers.billing.mark', $this->dossier), ['version' => 3])->assertUnprocessable();
});

it('blocks external direct links and document reads after deletion and exposes no mutation route', function () {
    $document = $this->dossier->documents()->create(['name' => 'Secret', 'path' => 'secret.pdf', 'mime_type' => 'application/pdf', 'size' => 5, 'client_visible' => true]);
    Storage::disk('local')->put('secret.pdf', '%PDF-');
    app(DossierArchiving::class)->delete($this->actor, $this->dossier, 1, null);
    $this->get(route('v2.dossiers.edit', $this->dossier))->assertNotFound();
    $this->patchJson(route('v2.dossiers.planning-assignment', $this->dossier), ['version' => 2, 'planning_user_id' => null])->assertNotFound();
    $this->actingAs($this->external);
    $this->get(route('client.dossiers'))->assertOk()->assertDontSee('Dossier à supprimer');
    $this->get(route('client.dossiers.show', $this->dossier))->assertNotFound();
    expect(app(ClientAccess::class)->viewDossier($this->external, $this->dossier->fresh()))->toBeFalse();
    expect($this->external->can('view', $document->fresh()->dossier))->toBeFalse();
});

it('denies delete and restore to planning technicians and external users', function (int $role) {
    $user = User::factory()->create(['role' => $role, 'admin' => false]);
    expect($user->can('delete', $this->dossier))->toBeFalse();
    app(DossierArchiving::class)->delete($this->actor, $this->dossier, 1, null);
    expect($user->can('restore', $this->dossier->refresh()))->toBeFalse();
})->with([1, 2, 3]);

it('rejects stale deletion and restoration without duplicate history', function () {
    $this->deleteJson(route('v2.dossiers.destroy', $this->dossier), ['version' => 99, 'confirmed' => true])->assertConflict();
    expect($this->dossier->activities()->count())->toBe(0);
    app(DossierArchiving::class)->delete($this->actor, $this->dossier, 1, null);
    $this->postJson(route('v2.dossiers.restore', $this->dossier), ['version' => 1])->assertConflict();
    expect($this->dossier->refresh()->trashed())->toBeTrue()->and($this->dossier->activities()->count())->toBe(1);
});

it('classifies the complete former unknown category and restores only unknown operations to booking', function () {
    $unknown = Dossier::create(['reference' => 'UNKNOWN-LEGACY', 'address' => '1 rue Test', 'city' => 'Paris']);
    $unknown->operations()->create()->forceFill(['legacy_status' => 'OLD-UNKNOWN'])->save();
    $unknown->operations()->create()->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    $empty = Dossier::create(['reference' => 'EMPTY-LEGACY', 'address' => '1 rue Test', 'city' => 'Paris']);
    expect(app(DossierArchiving::class)->classifyLegacyDeleted())->toBe(2);
    expect($this->dossier->fresh()->trashed())->toBeFalse()->and($unknown->refresh()->trashed())->toBeTrue();
    $this->post(route('v2.dossiers.restore', $unknown), ['version' => 1])->assertRedirect();
    expect($unknown->operations()->orderBy('id')->pluck('legacy_status')->all())->toBe(['PRISE DE RDV', 'RAPPORT GENIUS']);
    $this->postJson(route('v2.dossiers.restore', $empty), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('service_id');
    $service = Service::create(['name' => 'Inspection', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->post(route('v2.dossiers.restore', $empty), ['version' => 1, 'service_id' => $service->id])->assertRedirect();
    expect($empty->operations()->sole()->legacy_status)->toBe('PRISE DE RDV')
        ->and($empty->operations()->sole()->service_id)->toBe($service->id);
});
