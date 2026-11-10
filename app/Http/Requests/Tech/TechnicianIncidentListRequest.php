<?php

namespace App\Http\Requests\Tech;

use App\Models\TechnicianIncident;
use App\Services\Tech\TechnicianOnSiteReports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TechnicianIncidentListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', TechnicianIncident::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:150'],
            'source' => ['nullable', Rule::in(['cofrac', 'mymar'])],
            'dossier_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['all', ...array_keys(TechnicianIncident::PLANNING_STATUSES)])],
            'billing' => ['nullable', Rule::in(array_keys(TechnicianIncident::BILLING_STATUSES))],
            'reason' => ['nullable', Rule::in(array_keys(TechnicianOnSiteReports::REASONS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when(filled($this->input('from')), 'after_or_equal:from')],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000']];
    }
}
