<?php

namespace App\Http\Requests\Tech;

use App\Models\TechnicianIncident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeIncidentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('incident'));
    }

    public function rules(): array
    {
        return ['context' => ['required', 'string', 'max:16384'], 'target_status' => ['required', 'string', 'max:64'],
            'planning_status' => ['required', Rule::in(array_keys(TechnicianIncident::PLANNING_STATUSES))],
            'note' => ['required', 'string', 'max:2000']];
    }
}
