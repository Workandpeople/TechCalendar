<?php

namespace App\Mail;

use App\Models\MailSender;
use App\Models\MailTemplate;
use App\Services\MailSenderMailerConfigurator;
use App\Services\MailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MailTemplateMailable extends Mailable
{
    use Queueable, SerializesModels;

    public string $renderedSubject;

    public string $renderedMarkdown;

    public ?string $logoUrl;

    public ?string $senderMailerName = null;

    public ?int $senderId = null;

    public ?string $senderFromAddress = null;

    public ?string $senderFromName = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(MailTemplate $template, public array $data = [])
    {
        $template->loadMissing('sender');

        $renderer = app(MailTemplateRenderer::class);
        $sender = $template->sender;

        $this->renderedSubject = $renderer->renderSubject($template, $data);
        $this->renderedMarkdown = $renderer->renderMarkdownBody($template, $data);
        $this->logoUrl = $template->logo_url;

        if ($sender) {
            $this->senderId = $sender->id;
            $this->senderMailerName = 'mail_sender_'.$sender->id;
            $this->senderFromAddress = $sender->mail_from_address;
            $this->senderFromName = $sender->mail_from_name;
            $this->mailer($this->senderMailerName);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->senderFromAddress
                ? new Address($this->senderFromAddress, $this->senderFromName ?: null)
                : null,
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

    /**
     * @param  \Illuminate\Contracts\Mail\Factory|\Illuminate\Contracts\Mail\Mailer  $mailer
     */
    public function send($mailer)
    {
        if ($this->senderId && $this->senderMailerName) {
            $sender = MailSender::query()->find($this->senderId);

            if ($sender) {
                app(MailSenderMailerConfigurator::class)->configure($this->senderMailerName, $sender->smtpConfig());
            }
        }

        return parent::send($mailer);
    }
}
