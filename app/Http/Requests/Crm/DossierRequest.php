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
            'reference' => ['required', 'string', 'max:100', Rule::unique('dossiers')->ignore($dossier)],
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
            'address' => ['required', 'string', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:190'],
            'beneficiary_address' => ['nullable', 'string', 'max:500'],
            'beneficiary_postal_code' => ['nullable', 'string', 'max:10'],
            'beneficiary_city' => ['nullable', 'string', 'max:190'],
            'installer_name' => ['nullable', 'string', 'max:190'],
            'installer_siren' => ['nullable', 'regex:/^[0-9]{9}$/'],
            'service_ids' => [$dossier ? 'prohibited' : 'required', 'array', 'min:1', 'max:30'],
            'service_ids.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'version' => [$dossier ? 'required' : 'prohibited', 'integer', 'min:1'],
        ];
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
            'city' => 'ville', 'installer_siren' => 'SIREN', 'service_ids' => 'prestations',
            'version' => 'version du dossier',
        ];
    }
}
