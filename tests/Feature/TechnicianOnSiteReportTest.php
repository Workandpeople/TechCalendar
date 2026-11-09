<?php

use App\Jobs\SendPortalNotification;
use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\ExternalDelegataire;
use App\Models\Service;
use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Tech\OnSitePhoto;
use App\Services\Tech\TechnicianOnSiteReports;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    // Real commits exercise notification callbacks, exclusively against disposable in-memory SQL.
    expect(DB::connection()->getDriverName())->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    RefreshDatabaseState::$migrated = false;
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => false]);
    $this->travelTo(now()->setDate(2026, 11, 6)->setTime(10, 15));
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    Storage::fake('local');
    $this->tech = User::factory()->create(['role' => 2, 'admin' => false, 'must_change_password' => false]);
    $this->other = User::factory()->create(['role' => 2, 'admin' => false, 'must_change_password' => false]);
    $this->service = Service::create(['type' => 'COFRAC', 'name' => 'Visite', 'average_duration_minutes' => 60]);
    $this->dossier = Dossier::create(['reference' => 'ON-SITE', 'address' => 'Rue de test', 'city' => 'Paris']);
    $this->operation = $this->dossier->operations()->create(['service_id' => $this->service->id]);
    $this->operation->forceFill(['legacy_status' => 'RDV ATTENTE VISITE'])->save();
    $this->appointment = Appointment::forceCreate([
        'dossier_operation_id' => $this->operation->id, 'technician_id' => $this->tech->id,
        'service_id' => $this->service->id, 'created_by' => $this->tech->id,
        'starts_at' => '2026-11-06 10:00', 'ends_at' => '2026-11-06 11:00', 'duration_minutes' => 60,
        'customer_first_name' => 'Bénéficiaire', 'customer_last_name' => 'Fictif', 'customer_phone' => '',
        'address' => 'Rue de test, 75001 Paris', 'status' => 'scheduled', 'comment' => 'Consigne du planning à conserver',
        'external_payload' => ['retained' => 'Existing data'],
    ]);
    $this->url = route('tech.appointments.on-site-report', $this->appointment);
    $this->payload = fn (array $extra = []) => [...[
        'request_id' => (string) Str::uuid(), 'snapshot' => app(TechnicianOnSiteReports::class)->snapshot($this->appointment),
        'reason' => 'absent', 'comment' => 'Portail fermé, appels sans réponse.', 'audience' => 'client',
        'photo' => UploadedFile::fake()->image('presence.png', 80, 60),
    ], ...$extra];
    $this->actingAs($this->tech);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
});

it('offers the mobile report action only for an assigned and still scheduled intervention', function () {
    $this->get(route('tech.planning'))->assertOk()->assertSee('#tech-on-site-report', false);
    $this->get(route('tech.appointments.show', $this->appointment))->assertOk()->assertSee('data-on-site-report', false)
        ->assertSee('capture="environment"', false)->assertSee('photo', false);
    expect(Gate::forUser($this->tech)->allows('update', $this->dossier))->toBeFalse();
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    $this->get(route('tech.appointments.show', $this->appointment))->assertOk()->assertDontSee('data-on-site-report', false);
});

it('offers and records the explicit absence refusal and forgotten appointment reasons', function (string $reason, string $label) {
    $this->get(route('tech.appointments.show', $this->appointment))->assertOk()->assertSee($label);
    $this->postJson($this->url, ($this->payload)(['reason' => $reason]))->assertOk();
    expect($this->appointment->fresh()->external_payload['techcalendar_on_site']['reason'])->toBe($reason)
        ->and($this->appointment->fresh()->status)->toBe('problem')
        ->and($this->dossier->comments()->sole()->body)->toContain($label)
        ->and($this->dossier->documents()->sole()->description)->toContain($label);
})->with([
    ['absent', 'Client absent'],
    ['refused', 'Client refuse le rendez-vous'],
    ['forgotten', 'Client avait oublié le rendez-vous'],
]);

