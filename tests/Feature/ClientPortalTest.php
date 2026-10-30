<?php

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\LotImportPreview;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\LegacyClientReconciliation;
use App\Services\Crm\LotClientAssignment;
use App\Services\Crm\NativeAppointmentPlacement;
use App\Services\LotImportConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
    $this->client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'A',
        'kind' => 'client', 'name' => 'Organisation Alpha', 'is_active' => true]);
    $this->other = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'B',
        'kind' => 'delegataire', 'name' => 'Organisation Bravo', 'is_active' => true]);
    $this->external = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->client->id, 'portal_enabled' => true,
        'portal_permissions' => ClientAccess::defaults('client'), 'must_change_password' => false]);
    $this->dossier = Dossier::create(['reference' => 'ALPHA-DOSSIER', 'company_name' => 'Beneficiaire Alpha',
        'address' => 'Rue Alpha', 'city' => 'Paris', 'delegataire_id' => $this->client->id]);
    $this->dossier->operations()->create()->forceFill(['legacy_status' => 'NON CONFORMITES'])->save();
    $this->privateDossier = Dossier::create(['reference' => 'BRAVO-SECRET', 'company_name' => 'Beneficiaire Bravo',
        'address' => 'Rue Bravo', 'city' => 'Paris', 'delegataire_id' => $this->other->id]);
    $this->lot = Lot::create(['name' => 'Lot Alpha', 'type' => Lot::TYPE_FULL_CONTACT_CONTROL,
        'delegataire_id' => $this->client->id]);
    $this->privateLot = Lot::create(['name' => 'Lot Bravo', 'delegataire_id' => $this->other->id]);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('limits dossier and lot lists, detail links and counts to the assigned client', function () {
    $this->lot->appointments()->create(['customer_name' => 'Test beneficiaire',
        'comment' => 'INTERNAL LOT NOTE', 'contact_satisfaction' => true, 'processing_mode' => 'contact']);
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertOk()
        ->assertSee('Beneficiaire Alpha')->assertSee(route('client.dossiers.show', $this->dossier), false)
        ->assertDontSee('Beneficiaire Bravo')->assertDontSee(route('client.dossiers.show', $this->privateDossier), false)
        ->assertDontSee('ALPHA-DOSSIER')->assertDontSee('Gestion des données');
    $this->get(route('client.dossiers', ['q' => 'BRAVO']))->assertOk()->assertDontSee('Beneficiaire Bravo');
    $this->get(route('client.dossiers.show', $this->dossier))->assertOk()->assertSee('Beneficiaire Alpha');
    $this->get(route('client.dossiers.show', $this->privateDossier))->assertForbidden();
    $this->get(route('client.lots'))->assertOk()->assertSee('Lot Alpha')->assertDontSee('Lot Bravo');
    $this->get(route('client.lots.show', $this->lot))->assertOk()->assertDontSee('INTERNAL LOT NOTE');
    $this->get(route('client.lots.show', $this->privateLot))->assertNotFound();
    $this->get(route('dashboard'))->assertRedirect(route('client.dossiers'));
});

it('blocks internal pages APIs mutations and broadcasting regardless of guessed URLs', function (string $url) {
    $this->actingAs($this->external)->get($url)->assertForbidden();
})->with(['/manager/users', '/admin/users', '/manager/delegataires', '/manager/clients/create',
    '/manager/lots', '/planner/book', '/planner/tracking', '/tech/planning', '/v2/dossiers', '/admin/settings']);

it('does not allow external accounts to change assignments or escalate roles', function () {
    $this->actingAs($this->external)->put(route('v2.dossiers.update', $this->dossier), [
        'delegataire_id' => $this->other->id, 'version' => 1,
    ])->assertForbidden();
    $this->put(route('manager.users.update', $this->external), ['admin' => 1, 'role' => 0])->assertForbidden();
    $this->post('/broadcasting/auth', ['channel_name' => 'private-App.Models.User.'.$this->external->id, 'socket_id' => '1.2'])->assertForbidden();
    expect($this->dossier->fresh()->delegataire_id)->toBe($this->client->id);
});

