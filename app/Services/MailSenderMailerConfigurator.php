<?php

namespace App\Services;

class MailSenderMailerConfigurator
{
    /**
     * @param  array<string, mixed>  $smtpConfig
     */
    public function configure(string $mailerName, array $smtpConfig): void
    {
        config(["mail.mailers.{$mailerName}" => $smtpConfig]);
        app('mail.manager')->purge($mailerName);
    }
}
