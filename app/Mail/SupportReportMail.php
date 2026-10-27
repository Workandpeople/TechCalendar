<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SupportReportMail extends Mailable
{
    public function __construct(public array $report, private string $directory) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Cofrac · '.($this->report['kind'] === 'improvement' ? 'Amélioration' : 'Erreur').'] '.$this->report['title']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.support.report', text: 'emails.support.report-text');
    }

    public function attachments(): array
    {
        return array_map(fn (array $file) => Attachment::fromPath($this->directory.'/'.$file['name'])
            ->as($file['name'])->withMime($file['mime']), $this->report['screenshots']);
    }
}
