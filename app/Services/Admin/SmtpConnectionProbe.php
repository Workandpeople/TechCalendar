<?php

namespace App\Services\Admin;

use Illuminate\Mail\MailManager;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Smtp\Auth;
use Symfony\Component\Mailer\Transport\Smtp\Auth\AuthenticatorInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class SmtpConnectionProbe
{
    public function __construct(private readonly MailManager $mailers) {}

    public function check(array $configuration): bool
    {
        $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        $host = $configuration['host'] ?? '';
        $port = (int) ($configuration['port'] ?? 587);
        if (! is_string($host) || ! preg_match('/\A[a-zA-Z0-9.\-\[\]:]+\z/', $host)
            || ! in_array($port, [25, 465, 587, 2525], true)) {
            throw new InvalidArgumentException('Invalid saved SMTP destination.');
        }

        // Build a disposable transport: never mutate the application's mailer or send a message.
        $configuration['transport'] = 'smtp';
        $configuration['scheme'] = $configuration['driver'] ?? $configuration['scheme']
            ?? ($port === 465 ? 'smtps' : 'smtp');
        if (! in_array($configuration['scheme'], ['smtp', 'smtps'], true)) {
            throw new InvalidArgumentException('Invalid SMTP scheme.');
        }
        $configuration['timeout'] = 5;
        $configuration['verify_peer'] = true;
        unset($configuration['stream'], $configuration['source_ip']);
        $transport = $this->mailers->createSymfonyTransport($configuration);
        if (! $transport instanceof EsmtpTransport) {
            throw new InvalidArgumentException('SMTP transport required.');
        }
        $authenticated = false;
        $markAuthenticated = static function () use (&$authenticated): void {
            $authenticated = true;
        };
        $authenticators = [];
        foreach ([new Auth\CramMd5Authenticator, new Auth\LoginAuthenticator, new Auth\PlainAuthenticator, new Auth\XOAuth2Authenticator] as $inner) {
            $authenticators[] = new class($inner, $markAuthenticated) implements AuthenticatorInterface
            {
                public function __construct(private AuthenticatorInterface $inner, private \Closure $markAuthenticated) {}

                public function getAuthKeyword(): string
                {
                    return $this->inner->getAuthKeyword();
                }

                public function authenticate(EsmtpTransport $client): void
                {
                    $this->inner->authenticate($client);
                    ($this->markAuthenticated)();
                }
            };
        }
        $transport->setAuthenticators($authenticators);
        $transport->setRequireTls(filled($configuration['username'] ?? null)
            || in_array($configuration['encryption'] ?? null, ['tls', 'ssl'], true));
        try {
            $transport->start(); // EHLO, STARTTLS and AUTH only; no MAIL FROM, RCPT TO or DATA.

            return $authenticated;
        } finally {
            $transport->stop();
        }
    }
}
