<?php

namespace App\Mail;

use App\Models\MailTemplate;
use App\Services\MailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MailTemplateMailable extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public MailTemplate $template,
        public array $data = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: app(MailTemplateRenderer::class)->renderSubject($this->template, $this->data),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.templates.dynamic',
            with: [
                'markdown' => app(MailTemplateRenderer::class)->renderMarkdownBody($this->template, $this->data),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
