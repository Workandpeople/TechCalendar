<?php

use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierDocument;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\LotClientAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    foreach (['client', 'delegataire'] as $kind) {
        $this->$kind = ExternalDelegataire::create(['name' => $kind.' organisation', 'kind' => $kind, 'source' => 'native', 'external_id' => $kind, 'is_active' => true]);
        $this->{$kind.'User'} = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false, 'external_client_id' => $this->$kind->id,
            'portal_enabled' => true, 'portal_permissions' => [...ClientAccess::defaults($kind), 'comments' => true], 'must_change_password' => false]);
    }
    $this->dossier = Dossier::create(['reference' => 'EXPERIENCE', 'company_name' => 'Beneficiary', 'address' => 'Rue de test', 'city' => 'Paris',
        'client_id' => $this->client->id, 'delegataire_id' => $this->delegataire->id]);
    $this->service = Service::create(['name' => 'Controle', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV VISITE'])->save();
    $this->actingAs($this->staff);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('finds the dossier by either assigned organisation without changing the beneficiary', function () {
    foreach ([$this->client, $this->delegataire] as $organisation) {
        $this->get(route('v2.dossiers.index', ['delegataire_id' => $organisation->id, 'sort' => 'company']))
            ->assertOk()->assertSee('EXPERIENCE')->assertSee('Beneficiary');
    }
});

it('clears only the lot owner when a dossier has two distinct organisations', function () {
    $lot = Lot::create(['name' => 'Double ownership', 'delegataire_id' => $this->client->id]);
    $row = $lot->appointments()->create(['customer_name' => 'Test']);
    $this->dossier->update(['source' => 'lot', 'source_reference' => (string) $row->id]);
    app(LotClientAssignment::class)->update($this->staff, $lot, ['delegataire_id' => null]);
    expect($this->dossier->fresh()->client_id)->toBeNull()
        ->and($this->dossier->fresh()->delegataire_id)->toBe($this->delegataire->id);
});

it('preserves manual client coordinates when assigning a delegataire to a previously unassigned lot', function () {
    $lot = Lot::create(['name' => 'Manual client']);
    $row = $lot->appointments()->create(['customer_name' => 'Test']);
    $this->dossier->update(['source' => 'lot', 'source_reference' => (string) $row->id,
        'client_id' => null, 'delegataire_id' => null, 'client_contact' => ['name' => 'Manual client']]);
    app(LotClientAssignment::class)->update($this->staff, $lot, ['delegataire_id' => $this->delegataire->id]);
    expect($this->dossier->fresh()->client_contact)->toBe(['name' => 'Manual client'])
        ->and($this->dossier->fresh()->delegataire_id)->toBe($this->delegataire->id);
});

it('assigns two distinct accounts with manual snapshots without changing beneficiary details', function () {
    $this->put(route('v2.dossiers.update', $this->dossier), [
        'reference' => 'EXPERIENCE', 'version' => 1, 'company_name' => 'Beneficiary', 'address' => 'Rue de test', 'city' => 'Paris',
        'client_id' => $this->client->id, 'delegataire_id' => $this->delegataire->id,
        'client_contact' => ['name' => 'Contact client', 'phone' => '0600000000'],
        'delegataire_contact' => ['name' => 'Contact delegataire', 'email' => 'test@example.test'],
    ])->assertSessionHasNoErrors()->assertRedirect();
    $this->dossier->refresh();
    expect($this->dossier->client_contact['name'])->toBe('Contact client')
        ->and($this->dossier->delegataire_contact['name'])->toBe('Contact delegataire')
        ->and($this->dossier->company_name)->toBe('Beneficiary');
    foreach ([$this->clientUser, $this->delegataireUser] as $user) {
        $this->actingAs($user)->get(route('client.dossiers.show', $this->dossier))->assertOk();
        $this->get(route('client.dossiers'))->assertSee('Beneficiary')
            ->assertSee(route('client.dossiers.show', $this->dossier), false)->assertDontSee('EXPERIENCE');
    }
    $this->actingAs($this->staff)->put(route('v2.dossiers.update', $this->dossier), [
        'reference' => 'EXPERIENCE', 'version' => 2, 'company_name' => 'Beneficiary', 'address' => 'Rue', 'city' => 'Paris',
        'client_id' => null, 'delegataire_id' => null, 'client_contact' => ['name' => 'Unregistered company'],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->clientUser)->get(route('client.dossiers.show', $this->dossier))->assertForbidden();
    $this->actingAs($this->delegataireUser)->get(route('client.dossiers.show', $this->dossier))->assertForbidden();
});

it('rejects cross type assignment and forged contact fields', function () {
    $this->putJson(route('v2.dossiers.update', $this->dossier), [
        'reference' => 'EXPERIENCE', 'version' => 1, 'company_name' => 'Beneficiary', 'address' => 'Rue', 'city' => 'Paris',
        'client_id' => $this->delegataire->id, 'delegataire_id' => $this->client->id, 'client_contact' => ['admin' => true],
    ])->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'delegataire_id', 'client_contact']);
});

it('enforces separate audiences on native documents comments and direct download routes', function (string $audience, bool $client, bool $delegate) {
    $this->post(route('v2.dossiers.documents.store', $this->dossier), ['name' => 'CONFIDENTIAL-PIECE', 'description' => 'Description test',
        'document' => UploadedFile::fake()->image('photo.png'), 'audience' => $audience])->assertSessionHasNoErrors();
    $this->post(route('v2.dossiers.comments.store', $this->dossier), ['body' => 'CONFIDENTIAL-NOTE', 'audience' => $audience])->assertSessionHasNoErrors();
    $document = $this->dossier->documents()->sole();
    foreach ([[$this->clientUser, $client], [$this->delegataireUser, $delegate]] as [$user, $allowed]) {
        $response = $this->actingAs($user)->get(route('client.dossiers.show', $this->dossier))->assertOk();
        if ($allowed) {
            $response->assertSee('CONFIDENTIAL-PIECE')->assertSee('CONFIDENTIAL-NOTE');
        } else {
            $response->assertDontSee('CONFIDENTIAL-PIECE')->assertDontSee('CONFIDENTIAL-NOTE');
        }
        $this->get(route('client.documents.download', $document))->assertStatus($allowed ? 200 : 404);
        $this->patch(route('v2.documents.sharing', $document), ['audience' => 'shared'])->assertForbidden();
    }
})->with([['internal', false, false], ['client', true, false], ['shared', true, true]]);

it('filters imported files by the separate override and retains original metadata', function () {
    DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 300, 'dossier_id' => $this->dossier->id,
        'payload' => json_encode(['name' => 'CLIENT-ARCHIVE', 'is_private' => 0, 'is_delegataire' => 1, 'reference_controle' => 'R-300', 'comment' => '<b>Description</b>'])]);
    $this->patch(route('v2.dossiers.collected-documents.sharing', [$this->dossier, 300]), ['audience' => 'client'])->assertSessionHasNoErrors();
    $this->actingAs($this->clientUser)->get(route('client.dossiers.show', $this->dossier))->assertSee('CLIENT-ARCHIVE');
    $this->actingAs($this->delegataireUser)->get(route('client.dossiers.show', $this->dossier))->assertDontSee('CLIENT-ARCHIVE');
    $this->actingAs($this->staff)->get(route('v2.dossiers.show', $this->dossier))->assertSee('R-300')->assertSee('&lt;b&gt;Description&lt;/b&gt;', false);
    $this->delete(route('v2.dossiers.collected-documents.destroy', [$this->dossier, 300]))->assertRedirect();
    $this->actingAs($this->clientUser)->get(route('client.dossiers.show', $this->dossier))->assertDontSee('CLIENT-ARCHIVE');
    expect(DB::table('legacy_records')->value('hidden_at'))->not->toBeNull();
});

