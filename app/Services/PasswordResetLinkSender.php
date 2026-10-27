<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetLinkSender
{
    public const DELIVERY_FAILED = 'passwords.delivery_failed';

    public function send(string $email): string
    {
        try {
            return Password::sendResetLink(['email' => $email]);
        } catch (TransportExceptionInterface $exception) {
            // SMTP responses can contain credentials, recipients or reset links. Never log the exception.
            Log::warning('Password reset email delivery failed.', [
                'reason' => match (true) {
                    str_contains($exception->getMessage(), '5.7.60') => 'sender_not_permitted',
                    $exception->getCode() === 535 => 'authentication_failed',
                    default => 'transport_unavailable',
                },
                'smtp_code' => in_array($exception->getCode(), [535, 550], true) ? $exception->getCode() : null,
            ]);

            return self::DELIVERY_FAILED;
        }
    }
}
