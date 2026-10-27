<?php

namespace App\Services;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

final class FrenchMailNotifications
{
    public static function register(): void
    {
        ResetPassword::toMailUsing(static fn ($recipient, string $token): MailMessage => (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe - Espace Cofrac')
            ->markdown('emails.auth.reset-password', [
                'recipient' => $recipient,
                'actionUrl' => ResetPassword::$createUrlCallback
                    ? call_user_func(ResetPassword::$createUrlCallback, $recipient, $token)
                    : url(route('password.reset', ['token' => $token, 'email' => $recipient->getEmailForPasswordReset()], false)),
                'expires' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]));

        VerifyEmail::toMailUsing(static fn ($recipient, string $url): MailMessage => (new MailMessage)
            ->subject('Confirmation de votre adresse email - Espace Cofrac')
            ->markdown('emails.auth.verify-email', ['recipient' => $recipient, 'actionUrl' => $url]));
    }
}
