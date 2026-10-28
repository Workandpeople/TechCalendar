<?php

use App\Mail\MailTemplateMailable;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\ExternalDelegataire;
use App\Models\User;
use App\Services\Crm\DossierMailDelivery;
use App\Services\GlobalMailConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => true, 'mail.default' => 'smtp', 'simulation.enabled' => false]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->mock(GlobalMailConfiguration::class)->allows('refresh')->andReturnNull();
    $this->manager = User::factory()->create(['role' => 0, 'admin' => false]);
    $this->dossier = Dossier::create(['reference' => 'MAIL-TRACK', 'company_name' => 'Bénéficiaire Test',
        'address' => 'Rue Test', 'city' => 'Paris', 'email' => 'beneficiary@example.invalid']);
    $this->data = ['recipient_email' => 'beneficiary@example.invalid', 'subject' => 'Votre dossier', 'markdown_body' => 'Contenu confidentiel du message'];
    $this->actingAs($this->manager);
    $this->delivery = app(DossierMailDelivery::class);
});

afterEach(function () {
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

function queuedDossierMail($test): DossierActivity
{
    $test->post(route('v2.dossiers.mail.store', $test->dossier), $test->data)->assertRedirect()->assertSessionHasNoErrors();

    return $test->dossier->activities()->where('kind', 'mail_queued')->sole();
}

it('confirms registration without claiming successful delivery and queues a tracked mail', function () {
    $this->post(route('v2.dossiers.mail.store', $this->dossier), $this->data)->assertRedirect()
        ->assertSessionHas('status', 'Demande d’envoi enregistrée avec succès. Votre email sera envoyé automatiquement. Suivez son résultat dans la section « Envoyer un mail ».');
    $activity = $this->dossier->activities()->sole();
    expect($activity->changed_fields['delivery_status'])->toBe('queued')->and($activity->client_visible)->toBeFalse()->and($activity->delegataire_visible)->toBeFalse();
    Mail::assertQueued(MailTemplateMailable::class, function ($mail) use ($activity) {
        $job = new SendQueuedMailable(unserialize(serialize($mail)));
        expect($job->tries)->toBe(1)->and($job->timeout)->toBe(90)->and($job->failOnTimeout)->toBeTrue();

        return $mail->dossierMailActivityId === $activity->id && $mail->afterCommit === true;
    });
    $this->getJson(route('v2.dossiers.mail.status', $this->dossier))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('messages.0.status', 'queued')->assertJsonPath('messages.0.pending', true)
        ->assertDontSee('Contenu confidentiel du message');
});

it('records SMTP acceptance as sent and never submits a second time on retry', function () {
    $activity = queuedDossierMail($this);
    $accepted = Mockery::mock(SentMessage::class);
    $calls = 0;
    $send = function () use (&$calls, $accepted) {
        $calls++;

        return $accepted;
    };
    expect($this->delivery->deliver($activity->id, $send))->toBe($accepted)
        ->and($this->delivery->deliver($activity->id, $send))->toBeNull()->and($calls)->toBe(1);
    $this->delivery->failed($activity->id, new RuntimeException('late failure'));
    $this->getJson(route('v2.dossiers.mail.status', $this->dossier))->assertOk()
        ->assertJsonPath('messages.0.status', 'sent')->assertJsonPath('messages.0.pending', false)
        ->assertJsonPath('messages.0.detail', 'Accepté par le serveur mail. La réception et la lecture ne sont pas confirmées.');
    expect($activity->fresh()->kind)->toBe('mail_sent')->and($activity->fresh()->body)->toStartWith('Envoyé');
});

it('records explicit SMTP refusal without exposing raw error or retrying automatically', function () {
    $activity = queuedDossierMail($this);
    expect(fn () => $this->delivery->deliver($activity->id, fn () => throw new UnexpectedResponseException('SECRET SMTP credentials', 550)))->toThrow(RuntimeException::class);
    $mail = Mail::queued(MailTemplateMailable::class)->first();
    (new SendQueuedMailable($mail))->failed(new RuntimeException('SECRET SMTP credentials', 550));
    $this->getJson(route('v2.dossiers.mail.status', $this->dossier))->assertOk()->assertJsonPath('messages.0.status', 'failed')
        ->assertDontSee('SECRET')->assertJsonPath('messages.0.pending', false);
    expect($this->delivery->deliver($activity->id, fn () => throw new LogicException('must not retry')))->toBeNull();
});

it('distinguishes unconfirmed timeouts and security cancellation from successful delivery', function () {
    $activity = queuedDossierMail($this);
    expect(fn () => $this->delivery->deliver($activity->id, fn () => throw new RuntimeException('timeout')))->toThrow(RuntimeException::class);
    expect($this->delivery->recent($this->dossier)[0]['status'])->toBe('unknown');
    $other = $this->dossier->activities()->create(['kind' => 'mail_queued', 'body' => 'Test', 'changed_fields' => ['delivery_status' => 'queued']]);
    expect($this->delivery->deliver($other->id, fn () => null))->toBeNull();
    expect($this->delivery->recent($this->dossier)[0]['status'])->toBe('blocked');
    $listenerFailure = $this->dossier->activities()->create(['kind' => 'mail_queued', 'body' => 'Test', 'changed_fields' => ['delivery_status' => 'queued']]);
    expect(fn () => $this->delivery->deliver($listenerFailure->id, fn () => throw new RuntimeException('after-send listener failure', 500)))->toThrow(RuntimeException::class);
    expect($this->delivery->recent($this->dossier)[0]['status'])->toBe('unknown');
});

it('blocks a queued email if real delivery is disabled before the worker runs', function () {
    $activity = queuedDossierMail($this);
    config(['mail.delivery_enabled' => false]);
    expect($this->delivery->deliver($activity->id, fn () => throw new LogicException('must not send')))->toBeNull()
        ->and($this->delivery->recent($this->dossier)[0]['status'])->toBe('blocked');
});

it('keeps legacy and simulated requests honest without presenting them as sent', function () {
    $this->dossier->activities()->create(['kind' => 'mail_queued', 'body' => 'Old', 'changed_fields' => ['recipient' => 'old@example.invalid']]);
    expect($this->delivery->recent($this->dossier)[0]['status'])->toBe('legacy')->and($this->delivery->recent($this->dossier)[0]['pending'])->toBeFalse();
    config(['mail.delivery_enabled' => false]);
    $this->post(route('v2.dossiers.mail.store', $this->dossier), $this->data)->assertRedirect()
        ->assertSessionHas('status', 'Simulation enregistrée : aucun email envoyé.');
    Mail::assertNothingQueued();
    expect($this->delivery->recent($this->dossier)[0]['status'])->toBe('simulated');
});

it('bounds the result set and hides mail contents and outcomes from external users', function () {
    foreach (range(1, 12) as $number) {
        $this->dossier->activities()->create(['kind' => 'mail_queued', 'body' => 'Old', 'changed_fields' => ['subject' => 'Message '.$number]]);
    }
    $this->getJson(route('v2.dossiers.mail.status', $this->dossier))->assertOk()->assertJsonCount(10, 'messages');
    $organisation = ExternalDelegataire::create(['source' => 'native', 'external_id' => 'EXT', 'kind' => 'client', 'name' => 'Client', 'is_active' => true]);
    $this->dossier->update(['client_id' => $organisation->id]);
    $client = User::factory()->create(['role' => User::ROLE_EXTERNAL, 'external_client_id' => $organisation->id, 'portal_enabled' => true]);
    $this->actingAs($client)->getJson(route('v2.dossiers.mail.status', $this->dossier))->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 2]))->getJson(route('v2.dossiers.mail.status', $this->dossier))->assertForbidden();
    auth()->logout();
    $this->getJson(route('v2.dossiers.mail.status', $this->dossier))->assertUnauthorized();
});

