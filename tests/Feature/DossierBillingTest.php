<?php

use App\Models\Dossier;
use App\Models\DossierBilling;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierBillingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
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
    $this->dossier = Dossier::create(['reference' => 'BILLING-TEST', 'company_name' => 'Test', 'address' => 'Rue Test', 'city' => 'Paris']);
    $this->service = Service::create(['name' => 'Inspection', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    $this->url = route('v2.dossiers.billing.store', [$this->dossier, $this->operation]);
    $this->actingAs($this->manager);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

function markDossierBilled($test, array $data = [])
{
    return $test->postJson($test->url, array_replace([
        'version' => $test->dossier->fresh()->version, 'confirmed' => true, 'invoice_reference' => 'F-2026-123',
    ], $data));
}

it('records billing once without changing the business status or dispatching anything', function () {
    markDossierBilled($this)->assertRedirect();
    $billing = $this->operation->billing()->sole();
    expect($billing->marked_by)->toBe($this->manager->id)
        ->and($billing->marked_at)->not->toBeNull()
        ->and($billing->source)->toBe('native')
        ->and($billing->invoice_reference)->toBe('F-2026-123')
        ->and($this->operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS')
        ->and($this->dossier->fresh()->version)->toBe(2);
    $this->assertDatabaseCount('dossier_billings', 1);
    expect($this->dossier->activities()->where('kind', 'billing')->count())->toBe(1);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Facturé')->assertSee('Déjà facturé')->assertSee('F-2026-123');

    markDossierBilled($this, ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('billing');
    markDossierBilled($this, ['invoice_reference' => 'DIFFERENT'])->assertUnprocessable();
    $this->assertDatabaseCount('dossier_billings', 1);
    expect($billing->fresh()->invoice_reference)->toBe('F-2026-123')
        ->and($this->dossier->activities()->where('kind', 'billing')->count())->toBe(1);
});

it('keeps the mark through a real reopening and return to non conformity', function () {
    markDossierBilled($this)->assertRedirect();
    $workflow = route('v2.dossiers.operations.post-visit', [$this->dossier, $this->operation]);
    foreach (['reopen_control', 'request_correction'] as $action) {
        $this->postJson($workflow, ['version' => $this->dossier->fresh()->version,
            'action' => $action, 'comment' => 'Correction de contrôle'])->assertRedirect();
    }
    expect($this->operation->fresh()->legacy_status)->toBe('NON CONFORMITES');
    markDossierBilled($this)->assertUnprocessable()->assertJsonValidationErrors('billing');
    $this->get(route('v2.dossiers.index', ['status' => 'non_compliant']))->assertOk()->assertSee('Facturé');
    $this->getJson(route('v2.dossiers.index', ['status' => 'invoiced']))->assertOk()->assertJsonPath('total', 1);
});

it('can reopen a historically invoiced operation without losing billing', function () {
    markDossierBilled($this)->assertRedirect();
    $this->operation->forceFill(['legacy_status' => 'DOSSIERS FACTURER'])->save();
    $this->postJson(route('v2.dossiers.operations.post-visit', [$this->dossier, $this->operation]), [
        'action' => 'reopen_control', 'version' => $this->dossier->fresh()->version, 'comment' => 'Recontrôle nécessaire',
    ])->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT')->and($this->operation->billing()->exists())->toBeTrue();
});

it('requires permission independently of workflow edit rights', function (int $role, bool $admin, bool $permission, int $expected) {
    $actor = User::factory()->create(['role' => $role, 'admin' => $admin, 'can_manage_billing' => $permission]);
    $this->actingAs($actor);
    markDossierBilled($this)->assertStatus($expected);
    $this->assertDatabaseCount('dossier_billings', $expected === 302 ? 1 : 0);
})->with([
    'planner' => [1, false, false, 403],
    'accounting planner' => [1, false, true, 302],
    'technician even with forged permission' => [2, false, true, 403],
    'external even with admin flag' => [3, true, true, 403],
    'administrator' => [1, true, false, 302],
]);

it('rejects a revoked permission even with a previously loaded actor', function () {
    $actor = User::factory()->create(['role' => 1, 'admin' => false, 'can_manage_billing' => true]);
    User::whereKey($actor->id)->update(['can_manage_billing' => false]);
    expect(fn () => app(DossierBillingService::class)->mark($actor, $this->dossier, $this->operation, ['version' => 1]))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('dossier_billings', 0);
});

it('requires explicit confirmation and a fresh version and validates the reference', function () {
    markDossierBilled($this, ['confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
    markDossierBilled($this, ['version' => 9])->assertUnprocessable()->assertJsonValidationErrors('version');
    markDossierBilled($this, ['invoice_reference' => str_repeat('x', 191)])->assertUnprocessable()->assertJsonValidationErrors('invoice_reference');
    $this->assertDatabaseCount('dossier_billings', 0);
});

it('blocks ambiguous imported evidence and unrelated operations', function () {
    $this->dossier->forceFill(['billing_review_required' => true])->save();
    markDossierBilled($this)->assertUnprocessable()->assertJsonValidationErrors('billing');
    $this->get(route('v2.dossiers.show', $this->dossier))->assertSee('Facturation à vérifier')->assertDontSee('Confirmer la facturation');
    $other = Dossier::create(['reference' => 'UNRELATED', 'address' => 'Rue Test', 'city' => 'Paris']);
    $this->postJson(route('v2.dossiers.billing.store', [$other, $this->operation]), [
        'version' => 1, 'confirmed' => true,
    ])->assertNotFound();
    $this->assertDatabaseCount('dossier_billings', 0);
});

it('blocks early stages and an unreconciled invoiced status', function (string $status) {
    $this->operation->forceFill(['legacy_status' => $status])->save();
    markDossierBilled($this)->assertUnprocessable()->assertJsonValidationErrors('billing');
    $this->assertDatabaseCount('dossier_billings', 0);
})->with(['PRISE DE RDV', 'RDV ATTENTE VISITE', 'DOSSIERS FACTURER', 'DOSSIER ANNULE']);

it('marks the entire dossier and rejects a second mark on a different operation', function () {
    $other = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $other->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    markDossierBilled($this)->assertRedirect();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertSee('Facturé')->assertDontSee('Partiellement');
    $this->getJson(route('v2.dossiers.index', ['billing' => 'invoiced', 'status' => 'non_compliant']))->assertJsonPath('total', 1);
    $this->getJson(route('v2.dossiers.index', ['billing' => 'unbilled']))->assertJsonPath('total', 0);
    $this->url = route('v2.dossiers.billing.store', [$this->dossier, $other]);
    markDossierBilled($this)->assertUnprocessable()->assertJsonValidationErrors('billing');
    $this->url = route('v2.dossiers.billing.mark', $this->dossier);
    markDossierBilled($this)->assertUnprocessable()->assertJsonValidationErrors('billing');
    $this->assertDatabaseCount('dossier_billings', 1);
    $this->getJson(route('v2.dossiers.index', ['billing' => 'anything']))->assertUnprocessable();
});

it('supports the dossier level endpoint without an operation parameter', function () {
    $this->url = route('v2.dossiers.billing.mark', $this->dossier);
    markDossierBilled($this)->assertRedirect();
    expect($this->dossier->billings()->sole()->evidence)->toBe(['scope' => 'dossier']);
});

it('allows accounting in appointment problem status and blocks rebilling after a state change', function (string $status) {
    $this->operation->forceFill(['legacy_status' => $status])->save();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Confirmer la facturation');
    $this->url = route('v2.dossiers.billing.mark', $this->dossier);
    markDossierBilled($this)->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe($status);
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    markDossierBilled($this)->assertUnprocessable()->assertJsonValidationErrors('billing');
    $this->assertDatabaseCount('dossier_billings', 1);
})->with(['Problème rendez- vous', 'PROBLÈME RENDEZ-VOUS']);

it('enforces the one mark per operation invariant at database level', function () {
    markDossierBilled($this)->assertRedirect();
    expect(fn () => DossierBilling::forceCreate(['dossier_id' => $this->dossier->id, 'dossier_operation_id' => $this->operation->id,
        'source' => 'native', 'marked_at' => now()]))->toThrow(UniqueConstraintViolationException::class);
});
