<?php

namespace App\Http\Requests\Crm;

use App\Services\Capcee\CapceeReportPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CapceeReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transmitReport', $this->route('dossier')) ?? false;
    }

    public function rules(): array
    {
        return [
            'submission_token' => ['required', 'uuid'],
            'emmy_reference' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'object_type' => ['required', Rule::in(['Operation__c', 'Action__c'])],
            'inspection_type' => ['required', Rule::in(['site', 'contact'])],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'inspected_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:issued_on'],
            'conclusion' => ['required', Rule::in(['Satisfaisant', 'NonSatisfaisant', 'NonVerifiable', 'Autre'])],
            'send_email' => ['required', 'boolean'],
            'reason_for_change' => [Rule::requiredIf($this->route('report') !== null), 'nullable', 'string', 'max:255'],
            'validated_report' => ['required', 'accepted'],
            'report_file' => ['bail', 'required', 'file', 'mimes:pdf', 'extensions:pdf', 'max:34180',
                function ($attribute, $file, $fail): void {
                    if ($file->getSize() > CapceeReportPayload::MAX_PDF_BYTES) {
                        $fail('Le rapport doit être un PDF de 35 Mo maximum.');
                    }
                }],
        ];
    }

    public function attributes(): array
    {
        return ['emmy_reference' => 'référence EMMY', 'issued_on' => 'date d’émission',
            'inspected_on' => 'date d’inspection', 'report_file' => 'rapport PDF',
            'reason_for_change' => 'motif de révision', 'validated_report' => 'confirmation de validation du rapport'];
    }
}
