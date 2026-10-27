<?php

use App\Mail\MailTemplateMailable;
use App\Mail\UserCreatedCredentialsMail;
use App\Models\MailTemplate;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.v2_enabled' => true, 'mail.delivery_enabled' => true, 'mail.default' => 'array',
        'app.locale' => 'en', 'app.fallback_locale' => 'en', 'app.url' => 'https://cofrac.example.invalid',
        'mail.from.address' => 'contact@example.invalid', 'mail.from.name' => 'Genius Contrôle']);
    app()->setLocale('en');
    Http::preventStrayRequests();
    $this->recipient = User::factory()->create(['first_name' => 'Camille', 'last_name' => 'Martin']);
});

afterEach(fn () => Http::assertNothingSent());

function expectFrenchEmail($message): void
{
    expect($message->getHtmlBody())->toContain('lang="fr"', 'Genius Contrôle', '/images/logo.png', 'Tous droits réservés.')
        ->not->toContain('All rights reserved', 'Hello!', 'Regards,', 'having trouble', 'laravel.com/img');
    expect($message->getTextBody())->toContain('Tous droits réservés.')
        ->not->toContain('All rights reserved', 'Hello!', 'Regards,', 'having trouble');
}

it('sends the actual reset notification entirely in French with the broker expiry and a valid link', function () {
    config(['auth.passwords.users.expire' => 45]);
    $this->recipient->sendPasswordResetNotification('fixture-reset-token');
    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    expectFrenchEmail($message);
    expect($message->getSubject())->toBe('Réinitialisation de votre mot de passe - Espace Cofrac');
    foreach ([$message->getHtmlBody(), $message->getTextBody()] as $body) {
        expect($body)->toContain('Choisir mon nouveau mot de passe', '45 minutes', 'Camille Martin', 'fixture-reset-token')
            ->not->toContain('Reset your password', 'You are receiving', 'no further action', 'expire in');
    }
    expect($message->getFrom()[0]->getAddress())->toBe('contact@example.invalid');
});

it('customizes verification emails without changing the framework signed URL', function () {
    $url = 'https://cofrac.example.invalid/verification?signature=fixture';
    $message = call_user_func(VerifyEmail::$toMailCallback, $this->recipient, $url);
    expect($message->subject)->toBe('Confirmation de votre adresse email - Espace Cofrac')
        ->and($message->viewData['actionUrl'])->toBe($url);
    $html = app(Markdown::class)->render($message->markdown, $message->data())->toHtml();
    expect($html)->toContain('Confirmer mon adresse email', 'lang="fr"')->not->toContain('Verify Email Address', 'All rights reserved');
});

it('keeps a French generic notification wrapper even when Laravel runs in English', function () {
    $this->recipient->notify(new class extends Notification
    {
        public function via($recipient): array
        {
            return ['mail'];
        }

        public function toMail($recipient): MailMessage
        {
            return (new MailMessage)->subject('Votre espace Cofrac')->line('Votre dossier a été mis à jour.')
                ->action('Consulter mon dossier', 'https://cofrac.example.invalid/dossier');
        }
    });
    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    expectFrenchEmail($message);
    expect($message->getHtmlBody())->toContain('Bonjour,', 'À bientôt,', 'Si le bouton');
});

it('keeps business templates intact and localizes the common wrapper and account credentials', function () {
    $template = MailTemplate::create(['name' => 'Modèle personnalisé', 'subject' => 'Votre rendez-vous',
        'slug' => 'french-test', 'markdown_body' => "Bonjour,\n\nVotre rendez-vous est confirmé.", 'is_active' => true]);
    Mail::to($this->recipient)->send(new MailTemplateMailable($template));
    Mail::to($this->recipient)->send(new UserCreatedCredentialsMail($this->recipient, 'fixture-password'));
    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(2);
    foreach ($messages as $sent) {
        expectFrenchEmail($sent->getOriginalMessage());
    }
    expect($template->fresh()->markdown_body)->toBe("Bonjour,\n\nVotre rendez-vous est confirmé.");
});
