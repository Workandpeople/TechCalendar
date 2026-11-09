<?php

namespace App\Http\Requests\Crm;

use App\Services\Crm\ClientAccess;
use App\Services\Crm\DossierStatusCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientDossierListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(ClientAccess::class)->active($this->user())
            && ! $this->user()->must_change_password;
    }

    public function rules(): array
    {
        $services = $this->filled('service_ids')
            ? app(ClientAccess::class)->dossierServices($this->user())->pluck('services.id')->all() : [];

        return [
            'q' => ['nullable', 'string', 'max:255'],
            'lot' => ['nullable', 'string', 'max:256'],
            'status' => ['nullable', Rule::in(array_keys(DossierStatusCatalog::STATUSES))],
            'service_ids' => ['nullable', 'array', 'list', 'max:250'],
            'service_ids.*' => ['bail', 'required', 'integer', 'min:1', 'distinct', Rule::in($services)],
        ];
    }

    public function attributes(): array
    {
        return ['service_ids' => 'fiches CEE', 'service_ids.*' => 'fiche CEE'];
    }
}