it('hides internal comments and exposes only shared or legacy public client comments', function () {
    $this->dossier->activities()->create(['kind' => 'comment', 'body' => 'INTERNAL-SECRET']);
    $this->dossier->activities()->create(['kind' => 'legacy_comment', 'body' => 'LEGACY-PRIVATE', 'changed_fields' => ['is_private' => 1]]);
    $this->dossier->activities()->create(['kind' => 'legacy_comment', 'body' => 'LEGACY-PUBLIC', 'changed_fields' => ['is_private' => 0]]);
    $this->dossier->activities()->create(['kind' => 'legacy_history', 'body' => 'HISTORY-SECRET']);
    $shared = $this->dossier->activities()->create(['kind' => 'comment', 'body' => '<b>SHARED-COMMENT</b>', 'client_visible' => true]);
    $this->actingAs($this->external)->get(route('client.dossiers.show', $this->dossier))->assertOk()
        ->assertSee('SHARED-COMMENT')->assertSee('LEGACY-PUBLIC')->assertDontSee('LEGACY-PRIVATE')
        ->assertDontSee('INTERNAL-SECRET')->assertDontSee('HISTORY-SECRET')->assertSee('<b>SHARED-COMMENT</b>', false);
    $this->post(route('client.dossiers.comments', $this->dossier), ['body' => 'Client reply', 'client_visible' => false])->assertRedirect();
    expect($this->dossier->comments()->where('body', 'Client reply')->first()->client_visible)->toBeTrue();
    $this->post(route('client.dossiers.comments', $this->privateDossier), ['body' => 'NOT ALLOWED'])->assertForbidden();
    $this->actingAs($this->staff)->patch(route('v2.dossiers.comments.sharing', [$this->dossier, $shared->id]), ['client_visible' => false])->assertRedirect();
    $this->actingAs($this->external)->get(route('client.dossiers.show', $this->dossier))->assertDontSee('SHARED-COMMENT');
});

it('checks document visibility and ownership on direct downloads as well as lists', function () {
    foreach (['test', 'shared', 'other'] as $name) {
        Storage::disk('local')->put('crm/dossiers/'.$name.'.pdf', '%PDF test');
    }
    $private = $this->dossier->documents()->create(['name' => 'INTERNAL-FILE', 'path' => 'crm/dossiers/test.pdf', 'mime_type' => 'application/pdf', 'size' => 9]);
    $shared = $this->dossier->documents()->create(['name' => 'SHARED-FILE', 'path' => 'crm/dossiers/shared.pdf', 'mime_type' => 'application/pdf', 'size' => 9, 'client_visible' => true]);
    $other = $this->privateDossier->documents()->create(['name' => 'OTHER-FILE', 'path' => 'crm/dossiers/other.pdf', 'mime_type' => 'application/pdf', 'size' => 9, 'client_visible' => true]);
    $this->actingAs($this->external)->get(route('client.dossiers.show', $this->dossier))->assertOk()->assertSee('SHARED-FILE')->assertDontSee('INTERNAL-FILE');
    $this->get(route('client.documents.download', $private))->assertNotFound();
    $this->get(route('client.documents.download', $other))->assertForbidden();
    $this->get(route('client.documents.download', $shared))->assertDownload();
    $this->actingAs($this->staff)->patch(route('v2.documents.sharing', $shared), ['client_visible' => false])->assertRedirect();
    $this->actingAs($this->external)->get(route('client.documents.download', $shared))->assertNotFound();
    $this->post(route('client.dossiers.upload', $this->dossier), ['name' => 'Photo client', 'document' => UploadedFile::fake()->image('photo.jpg')])->assertRedirect();
    expect($this->dossier->documents()->where('name', 'Photo client')->first()->client_visible)->toBeTrue();
});