it('removes comments from all presentations while keeping a private audit trail', function () {
    $comment = $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'DELETED-NOTE', 'client_visible' => true, 'delegataire_visible' => true]);
    $control = $this->dossier->activities()->create(['kind' => 'post_visit', 'body' => 'CONTROL-TRACE']);
    $this->delete(route('v2.dossiers.comments.destroy', [$this->dossier, $control->id]))->assertNotFound();
    $this->delete(route('v2.dossiers.comments.destroy', [$this->dossier, $comment->id]))->assertRedirect();
    expect($comment->fresh()->hidden_at)->not->toBeNull()->and($this->dossier->comments()->count())->toBe(1);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertDontSee('DELETED-NOTE')->assertSee('CONTROL-TRACE');
    $this->actingAs($this->clientUser)->get(route('client.dossiers.show', $this->dossier))->assertDontSee('DELETED-NOTE');
});

it('provides safe inline preview and blocks removal of a report used by a control', function () {
    $this->post(route('v2.dossiers.documents.store', $this->dossier), ['name' => 'Preview', 'document' => UploadedFile::fake()->image('preview.png')])->assertSessionHasNoErrors();
    $doc = $this->dossier->documents()->sole();
    $this->get(route('v2.documents.download', ['document' => $doc, 'preview' => 1]))->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $this->operation->forceFill(['report_document_id' => $doc->id])->save();
    $this->deleteJson(route('v2.documents.destroy', $doc))->assertUnprocessable();
    $this->operation->forceFill(['report_document_id' => null])->save();
    $this->delete(route('v2.documents.destroy', $doc))->assertRedirect();
    $this->get(route('v2.documents.download', $doc))->assertNotFound();
    expect(Storage::disk('local')->exists($doc->path))->toBeTrue()->and(DossierDocument::withTrashed()->find($doc->id)->trashed())->toBeTrue();
});

