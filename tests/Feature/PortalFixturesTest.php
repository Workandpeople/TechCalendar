<?php

use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\ExternalDelegataire;
use App\Models\Lot;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Simulation\PortalFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'simulation.enabled' => true, 'services.smsfactor.mode' => 'simulate']);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Notification::fake();
    Storage::fake('local');
    $this->service = Service::create(['type' => 'COFFRAC', 'name' => 'Test inspection', 'average_duration_minutes' => 60]);
    $this->fixtures = app(PortalFixtures::class);
    $this->passwords = ['client' => 'Unique-Client-1234', 'delegataire' => 'Unique-Delegate-5678'];
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

it('previews the fixtures without creating accounts rows or files', function () {
    $this->artisan('demo:seed-portals')->expectsOutputToContain('Apercu uniquement')->assertSuccessful();
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('external_delegataires', 0);
    $this->assertDatabaseCount('dossiers', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('creates only fictitious accounts and data and leaves imported records unchanged', function () {
    $organisation = ExternalDelegataire::create(['source' => 'coffrac', 'external_id' => '999', 'name' => 'Existing organisation', 'kind' => 'client']);
    $existing = User::factory()->create(['external_client_id' => $organisation->id, 'portal_enabled' => false, 'role' => 3]);
    $original = $existing->fresh()->getAttributes();
    $result = $this->fixtures->create($this->passwords);
    expect($result['created'])->toBeTrue()->and($existing->fresh()->getAttributes())->toBe($original);
    $this->assertDatabaseCount('dossiers', 6);
    $this->assertDatabaseCount('dossier_operations', 6);
    $this->assertDatabaseCount('dossier_documents', 18);
    $this->assertDatabaseCount('dossier_activities', 18);
    $this->assertDatabaseCount('lots', 2);
    $this->assertDatabaseCount('lot_appointments', 6);
    $this->assertDatabaseCount('appointments', 0);
    foreach (PortalFixtures::ACCOUNTS as $kind => $email) {
        $account = User::where('email', $email)->sole();
        expect($account->role)->toBe(User::ROLE_EXTERNAL)->and($account->admin)->toBeFalse()
            ->and($account->can_manage_billing)->toBeFalse()->and($account->portal_enabled)->toBeTrue()
            ->and($account->notification_mail_enabled)->toBeFalse()->and($account->notification_push_enabled)->toBeFalse()
            ->and($account->portal_permissions)->toBe(ClientAccess::defaults($kind))
            ->and(Hash::check($this->passwords[$kind], $account->password))->toBeTrue()
            ->and($account->externalClient->source)->toBe(PortalFixtures::SOURCE);
    }
    expect(Dossier::whereNotNull('phone')->count())->toBe(0)
        ->and(Dossier::whereNotNull('email')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(18);
    foreach (DossierDocument::all() as $document) {
        expect(Storage::disk('local')->get($document->path))->toStartWith('%PDF-')
            ->and(Storage::disk('local')->size($document->path))->toBe($document->size)
            ->and(Storage::disk('local')->getVisibility($document->path))->toBe('private');
    }
});

it('preserves tester edits deleted files disabled accounts and passwords on repeated runs', function () {
    $this->fixtures->create($this->passwords);
    $user = User::where('email', PortalFixtures::ACCOUNTS['client'])->sole();
    $user->forceFill(['password' => 'Changed-by-tester', 'portal_enabled' => false, 'portal_permissions' => []])->save();
    $user->delete();
    $dossier = Dossier::firstOrFail();
    $dossier->update(['company_name' => 'Modified by tester']);
    $document = $dossier->documents()->firstOrFail();
    Storage::disk('local')->delete($document->path);
    $document->delete();
    $this->fixtures->create([]);
    $this->artisan('demo:seed-portals', ['--apply' => true])->expectsOutputToContain('Jeu deja cree')->assertSuccessful();
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('dossiers', 6);
    expect($user->fresh()->trashed())->toBeTrue()->and($user->fresh()->portal_enabled)->toBeFalse()
        ->and(Hash::check('Changed-by-tester', $user->fresh()->password))->toBeTrue()
        ->and($dossier->fresh()->company_name)->toBe('Modified by tester')
        ->and(Storage::disk('local')->exists($document->path))->toBeFalse();
});

it('refuses unsafe environments settings or public storage before any write', function (string $setting, mixed $value) {
    if ($setting === 'environment') {
        app()->detectEnvironment(fn () => $value);
    } elseif ($setting === 'public') {
        config(['filesystems.disks.local.root' => public_path()]);
    } else {
        config([$setting => $value]);
    }
    expect(fn () => $this->fixtures->create($this->passwords))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('dossiers', 0);
})->with([
    ['environment', 'production'], ['environment', 'staging'], ['simulation.enabled', false],
    ['crm.v2_enabled', false], ['services.smsfactor.mode', 'live'], ['filesystems.disks.local.driver', 's3'],
    ['public', null],
]);

it('requires two valid distinct passwords without echoing them', function (array $passwords) {
    expect(fn () => $this->fixtures->create($passwords))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('users', 0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    [[]], [['client' => '123', 'delegataire' => 'LongEnough-1']],
    [['client' => 'Identical-123', 'delegataire' => 'Identical-123']],
    [['client' => str_repeat('a', 73), 'delegataire' => 'LongEnough-1']],
    [['client' => "eight\0chars", 'delegataire' => 'LongEnough-1']],
]);

it('never reuses an existing account even when soft deleted or differently cased', function () {
    $existing = User::factory()->create(['email' => strtoupper(PortalFixtures::ACCOUNTS['client'])]);
    $existing->delete();
    expect(fn () => $this->fixtures->create($this->passwords))->toThrow(RuntimeException::class, 'deja utilisee');
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('dossiers', 0);
    expect($existing->fresh()->trashed())->toBeTrue();
});

it('does not repair a partially removed or conflicting fixture set', function () {
    ExternalDelegataire::create(['source' => PortalFixtures::SOURCE, 'external_id' => 'client', 'name' => 'Incomplete']);
    expect(fn () => $this->fixtures->create($this->passwords))->toThrow(RuntimeException::class, 'incomplet');
    $this->assertDatabaseCount('users', 0);
});

it('refuses a missing or retired service without writing anything', function () {
    $this->service->forceFill(['retired_at' => now()])->save();
    $this->artisan('demo:seed-portals', ['--service' => $this->service->id])->assertFailed();
    $this->artisan('demo:seed-portals', ['--service' => 'not-an-id'])->assertFailed();
    $this->assertDatabaseCount('users', 0);
});

it('rolls back database and already written files if a later write fails', function () {
    $disk = Storage::disk('local');
    $mock = Mockery::mock($disk)->makePartial();
    $calls = 0;
    $mock->shouldReceive('put')->andReturnUsing(function (...$args) use ($disk, &$calls) {
        return ++$calls === 4 ? false : $disk->put(...$args);
    });
    Storage::set('local', $mock);
    expect(fn () => $this->fixtures->create($this->passwords))->toThrow(RuntimeException::class, 'Ecriture');
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('external_delegataires', 0);
    $this->assertDatabaseCount('dossiers', 0);
    $this->assertDatabaseCount('lots', 0);
    expect($disk->allFiles())->toBe([]);
});

it('isolates both test portals from real data and honors the three document audiences', function () {
    $real = Dossier::create(['reference' => 'REAL-DOSSIER', 'address' => 'Real address', 'city' => 'Paris']);
    $this->fixtures->create($this->passwords);
    $common = Dossier::where('source_reference', 'commun-prise-rdv')->sole();
    foreach (PortalFixtures::ACCOUNTS as $kind => $email) {
        $user = User::where('email', $email)->sole();
        $mine = Dossier::where('source_reference', $kind.'-seul')->sole();
        $opposite = $kind === 'client' ? 'delegataire' : 'client';
        $other = Dossier::where('source_reference', $opposite.'-seul')->sole();
        $this->actingAs($user)->get(route('client.dossiers'))->assertOk()
            ->assertSee($mine->company_name)->assertDontSee($other->company_name)->assertDontSee('REAL-DOSSIER');
        $this->get(route('client.dossiers.show', $other))->assertForbidden();
        $this->get(route('client.dossiers.show', $real))->assertForbidden();
        $this->get(route('client.dossiers.show', $common))->assertOk()
            ->assertSee('[TEST] Client et delegataire')->assertDontSee('[TEST] Interne uniquement');
        foreach ($common->documents as $document) {
            $allowed = $kind === 'client' ? $document->client_visible : $document->delegataire_visible;
            $response = $this->get(route('client.documents.download', $document));
            $allowed ? $response->assertDownload() : $response->assertNotFound();
        }
        $lot = Lot::where('source', PortalFixtures::SOURCE)->where('delegataire_id', $user->external_client_id)->sole();
        $otherLot = Lot::where('source', PortalFixtures::SOURCE)->where('delegataire_id', '!=', $user->external_client_id)->sole();
        $this->get(route('client.lots'))->assertOk()->assertSee($lot->name)->assertDontSee($otherLot->name);
        $this->get(route('client.lots.show', $lot))->assertOk();
        $this->get(route('client.lots.show', $otherLot))->assertNotFound();
        $this->get('/manager/users')->assertForbidden();
    }
});

it('supports login and explicit recontrol from both fictional accounts without granting extra permissions', function (string $kind) {
    $this->fixtures->create($this->passwords);
    $this->post('/login', ['email' => PortalFixtures::ACCOUNTS[$kind], 'password' => $this->passwords[$kind]])->assertRedirect();
    $this->get(route('dashboard'))->assertRedirect(route('client.dossiers'));
    $dossier = Dossier::where('source_reference', 'commun-recontrole-'.$kind)->sole();
    $operation = $dossier->operations()->sole();
    $this->post(route('client.dossiers.recontrol', [$dossier, $operation]), ['version' => $dossier->version])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($operation->fresh()->legacy_status)->toBe('RECONTROLE');
})->with(['client', 'delegataire']);

it('creates the fixture set only after masked confirmation of both passwords', function () {
    $this->artisan('demo:seed-portals', ['--apply' => true])
        ->expectsQuestion('Mot de passe pour '.PortalFixtures::ACCOUNTS['client'], $this->passwords['client'])
        ->expectsQuestion('Confirmer le mot de passe client', $this->passwords['client'])
        ->expectsQuestion('Mot de passe pour '.PortalFixtures::ACCOUNTS['delegataire'], $this->passwords['delegataire'])
        ->expectsQuestion('Confirmer le mot de passe delegataire', $this->passwords['delegataire'])
        ->expectsOutputToContain('Jeu fictif cree')->assertSuccessful();
    $this->assertDatabaseCount('users', 2);
});

it('aborts noninteractive or mismatched password entry without creating anything', function () {
    $this->artisan('demo:seed-portals', ['--apply' => true, '--no-interaction' => true])->assertFailed();
    $this->artisan('demo:seed-portals', ['--apply' => true])
        ->expectsQuestion('Mot de passe pour '.PortalFixtures::ACCOUNTS['client'], $this->passwords['client'])
        ->expectsQuestion('Confirmer le mot de passe client', 'mismatch')->assertFailed();
    $this->assertDatabaseCount('users', 0);
});