it('applies delegataire defaults and private priority to collected documents', function () {
    $root = sys_get_temp_dir().'/client-documents-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    try {
        file_put_contents($root.'/test.pdf', '%PDF fake');
        file_put_contents($root.'/manifest', "test.pdf\0");
        config(['crm.collected_documents_root' => $root, 'crm.collected_documents_manifest' => $root.'/manifest']);
        $this->client->update(['kind' => 'delegataire']);
        $this->external->forceFill(['portal_permissions' => ClientAccess::defaults('delegataire')])->save();
        foreach ([[1, 0, 1], [2, 1, 1], [3, 0, 0]] as [$id, $private, $delegate]) {
            DB::table('legacy_records')->insert(['source_table' => 'dossier_document', 'source_id' => $id, 'dossier_id' => $this->dossier->id,
                'payload' => json_encode(['name' => 'Archive-'.$id, 'path' => 'test.pdf', 'is_private' => $private, 'is_delegataire' => $delegate])]);
        }
        $this->actingAs($this->external)->get(route('client.dossiers.show', $this->dossier))->assertOk()
            ->assertSee('Archive-1')->assertDontSee('Archive-2')->assertDontSee('Archive-3')->assertSee('Échanges avec notre équipe');
        $this->get(route('client.dossiers.archive', [$this->dossier, 1]))->assertDownload();
        $this->get(route('client.dossiers.archive', [$this->dossier, 2]))->assertNotFound();
        $this->get(route('client.dossiers.archive', [$this->dossier, 3]))->assertNotFound();
        $this->get(route('client.dossiers.archive', [$this->privateDossier, 1]))->assertForbidden();
        $this->post(route('client.dossiers.comments', $this->dossier), ['body' => 'Not allowed'])->assertForbidden();
        $this->post(route('client.dossiers.upload', $this->dossier), ['name' => 'Correction', 'document' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
    } finally {
        File::deleteDirectory($root);
    }
});

it('honors revoked permissions and disabled organisations for existing sessions', function () {
    $this->external->forceFill(['portal_permissions' => []])->save();
    $this->actingAs($this->external)->get(route('client.lots'))->assertForbidden();
    $this->get(route('client.dossiers.show', $this->dossier))->assertOk()->assertDontSee('Documents partagés')->assertDontSee('Échanges avec notre équipe');
    $this->client->update(['is_active' => false]);
    $this->external->unsetRelation('externalClient');
    $this->get(route('client.dossiers'))->assertForbidden();
    $this->assertGuest();
});

it('requires password change before granting external data access', function () {
    $this->external->forceFill(['must_change_password' => true])->save();
    $this->actingAs($this->external)->get(route('client.dossiers'))->assertRedirect(route('profile'));
    $this->get(route('profile'))->assertOk()->assertSee('Changement de mot de passe requis');
    $this->post(route('account.first-password.update'), ['password' => 'New-Password-123', 'password_confirmation' => 'New-Password-123'])->assertRedirect(route('client.dossiers'));
    $this->get(route('client.dossiers'))->assertOk();
});

it('redirects external logins to their portal and refuses disabled or V1 access', function () {
    $this->external->forceFill(['password' => 'Client-Test-123'])->save();
    $this->withSession(['url.intended' => route('manager.users')])->post('/login', [
        'email' => $this->external->email, 'password' => 'Client-Test-123',
    ])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->external);
    $this->post('/logout')->assertRedirect(route('login'));
    $this->external->forceFill(['portal_enabled' => false])->save();
    $credentials = ['email' => $this->external->email, 'password' => 'Client-Test-123'];
    $this->post('/login', $credentials)->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->external->forceFill(['portal_enabled' => true])->save();
    config(['crm.v2_enabled' => false]);
    $this->post('/login', $credentials)->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('refuses mobile tokens and inconsistent read write permissions for an external account', function () {
    $this->external->forceFill(['password' => 'Client-Test-123', 'portal_permissions' => [
        'comments' => false, 'add_comments' => true, 'documents' => false, 'upload_documents' => true,
    ]])->save();
    $this->postJson(route('api.mobile.login'), ['email' => $this->external->email, 'password' => 'Client-Test-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertDatabaseCount('mobile_access_tokens', 0);
    $this->actingAs($this->external)->post(route('client.dossiers.comments', $this->dossier), ['body' => 'Denied'])->assertForbidden();
    $this->post(route('client.dossiers.upload', $this->dossier), ['name' => 'Denied', 'document' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
});

it('scopes unknown states and escapes literal search wildcards', function () {
    $this->dossier->update(['reference' => 'ALPHA-100%']);
    $this->actingAs($this->external)->get(route('client.dossiers', ['status' => 'unknown']))->assertForbidden();
    $this->get(route('client.dossiers', ['q' => '100%']))->assertOk()->assertSee('Beneficiaire Alpha');
    $this->get(route('client.dossiers', ['q' => '_']))->assertOk()->assertDontSee('Beneficiaire Alpha');
});

it('keeps an existing inactive client on a lot without allowing assignment to another inactive client', function () {
    $service = Service::create(['name' => 'Test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->lot->update(['service_id' => $service->id, 'delegataire' => $this->client->name]);
    $this->client->update(['is_active' => false]);
    $this->other->update(['is_active' => false]);
    $payload = ['name' => 'Lot mis à jour', 'service_id' => $service->id,
        'type' => $this->lot->type, 'delegataire_id' => $this->client->id];
    $this->actingAs($this->staff)->get(route('manager.lots.show', $this->lot))->assertOk()
        ->assertSee('Organisation Alpha · Désactivé');
    $this->patch(route('manager.lots.update', $this->lot), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect($this->lot->fresh()->delegataire_id)->toBe($this->client->id);
    $this->patch(route('manager.lots.update', $this->lot), [...$payload, 'delegataire_id' => $this->other->id])
        ->assertRedirect()->assertSessionHasErrors('delegataire_id');
});

it('keeps external accounts separate from internal user CRUD and rejects account hijacking', function () {
    $this->actingAs($this->staff)->get(route('manager.users'))->assertOk()->assertDontSee($this->external->email);
    $this->put(route('manager.users.update', $this->external), [])->assertNotFound();
    $admin = User::factory()->create(['admin' => true]);
    $this->actingAs($admin)->get(route('admin.users'))->assertOk()->assertDontSee($this->external->email);
    $this->put(route('admin.users.update', $this->external), [])->assertNotFound();
    $this->delete(route('admin.users.destroy', $this->external))->assertNotFound();
    $payload = ['first_name' => 'Marie', 'last_name' => 'Client', 'email' => 'external@test.example',
        'password' => 'Temporary-123', 'password_confirmation' => 'Temporary-123', 'portal_enabled' => true,
        'permissions' => ClientAccess::defaults('client'), 'admin' => true, 'role' => 0];
    $this->actingAs($this->staff)->post(route('manager.clients.accounts.store', $this->client), $payload)->assertRedirect();
    $account = User::where('email', 'external@test.example')->firstOrFail();
    expect($account->role)->toBe(User::ROLE_EXTERNAL)->and($account->admin)->toBeFalse()
        ->and($account->external_client_id)->toBe($this->client->id)->and($account->must_change_password)->toBeTrue();
    $this->put(route('manager.clients.accounts.update', [$this->other, $account]), $payload)->assertNotFound();
    $this->put(route('manager.clients.accounts.update', [$this->client, $this->staff]), [...$payload, 'email' => $this->staff->email])->assertNotFound();
});

it('renders management forms and exposes both client kinds in dossier and lot selectors', function () {
    $this->actingAs($this->staff)->get(route('manager.delegataires'))->assertOk()->assertSee('Organisation Alpha')->assertSee('Organisation Bravo');
    $this->get(route('manager.clients.create'))->assertOk();
    $this->get(route('manager.clients.edit', $this->client))->assertOk()->assertSee($this->external->email);
    $this->get(route('v2.dossiers.create'))->assertOk()->assertSee('Organisation Alpha')->assertSee('Organisation Bravo');
    $this->get(route('manager.lots'))->assertOk()->assertSee('Organisation Alpha')->assertSee('Organisation Bravo');
    $this->post(route('manager.clients.store'), ['name' => 'Nouveau', 'kind' => 'client', 'is_active' => true])->assertRedirect();
    expect(ExternalDelegataire::where('name', 'Nouveau')->first()->source)->toBe('native');
});

it('preserves the selected client through preview confirmation and physical booking', function () {
    $service = Service::create(['name' => 'Test Prestation', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $preview = LotImportPreview::create([
        'uuid' => (string) Str::uuid(), 'status' => 'completed',
        'type' => Lot::TYPE_FULL_CONTROL, 'service_id' => $service->id,
        'delegataire' => $this->client->name, 'delegataire_id' => $this->client->id,
        'original_filename' => 'test.xlsx', 'created_by' => $this->staff->id,
        'original_file_disk' => 'local', 'original_file_path' => 'lot-import-previews/test.xlsx',
        'payload' => ['appointments' => [['row_number' => 2, 'customer_name' => 'Client Test',
            'customer_first_name' => 'Client', 'customer_last_name' => 'Test', 'address' => 'Rue Test',
            'city' => 'Paris', 'postal_code' => '75001', 'warnings' => []]]],
    ]);
    $lot = app(LotImportConfirmationService::class)->confirm($preview->refresh(), [2]);
    expect($lot->delegataire_id)->toBe($this->client->id);
    $row = $lot->appointments()->firstOrFail();
    $appointment = Appointment::create(['technician_id' => User::factory()->create(['role' => 2])->id,
        'service_id' => $service->id, 'created_by' => $this->staff->id, 'customer_first_name' => 'Client',
        'customer_last_name' => 'Test', 'customer_phone' => '0600000000', 'address' => 'Rue Test, 75001 Paris',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'duration_minutes' => 60,
        'status' => 'scheduled']);
    DB::transaction(fn () => app(NativeAppointmentPlacement::class)->place($this->staff, $appointment, ['lot_appointment_id' => $row->id]));
    $dossier = Dossier::where('source', 'lot')->where('source_reference', (string) $row->id)->firstOrFail();
    expect($dossier->client_id)->toBe($this->client->id)->and($dossier->delegataire_id)->toBeNull();
    $this->actingAs($this->external)->get(route('client.dossiers.show', $dossier))->assertOk()->assertSee('Planifié');
    app(LotClientAssignment::class)->update($this->staff, $lot, [
        'delegataire_id' => $this->other->id, 'delegataire' => $this->other->name,
    ]);
    expect($dossier->fresh()->delegataire_id)->toBe($this->other->id)->and($dossier->fresh()->client_id)->toBeNull();
    $this->get(route('client.dossiers.show', $dossier))->assertForbidden();
    $this->get(route('client.lots.show', $lot))->assertNotFound();
});

it('keeps legacy reconciliation idempotent and never enables or creates login accounts', function () {
    foreach ([[21, 5, 'Legacy Client'], [22, 7, 'Legacy Delegataire'], [23, 1, 'Internal Admin'], [24, 6, 'Internal Auditor']] as [$id, $type, $name]) {
        DB::table('legacy_records')->insert(['source_table' => 'users', 'source_id' => $id,
            'payload' => json_encode(['id' => $id, 'type' => $type, 'name' => $name, 'actif' => 1])]);
    }
    foreach ([[201, 21, null], [202, 21, 22], [203, 23, null], [204, 24, null]] as [$id, $client, $delegate]) {
        Dossier::create(['reference' => 'LEGACY-'.$id, 'source' => 'coffrac', 'source_reference' => (string) $id, 'address' => 'Rue', 'city' => 'Paris']);
        DB::table('legacy_records')->insert(['source_table' => 'dossiers', 'source_id' => $id,
            'payload' => json_encode(['client_id' => $client, 'delegataire_id' => $delegate])]);
    }
    $before = User::count();
    $service = app(LegacyClientReconciliation::class);
    $report = $service->run();
    expect($report['organisations_a_creer'])->toBe(2)->and($report['dossiers_a_rattacher'])->toBe(2)
        ->and($report['double_affectation_historique'])->toBe(1)->and(ExternalDelegataire::count())->toBe(2);
    $service->run(true);
    expect(Dossier::where('reference', 'LEGACY-201')->first()->client->kind)->toBe('client')
        ->and(Dossier::where('reference', 'LEGACY-202')->first()->client->kind)->toBe('client')
        ->and(Dossier::where('reference', 'LEGACY-202')->first()->delegataire->kind)->toBe('delegataire')
        ->and(Dossier::where('reference', 'LEGACY-203')->first()->delegataire_id)->toBeNull()
        ->and(Dossier::where('reference', 'LEGACY-204')->first()->client_id)->toBeNull()
        ->and(ExternalDelegataire::where('source', 'coffrac')->where('external_id', '24')->exists())->toBeFalse()
        ->and(User::count())->toBe($before);
    expect($service->run(true)['dossiers_a_rattacher'])->toBe(0)->and(ExternalDelegataire::count())->toBe(4);
});

it('persists a native client selected in the import modal without changing source filtering in V1', function () {
    $service = Service::create(['name' => 'Import Test', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->actingAs($this->staff)->postJson(route('manager.lots.imports.store'), [
        'type' => Lot::TYPE_FULL_CONTROL, 'service_id' => $service->id, 'delegataire_id' => $this->client->id,
        'received_at' => '2026-10-15', 'file' => UploadedFile::fake()->createWithContent('lot.csv', "REFERENCE;BENEFICIAIRE\n1;Exemple\n"),
    ])->assertSuccessful();
    expect(LotImportPreview::firstOrFail()->delegataire_id)->toBe($this->client->id);
});