it('follows the real mailable and queue failure hooks using an in-memory transport only', function () {
    $activity = queuedDossierMail($this);
    $mail = Mail::queued(MailTemplateMailable::class)->first();
    $transport = new ArrayTransport;
    $manager = new MailManager(app());
    $manager->extend('smtp', fn () => $transport);
    Mail::swap($manager);
    try {
        expect($mail->send($manager))->toBeInstanceOf(SentMessage::class)
            ->and($transport->messages())->toHaveCount(1)
            ->and($this->delivery->recent($this->dossier)[0]['status'])->toBe('sent');
        $mail->send($manager);
        expect($transport->messages())->toHaveCount(1);
    } finally {
        Mail::fake();
    }
    $stalled = $this->dossier->activities()->create(['kind' => 'mail_queued', 'body' => 'Sending',
        'changed_fields' => ['delivery_status' => 'sending', 'started_at' => now()->toIso8601String()]]);
    $mail->dossierMailActivityId = $stalled->id;
    (new SendQueuedMailable($mail))->failed(new RuntimeException('worker timeout'));
    expect($stalled->fresh()->changed_fields['delivery_status'])->toBe('unknown');
});

it('does not consume the sending quota when refreshing mail status', function () {
    foreach (range(1, 12) as $check) {
        $this->getJson(route('v2.dossiers.mail.status', $this->dossier))->assertOk();
    }
    $this->post(route('v2.dossiers.mail.store', $this->dossier), $this->data)->assertRedirect()->assertSessionHasNoErrors();
    Mail::assertQueued(MailTemplateMailable::class);
});
