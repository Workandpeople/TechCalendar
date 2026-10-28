<?php

namespace App\Http\Requests\Crm;

use App\Models\Dossier;
use App\Services\Crm\DossierStatusCatalog;
use App\Services\Crm\DossierTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DossierListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Dossier::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'delegataire_id' => ['nullable', 'integer', 'exists:external_delegataires,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'service_ids' => ['nullable', 'array', 'list', 'max:250'],
            'service_ids.*' => ['required', 'integer', 'min:1', 'distinct', 'exists:services,id'],
            'planning_user_ids' => [Rule::excludeIf($this->input('status') !== 'visited'), 'nullable', 'array', 'list', 'max:250'],
            'planning_user_ids.*' => [Rule::excludeIf($this->input('status') !== 'visited'), 'required', 'integer', 'min:1', 'distinct',
                Rule::exists('users', 'id')->where('role', 0)->whereNull('external_client_id')->whereNull('deleted_at')],
            'department' => ['nullable', 'string', 'exists:departments,code'],
            'section' => ['nullable', Rule::in(['corse', 'domtom', 'sav'])],
            'date_field' => ['nullable', Rule::in(['created', 'appointment'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('date_from'), 'after_or_equal:date_from')],
            'per_page' => ['nullable', Rule::in([10, 25, 50, 100])],
            'planning' => ['nullable', Rule::in(['unlinked', 'upcoming', 'problem'])],
            'billing' => ['nullable', Rule::in(['unbilled', 'partial', 'invoiced', 'review'])],
            'status' => ['nullable', Rule::in(array_keys(DossierStatusCatalog::STATUSES))],
            'sort' => ['nullable', Rule::in(array_keys(DossierTable::COLUMNS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'all_columns' => ['nullable', 'boolean'],
        ];
    }
}
