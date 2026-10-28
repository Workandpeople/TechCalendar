<?php

use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierDocument;
use App\Models\DossierOperation;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\DossierRegistry;
use App\Services\DocumentUpload;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('delivers imported rich comments as escaped text for the shared safe renderer without rewriting them', function () {
    $dossier = app(DossierRegistry::class)->create($this->manager, $this->data);
    $body = '<p onclick="alert(1)">Note <b>importée</b></p><script>alert(2)</script>';
    $activity = $dossier->activities()->create(['kind' => 'legacy_comment', 'body' => $body]);

    $this->actingAs($this->manager)->get(route('v2.dossiers.show', $dossier))
        ->assertOk()->assertSee('data-comment-body', false)
        ->assertSee('&lt;b&gt;importée&lt;/b&gt;', false)
        ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(2)</script>', false);

    expect($activity->fresh()->body)->toBe($body);
});

beforeEach(function () {
    config(['crm.v2_enabled' => true]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->manager = User::factory()->create(['role' => 0]);
    $this->service = Service::create(['name' => 'BAR EN 101', 'type' => 'COFFRAC', 'average_duration_minutes' => 60]);
    $this->data = [
        'reference' => 'LOT-ALPHA-001', 'company_name' => 'Bénéficiaire exemple',
        'first_name' => 'Marie', 'last_name' => 'DUPONT', 'email' => 'contact@example.test',
        'phone' => '0600000000', 'address' => '10 rue du Site', 'postal_code' => '69001',
        'city' => 'Lyon', 'beneficiary_address' => '2 rue du Siège',
        'beneficiary_postal_code' => '75001', 'beneficiary_city' => 'Paris',
        'installer_name' => 'Installateur exemple', 'installer_siren' => '123456789',
        'service_ids' => [$this->service->id],
    ];
});

it('keeps the V2 inaccessible and out of navigation while disabled', function () {
    config(['crm.v2_enabled' => false]);
    $this->actingAs($this->manager)->get(route('v2.dossiers.index'))->assertNotFound();
    $this->post(route('v2.dossiers.store'), $this->data)->assertNotFound();
    $this->get(route('manager.delegataires'))->assertOk()->assertDontSee('Les dossiers');
    expect(Dossier::count())->toBe(0);
});

it('requires authentication for the registry', function () {
    $this->get(route('v2.dossiers.index'))->assertRedirect(route('login'));
});

it('denies access to unvalidated roles and password changes pending', function (int $role, bool $passwordPending) {
    $user = User::factory()->create(['role' => $role, 'must_change_password' => $passwordPending]);
    $this->actingAs($user)->get(route('v2.dossiers.index'))->assertForbidden();
    $this->post(route('v2.dossiers.store'), $this->data)->assertForbidden();
})->with([[2, false], [0, true], [1, true]]);

it('renders the shared navigation and does not load legacy data as local dossiers', function () {
    $this->actingAs($this->manager)->get(route('v2.dossiers.index'))->assertOk()
        ->assertSee('Les dossiers')->assertSee('V2 en préparation.')
        ->assertSee('Gestion des données')->assertSee('Les lots')->assertSee('Les RDV');
    $this->get(route('v2.dossiers.create'))->assertOk()->assertSee('Nouveau dossier')->assertSee('BAR EN 101');
});

it('creates one dossier with multiple operations and no external side effects', function () {
    $second = Service::create(['name' => 'BAR EN 102', 'type' => 'COFFRAC', 'average_duration_minutes' => 90]);
    $delegataire = ExternalDelegataire::create(['source' => 'coffrac', 'external_id' => '42', 'name' => 'Délégataire test', 'is_active' => true]);
    $data = [...$this->data, 'service_ids' => [$this->service->id, $second->id], 'delegataire_id' => $delegataire->id];
    $this->actingAs($this->manager)->post(route('v2.dossiers.store'), [
        ...$data, 'created_by' => 999, 'source' => 'coffrac', 'source_reference' => 'fake',
    ])->assertRedirect(route('v2.dossiers.show', 1));
    $dossier = Dossier::firstOrFail();
    expect($dossier->created_by)->toBe($this->manager->id)
        ->and($dossier->source)->toBe('native')->and($dossier->source_reference)->toBeNull()
        ->and($dossier->company_name)->toBe('Bénéficiaire exemple')
        ->and($dossier->beneficiary_city)->toBe('Paris')
        ->and($dossier->city)->toBe('Lyon')
        ->and($dossier->delegataire_id)->toBe($delegataire->id)
        ->and($dossier->operations()->count())->toBe(2)
        ->and($dossier->activities()->first()->kind)->toBe('created');
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
    $this->get(route('v2.dossiers.show', $dossier))->assertOk()->assertSee('Délégataire test')
        ->assertSee('2 rue du Siège')->assertSee('10 rue du Site')->assertSee('BAR EN 102');
});

it('allows an admin technician to manage the registry', function () {
    $admin = User::factory()->create(['role' => 2, 'admin' => true]);
    $this->actingAs($admin)->post(route('v2.dossiers.store'), $this->data)->assertRedirect();
    expect(Dossier::count())->toBe(1);
});

it('lets planners use the same registry without creating another copy', function () {
    Storage::fake('local');
    $dossier = app(DossierRegistry::class)->create($this->manager, $this->data);
    $planner = User::factory()->create(['role' => 1, 'admin' => false]);
    $this->actingAs($planner)->get(route('v2.dossiers.index'))->assertOk()
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $dossier->id);
    $this->get(route('v2.dossiers.show', $dossier))->assertOk();
    $this->get(route('v2.dossiers.edit', $dossier))->assertOk();
    $this->put(route('v2.dossiers.update', $dossier), [
        ...collect($this->data)->except('service_ids')->all(), 'version' => 1, 'city' => 'Villeurbanne',
    ])->assertSessionHasNoErrors()->assertRedirect(route('v2.dossiers.show', $dossier));
    $this->post(route('v2.dossiers.comments.store', $dossier), ['body' => 'Commentaire planning'])->assertSessionHasNoErrors();
    $this->post(route('v2.dossiers.documents.store', $dossier), [
        'name' => 'Photo site', 'document' => UploadedFile::fake()->image('photo.png'),
    ])->assertSessionHasNoErrors();
    $document = $dossier->documents()->firstOrFail();
    $this->get(route('v2.documents.download', $document))->assertOk();
    expect(Dossier::count())->toBe(1)->and($dossier->fresh()->city)->toBe('Villeurbanne');
    $this->get(route('v2.dossiers.create'))->assertOk();
    $this->post(route('v2.dossiers.store'), [...$this->data, 'reference' => 'PLANNING-001'])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(Dossier::count())->toBe(2)
        ->and(Dossier::where('reference', 'PLANNING-001')->firstOrFail()->created_by)->toBe($planner->id);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not create a duplicate when a form is submitted again', function () {
    $this->actingAs($this->manager)->post(route('v2.dossiers.store'), $this->data)->assertRedirect();
    $this->post(route('v2.dossiers.store'), $this->data)->assertSessionHasErrors('reference');
    expect(Dossier::count())->toBe(1)->and(DossierOperation::count())->toBe(1)->and(DossierActivity::count())->toBe(1);
});

