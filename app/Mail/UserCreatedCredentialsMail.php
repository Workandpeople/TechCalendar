<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserCreatedCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Vos accès '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.users.created');
    }

    public function attachments(): array
    {
        return [];
    }
}
