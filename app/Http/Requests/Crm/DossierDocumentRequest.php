<?php

namespace App\Http\Requests\Crm;

use App\Services\Crm\ContentAudience;
use App\Services\Crm\PostVisitWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DossierDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('dossier')) ?? false;
    }

    public function rules(): array
    {
        $report = $this->input('document_type') === 'report';
        $visit = $this->input('document_type') === 'visit';

        return [
            ...ContentAudience::rules(),
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:10000'],
            'document_type' => ['sometimes', Rule::in(['free', 'visit', 'report'])],
            'document' => ['required', 'file', 'max:10240',
                $report ? 'mimes:pdf' : 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx',
                $report ? 'extensions:pdf' : 'extensions:pdf,jpg,jpeg,png,webp,docx,xlsx'],
            'operation_id' => [Rule::requiredIf($report || $visit), 'nullable', 'integer'],
            'appointment_id' => [Rule::requiredIf($visit), 'nullable', 'integer'],
            'control_reference' => [Rule::requiredIf($report), 'nullable', 'string', 'max:190'],
            'inspected_on' => [Rule::requiredIf($report), 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'issued_on' => [Rule::requiredIf($report), 'nullable', 'date_format:Y-m-d', 'after_or_equal:inspected_on', 'before_or_equal:today'],
            'outcome' => [Rule::requiredIf($report), 'nullable', Rule::in(array_keys(PostVisitWorkflow::OUTCOMES))],
            'capcee_emmy_reference' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'capcee_object_type' => ['nullable', Rule::in(['Operation__c', 'Action__c'])],
            'capcee_inspection_type' => ['nullable', Rule::in(['site', 'contact'])],
            'workflow_action' => ['sometimes', Rule::in(['store', 'submit_control', 'revise_control', 'validate_report'])],
            'version' => [Rule::requiredIf($report || $visit), 'nullable', 'integer', 'min:1'],
        ];
    }
}
