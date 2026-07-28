<?php

namespace App\Services;

use App\Mail\MailTemplateMailable;
use App\Models\MailTemplate;
use Illuminate\Support\Carbon;

class MailTemplateRenderer
{
    /**
     * @return array<string, string>
     */
    public function sampleData(): array
    {
        return [
            'app_name' => config('app.name', 'Tech Calendar'),
            'client_name' => 'Camille Martin',
            'customer_name' => 'Camille Martin',
            'customer_phone' => '06 12 34 56 78',
            'technician_name' => 'Lucas Tech',
            'service_label' => 'COFFRAC - BAR TH 171',
            'appointment_date' => Carbon::now()->addDays(3)->format('d/m/Y'),
            'appointment_time' => '09:30',
            'appointment_end_time' => '10:45',
            'address' => '12 rue de la Paix, 75002 Paris',
            'comment' => 'Prévoir un accès au local technique.',
            'manager_name' => 'Équipe planning',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function renderSubject(MailTemplate $template, array $data = []): string
    {
        return $this->replaceVariables($template->subject, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function renderMarkdownBody(MailTemplate $template, array $data = []): string
    {
        return $this->replaceVariables($template->markdown_body, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{subject:string,html:string}
     */
    public function preview(MailTemplate $template, array $data = []): array
    {
        $data = array_merge($this->sampleData(), $data);
        $mailable = new MailTemplateMailable($template, $data);

        return [
            'subject' => $this->renderSubject($template, $data),
            'html' => $mailable->render(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replaceVariables(string $content, array $data): string
    {
        return (string) preg_replace_callback('/{{\s*([a-zA-Z0-9_.-]+)\s*}}/', function (array $matches) use ($data): string {
            $value = data_get($data, $matches[1], '');

            if (is_scalar($value) || $value === null) {
                return (string) $value;
            }

            return '';
        }, $content);
    }
}