it('atomically records a timestamped incident its private raster photo and the chosen shared audience', function ($audience, $client, $delegate) {
    $payload = ($this->payload)(['audience' => $audience, 'comment' => '<script>alert(1)</script> Texte <b>fourni</b>']);
    $version = $this->dossier->fresh()->version;
    $this->postJson($this->url, $payload)->assertOk()->assertJsonPath('url', route('tech.appointments.show', $this->appointment));
    $appointment = $this->appointment->fresh();
    $document = $this->dossier->documents()->sole();
    $comment = $this->dossier->comments()->sole();
    expect($appointment->status)->toBe('problem')->and($appointment->problem_reported_at->format('H:i'))->toBe('10:15')
        ->and($appointment->comment)->toBe('Consigne du planning à conserver')
        ->and($appointment->external_payload['retained'])->toBe('Existing data')
        ->and($appointment->external_payload['techcalendar_on_site']['request_id'])->toBe($payload['request_id'])
        ->and($appointment->external_payload['techcalendar_on_site']['document_id'])->toBe($document->id)
        ->and($this->operation->fresh()->legacy_status)->toBe('PROBLÈME RENDEZ-VOUS')
        ->and($document->author_id)->toBe($this->tech->id)->and($document->document_type)->toBe('free')
        ->and($document->mime_type)->toBe('image/jpeg')->and($document->client_visible)->toBe($client)
        ->and($document->delegataire_visible)->toBe($delegate)
        ->and($comment->author_id)->toBe($this->tech->id)->and($comment->client_visible)->toBe($client)
        ->and($comment->delegataire_visible)->toBe($delegate)
        ->and($comment->body)->toContain('Client absent', '06/11/2026 à 10:15', '&lt;script&gt;')
        ->and($comment->body)->not->toContain('<script>', '<b>')
        ->and($this->dossier->fresh()->version)->toBe($version + 1);
    Storage::disk('local')->assertExists($document->path);
    expect(getimagesize(Storage::disk('local')->path($document->path))['mime'])->toBe('image/jpeg');
    $incident = TechnicianIncident::sole();
    expect($incident->request_id)->toBe($payload['request_id'])->and($incident->document_id)->toBe($document->id)
        ->and($incident->technician_id)->toBe($this->tech->id)->and($incident->planning_status)->toBe('pending')
        ->and($incident->billing_status)->toBe('review');
    $this->get(route('tech.appointments.show', $appointment))->assertOk()->assertSee('Signalement enregistré');
    $this->get(route('tech.documents.download', [$appointment, 'dossier', $document->id]).'?preview=1')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($this->other)->get(route('tech.documents.download', [$appointment, 'dossier', $document->id]))->assertForbidden();
    Queue::assertNothingPushed();
})->with([['internal', false, false], ['client', true, false], ['shared', true, true]]);

it('acknowledges the same request again without duplicating the photo comment status or notifications', function () {
    $payload = ($this->payload)();
    $this->postJson($this->url, $payload)->assertOk();
    $version = $this->dossier->fresh()->version;
    $this->postJson($this->url, $payload)->assertOk();
    expect($this->dossier->documents()->count())->toBe(1)->and($this->dossier->comments()->count())->toBe(1)
        ->and(TechnicianIncident::count())->toBe(1)
        ->and($this->dossier->fresh()->version)->toBe($version)->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    $this->postJson($this->url, ($this->payload)())->assertForbidden();
});

