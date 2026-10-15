<?php

namespace App\Services\Capcee;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CapceeReportPayload
{
    public const MAX_PDF_BYTES = 35_000_000;

    public function build(#[\SensitiveParameter] array $metadata, #[\SensitiveParameter] string $pdf, string $parentId, ?string $documentId = null): array
    {
        $data = Validator::make([
            ...$metadata, 'parent_id' => $parentId, 'document_id' => $documentId,
        ], [
            'emmy_reference' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'report_reference' => ['required', 'string', 'max:97', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'inspected_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:issued_on'],
            'inspection_type' => ['required', Rule::in(['site', 'contact'])],
            'conclusion' => ['required', Rule::in(['Satisfaisant', 'NonSatisfaisant', 'NonVerifiable', 'Autre'])],
            'object_type' => ['required', Rule::in(['Operation__c', 'Action__c'])],
            // Always explicit: omitting this field can trigger beneficiary emails in CAPCEE.
            'send_email' => ['required', 'boolean'],
            'reason_for_change' => [Rule::requiredIf($documentId !== null), 'nullable', 'string', 'max:255'],
            'parent_id' => ['required', 'regex:/\A(?:[A-Za-z0-9]{15}|[A-Za-z0-9]{18})\z/'],
            'document_id' => ['nullable', 'regex:/\A069(?:[A-Za-z0-9]{12}|[A-Za-z0-9]{15})\z/'],
        ])->validate();

        if (strlen($pdf) > self::MAX_PDF_BYTES || ! str_starts_with($pdf, '%PDF-')) {
            throw ValidationException::withMessages(['report' => 'Le rapport doit être un PDF de 35 Mo maximum.']);
        }
        // Budget for MIME inspection, base64, JSON and HTTP buffers before allocating them.
        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
        if ($memoryLimit > 0 && memory_get_usage(true) + strlen($pdf) * 5 + 8 * 1024 * 1024 > $memoryLimit) {
            throw ValidationException::withMessages(['report' => 'Mémoire insuffisante pour préparer ce rapport. Configurer un worker dédié avec une limite mémoire adaptée avant l’envoi.']);
        }
        if ((new \finfo(FILEINFO_MIME_TYPE))->buffer($pdf) !== 'application/pdf') {
            throw ValidationException::withMessages(['report' => 'Le contenu du rapport ne correspond pas à un PDF.']);
        }

        $title = 'Rapport inspection CEE - '.mb_substr($data['emmy_reference'], 0, 210).' - '.$data['issued_on'];
        $basename = trim(preg_replace('/[^A-Za-z0-9-]+/', '-', Str::ascii($title)), '-');
        $filename = rtrim(substr($basename, 0, 251), '-').'.pdf';

        return [
            'Title' => $title,
            'PathOnClient' => $filename,
            'ContentLocation' => 'S',
            'VersionData' => base64_encode($pdf),
            'DateEmission__c' => $data['issued_on'],
            'DateInspection__c' => $data['inspected_on'],
            'StatutRapportInspection__c' => $data['conclusion'],
            'TypeRapportInspection__c' => $data['inspection_type'] === 'site' ? 'ControleSurSite' : 'ControleParContact',
            'TypeDocument__c' => $data['inspection_type'] === 'site' ? 'RISITE' : 'RINSPCON',
            'Description' => "Rapport d'inspection récupéré automatiquement via API",
            'Objet__c' => $data['object_type'] === 'Operation__c' ? 'Opération' : 'Action',
            'Emetteur__c' => 'BC',
            'Origine__c' => 'GENIUS Contrôle',
            'TECH_ParentId__c' => $parentId,
            'StatutEnvoiEmail__c' => (bool) $data['send_email'] ? 'AEnvoyer' : 'NePasEnvoyer',
            'ExternalReference__c' => 'GC_'.$data['report_reference'],
            ...($documentId !== null ? [
                'ContentDocumentId' => $documentId,
                'ReasonForChange' => $data['reason_for_change'],
            ] : []),
        ];
    }
}
