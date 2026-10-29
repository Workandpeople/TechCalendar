<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PortalNotificationMail extends GloballyConfiguredMailable
{
    public function __construct(public array $context) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->context['title'].' - Espace Cofrac');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.portal.notification', with: $this->context);
    }
}
