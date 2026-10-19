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
            'q' => ['nullable', 'string', 'max:120'],
            'delegataire_id' => ['nullable', 'integer', 'exists:external_delegataires,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
            'planning' => ['nullable', Rule::in(['unlinked', 'upcoming', 'problem'])],
            'billing' => ['nullable', Rule::in(['unbilled', 'partial', 'invoiced', 'review'])],
            'status' => ['nullable', Rule::in(array_keys(DossierStatusCatalog::STATUSES))],
            'sort' => ['nullable', Rule::in(array_keys(DossierTable::COLUMNS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'all_columns' => ['nullable', 'boolean'],
        ];
    }
}
