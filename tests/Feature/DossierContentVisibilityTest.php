<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotAppointmentDocument;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\CollectedDossierDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
    $this->staff = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'VISIBILITY',
        'kind' => 'client', 'name' => 'Client test', 'is_active' => true]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->client->id, 'portal_enabled' => true,
        'portal_permissions' => ClientAccess::defaults('client'), 'must_change_password' => false]);
    $this->dossier = Dossier::create(['reference' => 'VISIBILITY', 'delegataire_id' => $this->client->id, 'address' => 'Rue de test', 'city' => 'Paris']);
    $this->other = Dossier::create(['reference' => 'FOREIGN', 'address' => 'Rue de test', 'city' => 'Paris']);
    $this->collection = sys_get_temp_dir().'/visibility-test-'.bin2hex(random_bytes(8));
    mkdir($this->collection, 0700);
    file_put_contents($this->collection.'/report.pdf', '%PDF test');
    file_put_contents($this->collection.'/manifest', "report.pdf\0");
    config(['crm.collected_documents_root' => $this->collection, 'crm.collected_documents_manifest' => $this->collection.'/manifest']);
    $this->sourcePayload = json_encode(['name' => 'ARCHIVE-DOCUMENT', 'path' => 'report.pdf', 'is_private' => 1, 'is_delegataire' => 0]);
    DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => 12,
        'dossier_id' => $this->dossier->id, 'payload' => $this->sourcePayload]);
});

