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

        return [
            ...ContentAudience::rules(),
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:10000'],
            'document_type' => ['sometimes', Rule::in(['free', 'report'])],
            'document' => ['required', 'file', 'max:10240',
                $report ? 'mimes:pdf' : 'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx',
                $report ? 'extensions:pdf' : 'extensions:pdf,jpg,jpeg,png,webp,docx,xlsx'],
            'operation_id' => [Rule::requiredIf($report), 'nullable', 'integer'],
            'control_reference' => [Rule::requiredIf($report), 'nullable', 'string', 'max:190'],
            'inspected_on' => [Rule::requiredIf($report), 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'issued_on' => [Rule::requiredIf($report), 'nullable', 'date_format:Y-m-d', 'after_or_equal:inspected_on', 'before_or_equal:today'],
            'outcome' => [Rule::requiredIf($report), 'nullable', Rule::in(array_keys(PostVisitWorkflow::OUTCOMES))],
            'workflow_action' => ['sometimes', Rule::in(['store', 'submit_control', 'revise_control', 'validate_report'])],
            'version' => [Rule::requiredIf($report), 'nullable', 'integer', 'min:1'],
        ];
    }
}
