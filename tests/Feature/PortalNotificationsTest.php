<?php

use App\Jobs\SendPortalNotification;
use App\Mail\PortalNotificationMail;
use App\Models\Dossier;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\PortalNotifications;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // These tests need real commits; RefreshDatabase's outer transaction would defer every callback.
    expect(DB::connection()->getDriverName())->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    RefreshDatabaseState::$migrated = false;
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => true, 'mail.default' => 'array', 'simulation.enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->staff = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->client = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'notify-client', 'kind' => 'client',
        'name' => 'Client notifie', 'is_active' => true, 'portal_categories' => ['pending', 'visited', 'report']]);
    $this->delegataire = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'notify-delegataire', 'kind' => 'delegataire',
        'name' => 'Delegataire notifie', 'is_active' => true, 'portal_categories' => ['pending', 'visited', 'report']]);
    $this->dossier = Dossier::create(['reference' => 'NOTIFICATION-FIXTURE', 'company_name' => 'Beneficiaire notification',
        'address' => '1 rue de test', 'city' => 'Paris', 'client_id' => $this->client->id, 'delegataire_id' => $this->delegataire->id]);
    $this->operation = $this->dossier->operations()->create();
    $this->operation->forceFill(['legacy_status' => 'PRISE DE RDV'])->save();
    $this->preferences = ['email_enabled' => true, 'comment_added' => true, 'document_added' => true, 'statuses' => ['visited', 'report']];
    $this->clientUser = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->client->id, 'portal_enabled' => true, 'portal_permissions' => ClientAccess::defaults('client'),
        'portal_notification_preferences' => $this->preferences]);
    $this->delegataireUser = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'admin' => false,
        'external_client_id' => $this->delegataire->id, 'portal_enabled' => true, 'portal_permissions' => ClientAccess::defaults('delegataire'),
        'portal_notification_preferences' => $this->preferences]);
    $this->comment = fn (array $extra = []) => $this->dossier->activities()->create([
        'kind' => 'comment', 'author_id' => $this->staff->id, 'body' => 'SENSITIVE COMMENT BODY',
        'client_visible' => true, 'delegataire_visible' => true, ...$extra,
    ]);
    $this->document = fn (array $extra = []) => $this->dossier->documents()->create([
        'author_id' => $this->staff->id, 'name' => 'SENSITIVE DOCUMENT NAME', 'path' => 'private/test.pdf', 'mime_type' => 'application/pdf', 'size' => 128,
        'client_visible' => true, 'delegataire_visible' => true, ...$extra,
    ]);
});

afterEach(fn () => Http::assertNothingSent());

it('queues shared comments independently for both assigned external accounts', function () {
    $comment = ($this->comment)();
    Queue::assertPushed(SendPortalNotification::class, 2);
    Queue::assertPushed(SendPortalNotification::class, fn ($job) => $job->userId === $this->clientUser->id
        && $job->sourceId === $comment->id && $job->event === 'comment_added' && $job->afterCommit);
    Mail::assertNothingOutgoing();
});

it('never queues a private comment or document', function () {
    ($this->comment)(['client_visible' => false, 'delegataire_visible' => false]);
    ($this->document)(['client_visible' => false, 'delegataire_visible' => false]);
    Queue::assertNothingPushed();
});

it('honours client only sharing for comments and documents', function (string $source) {
    ($this->{$source})(['delegataire_visible' => false]);
    Queue::assertPushed(SendPortalNotification::class, 1);
    Queue::assertPushed(SendPortalNotification::class, fn ($job) => $job->userId === $this->clientUser->id);
})->with(['comment', 'document']);

it('notifies only newly included audiences when sharing is extended', function () {
    $document = ($this->document)(['delegataire_visible' => false]);
    Queue::fake();
    $document->update(['delegataire_visible' => true]);
    Queue::assertPushed(SendPortalNotification::class, 1);
    Queue::assertPushed(SendPortalNotification::class, fn ($job) => $job->userId === $this->delegataireUser->id);
});

it('queues selected status changes including automatic workflow writes but not noops', function () {
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    Queue::assertPushed(SendPortalNotification::class, 2);
    Queue::assertPushed(SendPortalNotification::class, fn ($job) => $job->event === 'status_changed' && $job->status === 'report');
    Queue::fake();
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    Queue::assertNothingPushed();
});

it('does not notify of an inaccessible category even if a stored preference requests it', function () {
    $this->clientUser->forceFill(['portal_notification_preferences' => [...$this->preferences, 'statuses' => ['rt_control']]])->save();
    $this->operation->forceFill(['legacy_status' => 'CONTROLE RT'])->save();
    Queue::assertNothingPushed();
});

it('does not notify the author or accounts outside the assigned organisations', function () {
    $other = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'notify-other', 'kind' => 'client', 'name' => 'Autre', 'is_active' => true]);
    User::factory()->create(['role' => User::ROLE_EXTERNAL, 'external_client_id' => $other->id, 'portal_enabled' => true,
        'portal_permissions' => ClientAccess::defaults('client'), 'portal_notification_preferences' => $this->preferences]);
    ($this->comment)(['author_id' => $this->clientUser->id]);
    Queue::assertPushed(SendPortalNotification::class, 1);
    Queue::assertPushed(SendPortalNotification::class, fn ($job) => $job->userId === $this->delegataireUser->id);
});

