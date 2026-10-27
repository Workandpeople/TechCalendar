<?php

namespace App\Services;

class GlobalMailConfiguration
{
    public function refresh(): void
    {
        if (! config('crm.v2_enabled') || config('simulation.enabled') || app()->environment('demo')) {
            return;
        }

        $before = [config('mail.default'), config('mail.mailers'), config('mail.from')];
        app(ApplicationSettings::class)->applyToConfig('mail.');
        if ($before !== [config('mail.default'), config('mail.mailers'), config('mail.from')]) {
            // Workers persist between jobs; never reuse a transport with obsolete SMTP credentials.
            app('mail.manager')->forgetMailers();
        }
    }
}