it('protects duplicate references at the service and database boundary', function () {
    $registry = app(DossierRegistry::class);
    $registry->create($this->manager, $this->data);
    expect(fn () => $registry->create($this->manager, $this->data))
        ->toThrow(ValidationException::class);
    expect(Dossier::count())->toBe(1)->and(DossierOperation::count())->toBe(1);
});

it('prevents deleting a service used by a dossier even when V2 is disabled', function (bool $enabled) {
    app(DossierRegistry::class)->create($this->manager, $this->data);
    config(['crm.v2_enabled' => $enabled]);

    $this->actingAs($this->manager)->delete(route('manager.services.destroy', $this->service))
        ->assertSessionHasErrors('service');

    expect($this->service->fresh())->not->toBeNull()
        ->and(DossierOperation::count())->toBe(1);
})->with([true, false]);

it('still allows deleting a service not used by a dossier', function () {
    $this->actingAs($this->manager)->delete(route('manager.services.destroy', $this->service))
        ->assertRedirect(route('manager.services'))->assertSessionHasNoErrors();

    expect($this->service->fresh())->toBeNull();
});

it('validates identities services sirens and active delegataires', function () {
    $this->actingAs($this->manager)->post(route('v2.dossiers.store'), [
        ...$this->data, 'company_name' => null, 'last_name' => null,
        'installer_siren' => 'wrong', 'service_ids' => [$this->service->id, $this->service->id],
    ])->assertSessionHasErrors(['company_name', 'last_name', 'installer_siren', 'service_ids.0']);
    $inactive = ExternalDelegataire::create(['source' => 'coffrac', 'external_id' => '43', 'name' => 'Inactif', 'is_active' => false]);
    $this->post(route('v2.dossiers.store'), [...$this->data, 'delegataire_id' => $inactive->id])->assertSessionHasErrors('delegataire_id');
    $this->post(route('v2.dossiers.store'), [...$this->data, 'service_ids' => [9999]])->assertSessionHasErrors('service_ids.0');
    expect(Dossier::count())->toBe(0);
});

