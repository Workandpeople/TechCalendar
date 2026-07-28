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

    public string $renderedSubject;

    public string $renderedMarkdown;

    public ?string $logoUrl;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(MailTemplate $template, public array $data = [])
    {
        $renderer = app(MailTemplateRenderer::class);

        $this->renderedSubject = $renderer->renderSubject($template, $data);
        $this->renderedMarkdown = $renderer->renderMarkdownBody($template, $data);
        $this->logoUrl = $template->logo_url;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->renderedSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.templates.dynamic',
            with: [
                'markdown' => $this->renderedMarkdown,
                'logoUrl' => $this->logoUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