it('offers downloads but no browser preview for Office documents', function () {
    $this->dossier->documents()->create(['name' => 'Planning Office', 'path' => 'crm/planning.xlsx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => 100]);
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('Télécharger Planning Office')->assertDontSee('Voir Planning Office');
});

function experienceReport($test, array $extra = []): array
{
    return [...[
        'document_type' => 'report', 'name' => 'Rapport de controle', 'document' => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF"),
        'operation_id' => $test->operation->id, 'version' => $test->dossier->fresh()->version,
        'control_reference' => 'CONTROL-001', 'inspected_on' => now()->subDays(2)->toDateString(), 'issued_on' => now()->subDay()->toDateString(),
        'outcome' => 'satisfactory', 'description' => 'Conclusion du controle', 'audience' => 'client',
    ], ...$extra];
}

it('uploads reports with required metadata and reuses the existing RT workflow', function () {
    $url = route('v2.dossiers.documents.store', $this->dossier);
    $this->post($url, experienceReport($this, ['workflow_action' => 'submit_control']))->assertSessionHasNoErrors();
    expect($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT');
    $doc = $this->dossier->documents()->sole();
    expect($doc->control_reference)->toBe('CONTROL-001')->and($doc->issued_on->toDateString())->toBe(now()->subDay()->toDateString())
        ->and($doc->client_visible)->toBeTrue()->and($doc->delegataire_visible)->toBeFalse();
    $this->post($url, experienceReport($this, ['workflow_action' => 'validate_report', 'outcome' => 'unsatisfactory']))->assertSessionHasNoErrors();
    expect($this->operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS')->and($this->operation->fresh()->control_outcome)->toBe('unsatisfactory');
    expect($this->dossier->operations()->count())->toBe(1);
});

it('rolls back both file and records for forbidden report transitions', function () {
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->postJson(route('v2.dossiers.documents.store', $this->dossier), experienceReport($this, ['workflow_action' => 'validate_report']))->assertUnprocessable();
    expect($this->dossier->documents()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->operation->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    $this->actingAs($planner)->postJson(route('v2.dossiers.documents.store', $this->dossier), experienceReport($this, ['workflow_action' => 'validate_report']))->assertForbidden();
    expect($this->dossier->documents()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('rejects a report for another dossier or invalid dates without storing a file', function () {
    $other = Dossier::create(['reference' => 'OTHER', 'address' => 'Rue', 'city' => 'Paris']);
    $operation = $other->operations()->create(['service_id' => $this->service->id]);
    $this->postJson(route('v2.dossiers.documents.store', $this->dossier), experienceReport($this, ['operation_id' => $operation->id]))->assertNotFound();
    $this->postJson(route('v2.dossiers.documents.store', $this->dossier), experienceReport($this, ['issued_on' => now()->subDays(10)->toDateString()]))->assertUnprocessable();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('defaults new contents to client only without exposing older internal records', function () {
    $old = $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'OLD-PRIVATE', 'client_visible' => false, 'delegataire_visible' => false]);
    $this->post(route('v2.dossiers.comments.store', $this->dossier), ['body' => 'NEW-CLIENT-NOTE'])->assertSessionHasNoErrors();
    $this->post(route('v2.dossiers.documents.store', $this->dossier), ['name' => 'NEW-CLIENT-DOC', 'document' => UploadedFile::fake()->image('new.png')])->assertSessionHasNoErrors();
    $note = $this->dossier->comments()->where('body', 'NEW-CLIENT-NOTE')->sole();
    $doc = $this->dossier->documents()->sole();
    expect($note->client_visible)->toBeTrue()->and($note->delegataire_visible)->toBeFalse()
        ->and($doc->client_visible)->toBeTrue()->and($doc->delegataire_visible)->toBeFalse()
        ->and($old->fresh()->client_visible)->toBeFalse();
    $this->actingAs($this->clientUser)->get(route('client.dossiers.show', $this->dossier))
        ->assertSee('NEW-CLIENT-NOTE')->assertSee('NEW-CLIENT-DOC')->assertDontSee('OLD-PRIVATE');
    $this->actingAs($this->delegataireUser)->get(route('client.dossiers.show', $this->dossier))
        ->assertDontSee('NEW-CLIENT-NOTE')->assertDontSee('NEW-CLIENT-DOC')->assertDontSee('OLD-PRIVATE');
});

it('uses the same automatic report transitions for every opinion and ignores obsolete form actions', function (string $outcome) {
    $url = route('v2.dossiers.documents.store', $this->dossier);
    $this->post($url, experienceReport($this, ['outcome' => $outcome, 'workflow_action' => 'store']))->assertSessionHasNoErrors();
    expect($this->operation->fresh()->legacy_status)->toBe('CONTROLE RT')
        ->and($this->operation->fresh()->control_outcome)->toBe($outcome)
        ->and($this->operation->fresh()->report_document_id)->not->toBeNull();
    $this->post($url, experienceReport($this, ['outcome' => $outcome]))->assertSessionHasNoErrors();
    expect($this->operation->fresh()->legacy_status)->toBe('RAPPORT GENIUS')
        ->and($this->operation->fresh()->control_outcome)->toBe($outcome);
})->with(['satisfactory', 'unsatisfactory', 'unverifiable']);

it('allows each assigned external party to explicitly request recontrol without acquiring other write rights', function (string $kind) {
    $this->operation->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $user = $this->{$kind.'User'};
    $user->forceFill(['portal_permissions' => []])->save();
    $url = route('client.dossiers.recontrol', [$this->dossier, $this->operation]);
    $this->actingAs($user)->get(route('client.dossiers.show', $this->dossier))->assertSee('Transmettre pour recontrôle');
    $this->postJson($url, ['version' => 1, 'comment' => 'Not allowed'])->assertForbidden();
    $this->postJson($url, ['version' => 1, 'document' => UploadedFile::fake()->image('forbidden.png')])->assertForbidden();
    $this->postJson($url, ['version' => 1])->assertRedirect();
    expect($this->operation->fresh()->legacy_status)->toBe('RECONTROLE')
        ->and($this->operation->fresh()->control_outcome)->toBeNull()
        ->and($this->dossier->documents()->count())->toBe(0);
    $this->postJson($url, ['version' => 1])->assertUnprocessable();
    $this->postJson($url, ['version' => 2])->assertUnprocessable();
    expect($this->dossier->activities()->count())->toBe(1);
})->with(['client', 'delegataire']);

it('refuses reports before the visit or outside a review stage without orphan files', function (string $state) {
    $this->operation->forceFill(['legacy_status' => $state])->save();
    $this->postJson(route('v2.dossiers.documents.store', $this->dossier), experienceReport($this))
        ->assertUnprocessable()->assertJsonValidationErrors('operation_id');
    expect($this->dossier->fresh()->version)->toBe(1)->and($this->dossier->documents()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['PRISE DE RDV', 'RDV ATTENTE VISITE', 'NON CONFORMITES', 'RAPPORT GENIUS', 'DOSSIER ANNULE']);

it('rolls back an uploaded report and opinion together if the workflow audit fails', function () {
    DossierActivity::creating(function ($activity) {
        if ($activity->kind === 'post_visit') {
            throw new RuntimeException('Audit unavailable');
        }
    });
    $this->postJson(route('v2.dossiers.documents.store', $this->dossier), experienceReport($this))->assertServerError();
    expect($this->dossier->fresh()->version)->toBe(1)->and($this->operation->fresh()->legacy_status)->toBe('RDV VISITE')
        ->and($this->operation->fresh()->control_outcome)->toBeNull()->and($this->dossier->documents()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