it('searches literal references and filters by an operation without duplicate result rows', function () {
    $registry = app(DossierRegistry::class);
    $first = $registry->create($this->manager, [...$this->data, 'reference' => 'REF%_1']);
    $registry->create($this->manager, [...$this->data, 'reference' => 'REFXX1']);
    $first->operations()->create(['service_id' => $this->service->id]);
    $this->actingAs($this->manager)->get(route('v2.dossiers.index', ['q' => '%_', 'service_id' => $this->service->id]))
        ->assertOk()->assertSee('REF%_1')->assertDontSee('REFXX1')
        ->assertViewHas('dossiers', fn ($rows) => $rows->total() === 1);
    $this->get(route('v2.dossiers.index', ['per_page' => 99999]))->assertSessionHasErrors('per_page');
});

it('updates the dossier atomically and rejects a stale form without losing data', function () {
    $dossier = app(DossierRegistry::class)->create($this->manager, $this->data);
    $update = collect($this->data)->except('service_ids')->all();
    $this->actingAs($this->manager)->get(route('v2.dossiers.edit', $dossier))->assertOk();
    $this->put(route('v2.dossiers.update', $dossier), [...$update, 'version' => 1, 'city' => 'Villeurbanne'])->assertRedirect();
    expect($dossier->fresh()->city)->toBe('Villeurbanne')->and($dossier->fresh()->version)->toBe(2);
    $this->put(route('v2.dossiers.update', $dossier), [...$update, 'version' => 1])->assertSessionHasErrors('version');
    expect($dossier->fresh()->city)->toBe('Villeurbanne')->and($dossier->activities()->count())->toBe(2);
    expect($dossier->activities()->latest('id')->first()->changed_fields)->toBe(['city']);
});

it('retains an existing inactive delegataire without allowing another inactive choice', function () {
    $old = ExternalDelegataire::create(['source' => 'coffrac', 'external_id' => '43', 'name' => 'Ancien', 'is_active' => true]);
    $other = ExternalDelegataire::create(['source' => 'coffrac', 'external_id' => '44', 'name' => 'Autre', 'is_active' => false]);
    $dossier = app(DossierRegistry::class)->create($this->manager, [...$this->data, 'delegataire_id' => $old->id]);
    $old->update(['is_active' => false]);
    $update = collect($this->data)->except('service_ids')->all();
    $this->actingAs($this->manager)->put(route('v2.dossiers.update', $dossier), [...$update, 'version' => 1, 'delegataire_id' => $old->id])->assertSessionHasNoErrors();
    $this->put(route('v2.dossiers.update', $dossier), [...$update, 'version' => 1, 'delegataire_id' => $other->id])->assertSessionHasErrors('delegataire_id');
});