it('requires a usable photo reason scope and request identity without writing anything', function ($invalid, $field) {
    $extra = match ($invalid) {
        'missing' => ['photo' => null], 'text' => ['photo' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image')],
        'svg' => ['photo' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>')],
        'large' => ['photo' => UploadedFile::fake()->image('x.jpg')->size(10241)],
        'pixels' => ['photo' => UploadedFile::fake()->image('x.png', 5000, 2500)],
        'reason' => ['reason' => 'invented'], 'other' => ['reason' => 'other', 'comment' => ''],
        'audience' => ['audience' => 'everyone'], 'uuid' => ['request_id' => 'invalid'],
    };
    $this->postJson($this->url, ($this->payload)($extra))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->appointment->fresh()->status)->toBe('scheduled')->and($this->dossier->documents()->count())->toBe(0)
        ->and($this->dossier->comments()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();
})->with([['missing', 'photo'], ['text', 'photo'], ['svg', 'photo'], ['large', 'photo'], ['pixels', 'photo'],
    ['reason', 'reason'], ['other', 'comment'], ['audience', 'audience'], ['uuid', 'request_id']]);

it('denies stale booking snapshots even if the assigned technician has not changed', function () {
    $payload = ($this->payload)();
    $this->appointment->update(['starts_at' => '2026-11-06 11:00', 'ends_at' => '2026-11-06 12:00']);
    $this->postJson($this->url, $payload)->assertUnprocessable()->assertJsonValidationErrors('snapshot');
    expect(Storage::disk('local')->allFiles())->toBeEmpty()->and($this->dossier->comments()->count())->toBe(0);
});

it('denies unrelated inactive external and password gated accounts', function ($kind) {
    $actor = match ($kind) {
        'other' => $this->other,
        'external' => User::factory()->create(['role' => 3, 'admin' => false]),
        'manager' => User::factory()->create(['role' => 0, 'admin' => false]),
        default => $this->tech,
    };
    if ($kind === 'inactive') {
        $actor->delete();
    }
    if ($kind === 'password') {
        $actor->forceFill(['must_change_password' => true])->save();
    }
    $this->actingAs($actor)->postJson($this->url, ($this->payload)())->assertForbidden();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with(['other', 'external', 'manager', 'inactive', 'password']);

it('refuses unlinked deleted closed and MYMAR appointments without touching source systems', function ($kind) {
    match ($kind) {
        'unlinked' => $this->appointment->forceFill(['dossier_operation_id' => null])->save(),
        'deleted-dossier' => $this->dossier->delete(),
        'closed' => $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save(),
        'mymar' => $this->appointment->update(['external_source' => 'mymar']),
        'cancelled' => $this->appointment->update(['status' => 'cancelled']),
    };
    $this->postJson($this->url, ($this->payload)())->assertForbidden();
    expect(Storage::disk('local')->allFiles())->toBeEmpty()->and($this->dossier->comments()->count())->toBe(0);
})->with(['unlinked', 'deleted-dossier', 'closed', 'mymar', 'cancelled']);

it('rechecks assignment and account access after the photo was staged and cleans it up if revoked', function ($revocation) {
    $real = new OnSitePhoto;
    $fake = Mockery::mock(OnSitePhoto::class);
    $fake->shouldReceive('store')->once()->andReturnUsing(function ($file, $dossier) use ($real, $revocation) {
        $stored = $real->store($file, $dossier);
        match ($revocation) {
            'assignment' => DB::table('appointments')->where('id', $this->appointment->id)->update(['technician_id' => $this->other->id]),
            'account' => DB::table('users')->where('id', $this->tech->id)->update(['deleted_at' => now()]),
            'role' => DB::table('users')->where('id', $this->tech->id)->update(['role' => 0]),
        };

        return $stored;
    });
    $this->app->instance(OnSitePhoto::class, $fake);
    $this->postJson($this->url, ($this->payload)())->assertForbidden();
    expect(Storage::disk('local')->allFiles())->toBeEmpty()->and($this->dossier->comments()->count())->toBe(0);
})->with(['assignment', 'account', 'role']);

it('rolls back all business changes and removes the staged photo on database failure', function () {
    Event::listen('eloquent.creating: '.DossierDocument::class, fn () => throw new RuntimeException('Synthetic database failure'));
    $this->withoutExceptionHandling();
    try {
        $this->postJson($this->url, ($this->payload)());
        $this->fail('The synthetic database error must surface.');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Synthetic database failure');
    }
    expect($this->appointment->fresh()->status)->toBe('scheduled')->and($this->operation->fresh()->legacy_status)->toBe('RDV ATTENTE VISITE')
        ->and($this->dossier->activities()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();
});

it('only queues selected notifications for assigned organisations after a successful shared report', function () {
    config(['mail.delivery_enabled' => true, 'mail.default' => 'array', 'simulation.enabled' => false]);
    $org = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'ON-SITE-CLIENT', 'kind' => 'client',
        'name' => 'Société de test', 'is_active' => true, 'portal_categories' => ['problem']]);
    $this->dossier->update(['client_id' => $org->id]);
    $recipient = User::factory()->create(['role' => 3, 'admin' => false, 'external_client_id' => $org->id,
        'portal_enabled' => true, 'must_change_password' => false, 'portal_permissions' => ClientAccess::defaults('client'),
        'portal_notification_preferences' => ['email_enabled' => true, 'comment_added' => true,
            'document_added' => false, 'statuses' => []]]);
    $payload = ($this->payload)();
    $this->postJson($this->url, $payload)->assertOk();
    Queue::assertPushed(SendPortalNotification::class, 1);
    Queue::assertPushed(SendPortalNotification::class, fn ($job) => $job->userId === $recipient->id && $job->event === 'comment_added');
    $this->postJson($this->url, $payload)->assertOk();
    Queue::assertPushed(SendPortalNotification::class, 1);
});

it('retains an attached photo if confirmation fails after commit and safely acknowledges the retry', function () {
    Event::listen('eloquent.created: '.DossierDocument::class, function () {
        DB::afterCommit(fn () => throw new RuntimeException('Synthetic post-commit failure'));
    });
    $payload = ($this->payload)();
    $this->withoutExceptionHandling();
    try {
        $this->postJson($this->url, $payload);
        $this->fail('The synthetic post-commit error must surface.');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Synthetic post-commit failure');
    }
    $document = $this->dossier->documents()->sole();
    expect($this->appointment->fresh()->status)->toBe('problem');
    Storage::disk('local')->assertExists($document->path);
    $this->postJson($this->url, $payload)->assertOk();
    expect($this->dossier->documents()->count())->toBe(1)->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('strips metadata and appended content by generating a fresh raster file', function () {
    $file = UploadedFile::fake()->image('sample.jpg', 2200, 800);
    file_put_contents($file->getPathname(), "\nGPS SECRET APPENDED PAYLOAD", FILE_APPEND);
    $this->postJson($this->url, ($this->payload)(['photo' => $file]))->assertOk();
    $document = $this->dossier->documents()->sole();
    $path = Storage::disk('local')->path($document->path);
    expect(getimagesize($path)[0])->toBe(2000)->and(file_get_contents($path))->not->toContain('GPS SECRET APPENDED PAYLOAD');
});