afterEach(function () {
    File::deleteDirectory($this->collection);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('shares and revokes imported documents without changing the archive or leaking through direct links', function (string $kind) {
    $this->client->update(['kind' => $kind]);
    $this->external->forceFill(['portal_permissions' => ClientAccess::defaults($kind)])->save();
    $share = route('v2.dossiers.collected-documents.sharing', [$this->dossier, 12]);
    $download = route('client.dossiers.archive', [$this->dossier, 12]);
    $show = route('client.dossiers.show', $this->dossier);
    $this->actingAs($this->external)->get($download)->assertNotFound();
    $this->get($show)->assertOk()->assertDontSee('ARCHIVE-DOCUMENT');
    $this->patch($share, ['client_visible' => true])->assertForbidden();

    $this->actingAs($this->staff)->patch($share, ['client_visible' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('v2.dossiers.show', $this->dossier))->assertOk()->assertSee('Modifier l’accès');
    $this->actingAs($this->external)->get($show)->assertOk()->assertSee('ARCHIVE-DOCUMENT')
        ->assertViewHas('collected', fn ($rows) => $rows->total() === 1);
    $this->get($download)->assertDownload()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(app(CollectedDossierDocuments::class)->forDossiers($this->external, collect([$this->dossier]))[$this->dossier->id])
        ->toHaveCount(1);
    $this->get(route('client.dossiers.archive', [$this->other, 12]))->assertForbidden();

    $this->external->forceFill(['portal_permissions' => []])->save();
    $this->get($download)->assertNotFound();
    $this->external->forceFill(['portal_permissions' => ClientAccess::defaults($kind)])->save();
    $this->actingAs($this->staff)->patch($share, ['client_visible' => false])->assertRedirect();
    $this->actingAs($this->external)->get($download)->assertNotFound();
    $this->get($show)->assertOk()->assertDontSee('ARCHIVE-DOCUMENT')
        ->assertViewHas('collected', fn ($rows) => $rows->total() === 0);
    expect(app(CollectedDossierDocuments::class)->forDossiers($this->external, collect([$this->dossier])))->toBe([])
        ->and(DB::table('legacy_records')->value('payload'))->toBe($this->sourcePayload)
        ->and($this->dossier->activities()->where('kind', 'document_visibility')->count())->toBe(2);
})->with(['client', 'delegataire']);

it('validates document sharing requests and refuses unrelated records and non internal roles', function () {
    $share = route('v2.dossiers.collected-documents.sharing', [$this->dossier, 12]);
    $this->actingAs($this->staff)->patchJson($share, [])->assertUnprocessable();
    $this->patchJson($share, ['client_visible' => 'public'])->assertUnprocessable();
    $this->patch(route('v2.dossiers.collected-documents.sharing', [$this->other, 12]), ['client_visible' => true])->assertNotFound();
    $this->actingAs(User::factory()->create(['role' => 2, 'admin' => false]))->patch($share, ['client_visible' => true])->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->get(route('client.dossiers.archive', [$this->dossier, 12]))->assertRedirect(route('login'));
    expect(DB::table('legacy_records')->value('client_visible_override'))->toBeNull();
});

it('uses the same legacy visibility for listings and direct downloads', function (mixed $private) {
    DB::table('legacy_records')->where('source_id', 12)->update(['payload' => json_encode([
        'name' => 'ARCHIVE-DOCUMENT', 'path' => 'report.pdf', 'is_private' => $private, 'is_delegataire' => 1,
    ])]);
    $this->actingAs($this->external)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('ARCHIVE-DOCUMENT')->assertViewHas('collected', fn ($rows) => $rows->total() === 1);
    $this->get(route('client.dossiers.archive', [$this->dossier, 12]))->assertDownload();
})->with(['integer' => [0], 'string' => ['0'], 'boolean' => [false]]);

it('preserves fail closed legacy rules until an explicit internal sharing decision', function () {
    $access = app(ClientAccess::class);
    foreach ([[], ['is_private' => null], ['is_private' => 'invalid'], ['is_private' => true, 'is_delegataire' => true]] as $payload) {
        expect($access->canReadLegacyDocument($this->external, $payload))->toBeFalse();
    }
    foreach ([0, '0', false] as $public) {
        expect($access->canReadLegacyDocument($this->external, ['is_private' => $public]))->toBeTrue()
            ->and($access->canReadLegacyDocument($this->external, ['is_private' => $public], false))->toBeFalse();
    }
    $this->client->update(['kind' => 'delegataire']);
    $this->external->unsetRelation('externalClient');
    expect($access->canReadLegacyDocument($this->external, ['is_private' => 0, 'is_delegataire' => 0]))->toBeFalse()
        ->and($access->canReadLegacyDocument($this->external, ['is_private' => 0, 'is_delegataire' => 1]))->toBeTrue()
        ->and($access->canReadLegacyDocument($this->external, [], true))->toBeTrue();
});

it('can make an old public comment private without the legacy fallback making it public again', function () {
    $comment = $this->dossier->activities()->create(['kind' => 'legacy_comment', 'body' => 'LEGACY-PUBLIC-NOTE',
        'changed_fields' => ['is_private' => 0, 'source_author_id' => 123]]);
    $show = route('client.dossiers.show', $this->dossier);
    $share = route('v2.dossiers.comments.sharing', [$this->dossier, $comment->id]);
    $this->actingAs($this->external)->get($show)->assertSee('LEGACY-PUBLIC-NOTE');
    $this->patch($share, ['client_visible' => false])->assertForbidden();
    $this->actingAs($this->staff)->patch($share, ['client_visible' => false])->assertRedirect();
    $this->actingAs($this->external)->get($show)->assertDontSee('LEGACY-PUBLIC-NOTE');
    $comment->refresh();
    expect($comment->visibility_reviewed_at)->not->toBeNull()
        ->and($comment->changed_fields)->toBe(['is_private' => 0, 'source_author_id' => 123])
        ->and(app(ClientAccess::class)->commentVisibleToClient($comment))->toBeFalse();
    $this->actingAs($this->staff)->patch($share, ['client_visible' => true])->assertRedirect();
    $this->actingAs($this->external)->get($show)->assertSee('LEGACY-PUBLIC-NOTE')->assertDontSee('Partage client activé');
});

it('keeps comments optional for delegataires and never shares workflow history', function () {
    $this->client->update(['kind' => 'delegataire']);
    $this->external->forceFill(['portal_permissions' => ClientAccess::defaults('delegataire')])->save();
    $shared = $this->dossier->activities()->create(['kind' => 'legacy_comment', 'body' => 'SHARED-WITH-DELEGATAIRE', 'changed_fields' => ['is_private' => 1]]);
    $private = $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'ALWAYS-INTERNAL']);
    $history = $this->dossier->activities()->create(['kind' => 'legacy_history', 'body' => 'AUDIT-INTERNAL']);
    $this->actingAs($this->staff)->patch(route('v2.dossiers.comments.sharing', [$this->dossier, $shared->id]), ['client_visible' => true])->assertRedirect();
    $this->patch(route('v2.dossiers.comments.sharing', [$this->dossier, $history->id]), ['client_visible' => true])->assertNotFound();
    $this->patch(route('v2.dossiers.comments.sharing', [$this->other, $private->id]), ['client_visible' => true])->assertNotFound();
    $this->actingAs($this->external)->get(route('client.dossiers.show', $this->dossier))->assertDontSee('Échanges avec notre équipe')->assertDontSee('SHARED-WITH-DELEGATAIRE');
    $this->external->forceFill(['portal_permissions' => [...ClientAccess::defaults('delegataire'), 'comments' => true]])->save();
    $this->get(route('client.dossiers.show', $this->dossier))->assertSee('SHARED-WITH-DELEGATAIRE')->assertDontSee('ALWAYS-INTERNAL')->assertDontSee('AUDIT-INTERNAL');
    $this->post(route('client.dossiers.comments', $this->dossier), ['body' => 'Not permitted'])->assertForbidden();
});

it('only shares explicitly public lot attachments linked to the assigned dossier', function () {
    $service = Service::create(['name' => 'Test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $operation = $this->dossier->operations()->create(['service_id' => $service->id]);
    $appointment = Appointment::create(['service_id' => $service->id,
        'technician_id' => $this->staff->id, 'created_by' => $this->staff->id, 'customer_first_name' => 'Test', 'customer_last_name' => 'Client',
        'customer_phone' => '0600000000', 'address' => 'Rue de test', 'starts_at' => now(), 'ends_at' => now()->addHour(),
        'duration_minutes' => 60, 'status' => 'scheduled']);
    $appointment->forceFill(['dossier_operation_id' => $operation->id])->save();
    $lot = Lot::create(['name' => 'Lot test', 'delegataire_id' => $this->client->id]);
    $row = $lot->appointments()->create(['appointment_id' => $appointment->id, 'customer_name' => 'Test']);
    $path = 'lot-appointment-documents/'.$row->id.'/report.pdf';
    Storage::disk('local')->put($path, '%PDF test');
    $document = LotAppointmentDocument::create(['lot_appointment_id' => $row->id, 'name' => 'LOT-FILE',
        'original_name' => 'report.pdf', 'path' => $path, 'disk' => 'local', 'size' => 9, 'is_private' => false]);
    $share = route('v2.dossiers.lot-documents.sharing', [$this->dossier, $document->id]);
    $download = route('client.dossiers.lot-documents.download', [$this->dossier, $document->id]);
    $this->actingAs($this->external)->get($download)->assertNotFound();
    $this->get(route('client.dossiers.show', $this->dossier))->assertDontSee('LOT-FILE');
    $this->patch($share, ['client_visible' => true])->assertForbidden();
    $this->actingAs($this->staff)->patch(route('v2.dossiers.lot-documents.sharing', [$this->other, $document->id]), ['client_visible' => true])->assertNotFound();
    $this->patch($share, ['client_visible' => true])->assertRedirect();
    $this->actingAs($this->external)->get(route('client.dossiers.show', $this->dossier))->assertSee('LOT-FILE');
    $this->get($download)->assertDownload();
    $this->get(route('client.dossiers.lot-documents.download', [$this->other, $document->id]))->assertForbidden();
    $this->external->forceFill(['portal_permissions' => []])->save();
    $this->get($download)->assertForbidden();
    $this->external->forceFill(['portal_permissions' => ClientAccess::defaults('client')])->save();
    $document->update(['is_private' => true]);
    $this->get($download)->assertNotFound();
    $this->actingAs($this->staff)->patch($share, ['client_visible' => false])->assertRedirect();
    expect($document->fresh()->client_visible)->toBeFalse()->and($document->fresh()->is_private)->toBeTrue();
    $this->actingAs($this->external)->get($download)->assertNotFound();
    $this->get(route('client.dossiers.show', $this->dossier))->assertDontSee('LOT-FILE');
});
