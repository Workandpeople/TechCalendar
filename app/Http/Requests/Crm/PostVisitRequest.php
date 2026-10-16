<?php

namespace App\Http\Requests\Crm;

use App\Services\Crm\ContentAudience;
use App\Services\Crm\PostVisitWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('dossier')) ?? false;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(array_keys(PostVisitWorkflow::TRANSITIONS))],
            'comment' => ['required', 'string', 'max:10000'],
            ...ContentAudience::rules(),
            'appointment_id' => ['required_if:action,record_visit', 'nullable', 'integer'],
            'outcome' => ['required_if:action,submit_control,revise_control', 'nullable', Rule::in(array_keys(PostVisitWorkflow::OUTCOMES))],
            'inspected_on' => ['required_if:action,submit_control,revise_control', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'report_document_id' => ['required_if:action,validate_report', 'nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return ['comment' => 'commentaire', 'outcome' => 'conclusion du contrôle', 'inspected_on' => 'date de visite',
            'appointment_id' => 'rendez-vous', 'report_document_id' => 'rapport PDF', 'version' => 'version du dossier'];
    }
}
