<?php

namespace App\Services\Coffrac;

use App\Services\Sms\DossierSmsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NativeCommunicationTemplates
{
    private const MAIL_VARIABLES = [
        '{client_name}' => '{{client_name}}',
        '{adresse}' => '{{address}}',
        '{date_rdv}' => '{{appointment_date}} de {{appointment_time}} à {{appointment_end_time}}',
        '{technicien}' => '{{technician_name}}',
        '{societe}' => '{{company_name}}',
    ];

    public function import(CoffracDataset $dataset): array
    {
        NativeImportTarget::assertAllowed();
        if (DB::table('mail_templates')->exists()) {
            throw new RuntimeException('Catalogue mail natif non vide : reprise annulee.');
        }
        $mapping = $issues = [];
        foreach ($dataset->rows('mail_templates') as $row) {
            $subject = LegacyTemplateText::read($row, 'subject');
            $body = LegacyTemplateText::read($row, 'text');
            $reason = match (true) {
                $subject === null || $body === null => 'invalid_text_encoding',
                trim($subject) === '' || trim($body) === '' => 'empty_template',
                mb_strlen($subject) > 190 || mb_strlen($body) > 60000 => 'template_exceeds_native_limits',
                str_contains($subject, "\r") || str_contains($subject, "\n") => 'multiline_subject',
                preg_match('~<[/!?a-zA-Z]|\{\{|\}\}|@(php|include|if|foreach|extends|component)\b~', $subject."\n".$body) === 1 => 'markup_requires_review',
                $this->hasUnknownVariable($subject."\n".$body) => 'unmapped_variable',
                default => null,
            };
            if ($reason !== null) {
                $issues[$row['id']] = $reason;

                continue;
            }
            $markdown = $this->markdown($body);
            $nativeSubject = strtr($subject, self::MAIL_VARIABLES);
            if (mb_strlen($markdown) > 60000 || mb_strlen($nativeSubject) > 190) {
                $issues[$row['id']] = 'template_exceeds_native_limits';

                continue;
            }
            $mapping[$row['id']] = DB::table('mail_templates')->insertGetId([
                'name' => 'Coffrac #'.$row['id'].' - '.mb_substr(trim($subject), 0, 150),
                'slug' => 'coffrac-mail-'.$row['id'], 'subject' => $nativeSubject,
                'markdown_body' => $markdown, 'is_active' => false, 'mail_sender_id' => null,
                'created_at' => $this->timestamp($row['created_at'] ?? null), 'updated_at' => $this->timestamp($row['updated_at'] ?? null),
            ]);
        }
        // The SMS screen already reads legacy_records: no second SMS catalogue is created.
        $sms = array_column(app(DossierSmsService::class)->templates(), 'id');
        $unreadable = [];
        foreach ($dataset->rows('sms_tempalte') as $row) {
            if (! in_array($row['id'], $sms, true)) {
                $unreadable[] = $row['id'];
            }
        }

        return ['mail_templates' => ['mapping' => $mapping, 'inactive' => count($mapping), 'issues' => $issues,
            'sender_selection_and_activation_required' => true],
            'sms_templates' => ['available_source_ids' => $sms, 'unreadable_source_ids' => $unreadable,
                'native_duplicates_created' => 0, 'sending_enabled' => false]];
    }

    private function hasUnknownVariable(string $text): bool
    {
        return str_contains(strtr($text, array_fill_keys(array_keys(self::MAIL_VARIABLES), '')), '{')
            || str_contains(strtr($text, array_fill_keys(array_keys(self::MAIL_VARIABLES), '')), '}');
    }

    private function markdown(string $text): string
    {
        // Escape literal text, then restore only the known placeholders. Never evaluate source markup.
        $parts = preg_split('/(\{[a-z_]+\})/', str_replace(["\r\n", "\r"], "\n", $text), -1, PREG_SPLIT_DELIM_CAPTURE);
        $markdown = '';
        foreach ($parts as $part) {
            $markdown .= self::MAIL_VARIABLES[$part] ?? preg_replace('/([\\\\`*_{}\[\]<>#+.!|~\-])/', '\\\\$1', $part);
        }

        return str_replace("\n", "  \n", $markdown);
    }

    private function timestamp(?string $value): ?string
    {
        return $value && ! str_starts_with($value, '0000-')
            ? CarbonImmutable::parse($value, 'UTC')->setTimezone(config('app.timezone', 'Europe/Paris'))->format('Y-m-d H:i:s') : null;
    }
}
