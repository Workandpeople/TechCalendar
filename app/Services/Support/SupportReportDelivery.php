<?php

namespace App\Services\Support;

use App\Mail\SupportReportMail;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Mail;

class SupportReportDelivery
{
    public const RECIPIENT = 'contact@lucas-dinnichert.fr';

    public function send(array $report, string $directory): string
    {
        // HTTP bootstrap already applied global mail settings; do not query SQL again on an error.
        $configuration = config('mail.mailers.'.config('mail.default'), []);
        if (! config('mail.delivery_enabled') || config('simulation.enabled') || app()->environment('demo')
            || ($configuration['transport'] ?? null) !== 'smtp') {
            return 'simulated';
        }
        $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        unset($configuration['url']);
        $configuration['timeout'] = 10;
        $mailer = Mail::build($configuration);
        $mailer->alwaysFrom(config('mail.from.address'), config('mail.from.name') ?: 'Genius Contrôle');

        return $mailer->to(self::RECIPIENT)->send(new SupportReportMail($report, $directory)) ? 'sent' : 'simulated';
    }
}