it('escapes comments and document names and forbids anonymous file access', function () {
    Storage::fake('local');
    $dossier = app(DossierRegistry::class)->create($this->manager, $this->data);
    $this->actingAs($this->manager)->post(route('v2.dossiers.comments.store', $dossier), ['body' => '<script>alert(1)</script>'])->assertRedirect();
    $this->post(route('v2.dossiers.documents.store', $dossier), [
        'name' => '<b>Photo site</b>', 'document' => UploadedFile::fake()->image('photo.png'),
    ])->assertRedirect();
    $document = DossierDocument::firstOrFail();
    Storage::disk('local')->assertExists($document->path);
    expect($document->path)->toStartWith('crm/dossiers/'.$dossier->id.'/');
    $this->get(route('v2.dossiers.show', $dossier))->assertOk()
        ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;b&gt;Photo site&lt;/b&gt;', false);
    $this->get(route('v2.documents.download', $document))->assertOk()->assertDownload()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $tech = User::factory()->create(['role' => 2]);
    $this->actingAs($tech)->get(route('v2.documents.download', $document))->assertForbidden();
    $this->get(route('v2.dossiers.show', $dossier))->assertForbidden();
    $this->post(route('v2.dossiers.comments.store', $dossier), ['body' => 'Interdit'])->assertForbidden();
    auth()->logout();
    $this->get(route('v2.documents.download', $document))->assertRedirect(route('login'));
});

it('rejects executable html oversized and missing files without storing anything', function () {
    Storage::fake('local');
    $dossier = app(DossierRegistry::class)->create($this->manager, $this->data);
    $this->actingAs($this->manager);
    foreach ([
        UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1;'),
        UploadedFile::fake()->createWithContent('evil.html', '<script>alert(1)</script>'),
        UploadedFile::fake()->create('large.pdf', DocumentUpload::MAX_KILOBYTES + 1, 'application/pdf'),
    ] as $file) {
        $this->post(route('v2.dossiers.documents.store', $dossier), ['name' => 'Test', 'document' => $file])->assertSessionHasErrors('document');
    }
    expect(DossierDocument::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('cleans up a stored file when persisting its metadata fails', function () {
    Storage::fake('local');
    $dossier = app(DossierRegistry::class)->create($this->manager, $this->data);
    // An absent author simulates a database failure after the file was stored.
    $this->manager->id = 99999;
    expect(fn () => app(DossierRegistry::class)->addDocument($this->manager, $dossier, UploadedFile::fake()->image('site.png'), 'Photo'))
        ->toThrow(QueryException::class);
    expect(Storage::disk('local')->allFiles())->toBe([])->and(DossierDocument::count())->toBe(0);
});

it('paginates documents comments and history independently', function () {
    $dossier = app(DossierRegistry::class)->create($this->manager, $this->data);
    foreach (range(1, 28) as $i) {
        $dossier->activities()->create(['kind' => 'comment', 'body' => 'Note '.$i]);
        $dossier->activities()->create(['kind' => 'updated', 'body' => 'Modification '.$i]);
        $dossier->documents()->create(['name' => 'Pièce '.$i, 'path' => 'crm/test/'.$i.'.pdf', 'mime_type' => 'application/pdf', 'size' => 123]);
    }
    $this->actingAs($this->manager)->get(route('v2.dossiers.show', ['dossier' => $dossier, 'documents_page' => 2]))
        ->assertOk()->assertViewHas('documents', fn ($rows) => $rows->count() === 3)
        ->assertViewHas('comments', fn ($rows) => $rows->currentPage() === 1 && $rows->count() === 25 && $rows->total() === 28)
        ->assertViewHas('activities', fn ($rows) => $rows->currentPage() === 1 && $rows->count() === 25);

    $this->get(route('v2.dossiers.show', [$dossier, 'comments_page' => 2, 'history_page' => 2]))->assertOk()
        ->assertViewHas('documents', fn ($rows) => $rows->currentPage() === 1 && $rows->count() === 25)
        ->assertViewHas('comments', fn ($rows) => $rows->currentPage() === 2 && $rows->count() === 3)
        ->assertViewHas('activities', fn ($rows) => $rows->currentPage() === 2 && $rows->count() === 4);
});

it('renders a compact searchable multiple prestation picker with the original native payload', function () {
    $response = $this->actingAs($this->manager)->get(route('v2.dossiers.create'))->assertOk();
    $response->assertSee('id="dossier-service-ids"', false)->assertSee('name="service_ids[]"', false)
        ->assertSee('data-multi-select', false)->assertSee('Rechercher par nom ou code…')
        ->assertSee('BAR EN 101 · COFRAC')->assertDontSee('type="checkbox" name="service_ids[]"', false);
    $this->withSession(['_old_input' => ['service_ids' => [(string) $this->service->id]]])
        ->get(route('v2.dossiers.create'))->assertSee('value="'.$this->service->id.'" selected', false);
});
