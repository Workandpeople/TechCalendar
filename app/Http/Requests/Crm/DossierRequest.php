<?php

namespace App\Http\Requests\Crm;

use App\Models\Dossier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DossierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Dossier::class) ?? false;
    }

    public function rules(): array
    {
        $dossier = $this->route('dossier');

        $rules = [
            'reference' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('dossiers')->ignore($dossier)],
            'delegataire_id' => ['nullable', 'integer', Rule::exists('external_delegataires', 'id')->where(function ($query) use ($dossier): void {
                $query->where('kind', 'delegataire')->where(fn ($query) => $query->where('is_active', true)->when($dossier?->delegataire_id, fn ($query, $id) => $query->orWhere('id', $id)));
            })],
            'client_id' => ['nullable', 'integer', Rule::exists('external_delegataires', 'id')->where(function ($query) use ($dossier): void {
                $query->where('kind', 'client')->where(fn ($query) => $query->where('is_active', true)->when($dossier?->client_id, fn ($query, $id) => $query->orWhere('id', $id)));
            })],
            'company_name' => ['nullable', 'required_without:last_name', 'string', 'max:190'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'required_without:company_name', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:500'],
            'site_name' => ['nullable', 'string', 'max:190'],
            'legacy_lot_name' => ['sometimes', 'nullable', 'string', 'max:256', 'not_regex:/[\x00-\x1F\x7F]/'],
            'address' => ['required_unless:address_precision,city', 'nullable', 'string', 'max:500', 'not_regex:/[;\x00-\x1F\x7F]/'],
            'postal_code' => ['required_if:address_precision,city', 'nullable', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:190', 'not_regex:/[;\x00-\x1F\x7F]/'],
            'address_precision' => ['sometimes', Rule::in(['address', 'city'])],
            'mapbox_id' => ['nullable', 'string', 'max:200', 'regex:/^(address|place|locality|postcode)\.[a-zA-Z0-9_-]+$/D'],
            'beneficiary_address' => ['nullable', 'string', 'max:500'],
            'beneficiary_postal_code' => ['nullable', 'string', 'max:10'],
            'beneficiary_city' => ['nullable', 'string', 'max:190'],
            'installer_name' => ['nullable', 'string', 'max:190'],
            'installer_siren' => ['nullable', 'regex:/^[0-9]{9}$/'],
            'service_ids' => [$dossier ? 'prohibited' : 'required', 'array', 'size:1'],
            'service_ids.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'version' => [$dossier ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
        if ($this->input('address_precision') === 'city') {
            $rules['postal_code'][] = 'regex:/^[0-9]{5}$/';
        }
        foreach (['client_contact', 'delegataire_contact'] as $contact) {
            $rules[$contact] = ['nullable', 'array:name,company_name,email,phone,address,postal_code,city'];
            foreach (['name' => 190, 'company_name' => 190, 'email' => 190, 'phone' => 60, 'address' => 500, 'postal_code' => 10, 'city' => 190] as $field => $length) {
                $rules[$contact.'.'.$field] = ['nullable', 'string', 'max:'.$length];
                if ($field === 'email') {
                    $rules[$contact.'.'.$field][] = 'email:rfc';
                }
            }
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'reference' => 'référence', 'company_name' => 'raison sociale',
            'last_name' => 'nom du bénéficiaire', 'address' => 'adresse du lieu d’inspection',
            'city' => 'ville', 'installer_siren' => 'SIREN', 'service_ids' => 'fiche technique', 'service_ids.*' => 'fiche technique',
            'version' => 'version du dossier',
        ];
    }

    public function messages(): array
    {
        return ['service_ids.size' => 'Sélectionnez une seule fiche technique par dossier.'];
    }
}
