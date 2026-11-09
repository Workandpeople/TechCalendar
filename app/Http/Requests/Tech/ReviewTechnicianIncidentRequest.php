<?php

namespace App\Http\Requests\Tech;

use App\Models\TechnicianIncident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTechnicianIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('incident'))
            && ($this->input('section') !== 'billing' || $this->user()->can('reviewBilling', TechnicianIncident::class));
    }

    public function rules(): array
    {
        return ['section' => ['required', Rule::in(['planning', 'billing'])],
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(array_keys($this->input('section') === 'billing'
                ? TechnicianIncident::BILLING_STATUSES : TechnicianIncident::PLANNING_STATUSES))],
            'note' => ['required', 'string', 'max:2000']];
    }
}
