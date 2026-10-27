<?php

namespace App\Mail;

use App\Services\GlobalMailConfiguration;
use Illuminate\Mail\Mailable;

abstract class GloballyConfiguredMailable extends Mailable
{
    public function send($mailer)
    {
        if (config('crm.v2_enabled')) {
            app(GlobalMailConfiguration::class)->refresh();
            // Queued mail may still contain a transport or From chosen before settings changed.
            $this->mailer = config('mail.default');
            $this->from = $this->replyTo = [];
            $mailer = app('mail.manager');
        }

        return parent::send($mailer);
    }
}