it('waits for the outer transaction to commit before scheduling anything', function () {
    DB::beginTransaction();
    DB::transaction(fn () => ($this->comment)());
    Queue::assertNothingPushed();
    DB::commit();
    Queue::assertPushed(SendPortalNotification::class, 2);
});

it('does not queue anything when the business action rolls back', function () {
    DB::beginTransaction();
    ($this->comment)();
    DB::rollBack();
    Queue::assertNothingPushed();
});

it('does not replay notifications when archived records are imported via query builder', function () {
    DB::table('dossier_operations')->where('id', $this->operation->id)->update(['legacy_status' => 'RAPPORT GENIUS']);
    Queue::assertNothingPushed();
});

it('keeps all outgoing notifications disabled in simulation or when delivery is disabled', function (array $config) {
    config($config);
    ($this->comment)();
    ($this->document)();
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    Queue::assertNothingPushed();
    Mail::assertNothingOutgoing();
})->with([
    [['simulation.enabled' => true]], [['mail.delivery_enabled' => false]], [['mail.default' => 'log']], [['crm.v2_enabled' => false]],
]);

it('rechecks subscriptions permissions assignment and visibility before sending', function (string $revocation) {
    $document = ($this->document)();
    match ($revocation) {
        'unsubscribe' => $this->clientUser->forceFill(['portal_notification_preferences' => [...$this->preferences, 'email_enabled' => false]])->save(),
        'event_disabled' => $this->clientUser->forceFill(['portal_notification_preferences' => [...$this->preferences, 'document_added' => false]])->save(),
        'disabled' => $this->clientUser->forceFill(['portal_enabled' => false])->save(),
        'deleted' => $this->clientUser->delete(),
        'organisation' => $this->client->update(['is_active' => false]),
        'categories' => $this->client->update(['portal_categories' => []]),
        'permission' => $this->clientUser->forceFill(['portal_permissions' => [...ClientAccess::defaults('client'), 'documents' => false]])->save(),
        'assignment' => $this->dossier->update(['client_id' => null]),
        'private' => $document->update(['client_visible' => false, 'delegataire_visible' => false]),
        'removed' => $document->delete(),
        'dossier_removed' => $this->dossier->delete(),
        'delivery_disabled' => config(['mail.delivery_enabled' => false]),
    };
    (new SendPortalNotification($this->clientUser->id, 'document_added', $this->dossier->id, $document->id))->handle(app(PortalNotifications::class));
    Mail::assertNothingOutgoing();
})->with(['unsubscribe', 'event_disabled', 'disabled', 'deleted', 'organisation', 'categories', 'permission', 'assignment', 'private', 'removed', 'dossier_removed', 'delivery_disabled']);

it('skips obsolete status notifications when the dossier has moved again', function () {
    $this->operation->forceFill(['legacy_status' => 'RDV VISITE'])->save();
    $this->operation->forceFill(['legacy_status' => 'RAPPORT GENIUS'])->save();
    (new SendPortalNotification($this->clientUser->id, 'status_changed', $this->dossier->id, $this->operation->id, 'visited'))->handle(app(PortalNotifications::class));
    Mail::assertNothingOutgoing();
});

it('sends a French email with an authenticated link without copying sensitive contents', function () {
    $comment = ($this->comment)();
    $job = new SendPortalNotification($this->clientUser->id, 'comment_added', $this->dossier->id, $comment->id);
    expect(serialize($job))->not->toContain('SENSITIVE COMMENT BODY', $this->clientUser->email);
    $job->handle(app(PortalNotifications::class));
    Mail::assertSent(PortalNotificationMail::class, function ($mail) {
        $html = $mail->render();

        return $mail->hasTo($this->clientUser->email) && str_contains($html, 'Consulter mon dossier')
            && str_contains($html, route('client.dossiers.show', $this->dossier))
            && str_contains($html, 'Tous droits réservés.') && ! str_contains($html, 'SENSITIVE COMMENT BODY')
            && ! str_contains($html, 'SENSITIVE DOCUMENT NAME');
    });
});

it('does not enable notifications for imported or new accounts without explicit preferences', function () {
    User::query()->whereIn('id', [$this->clientUser->id, $this->delegataireUser->id])->update(['portal_notification_preferences' => null]);
    ($this->comment)();
    Queue::assertNothingPushed();
});

it('fails closed on malformed stored notification settings', function (mixed $stored) {
    $this->clientUser->forceFill(['portal_notification_preferences' => $stored])->save();
    $this->delegataireUser->forceFill(['portal_notification_preferences' => null])->save();
    ($this->comment)();
    Queue::assertNothingPushed();
})->with([['corrupt'], [['email_enabled' => 'true', 'statuses' => [['report']]]]]);
