<?php

namespace App\Http\Requests\Crm;

use App\Services\Crm\ContentAudience;
use App\Services\Crm\DossierStatusCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DossierStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('dossier')) ?? false;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(array_keys(DossierStatusCatalog::manualStates()))],
            ...ContentAudience::rules(),
            'outcome' => ['prohibited'], 'inspected_on' => ['prohibited'],
            'report_document_id' => ['prohibited'], 'visit_document_id' => ['prohibited'], 'appointment_id' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return ['status' => 'nouvel état', 'version' => 'version du dossier'];
    }
}
