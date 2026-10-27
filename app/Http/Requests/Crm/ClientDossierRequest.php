<?php

namespace App\Http\Requests\Crm;

use App\Models\Dossier;
use Illuminate\Validation\Rule;

class ClientDossierRequest extends DossierRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('createFromPortal', Dossier::class) ?? false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        foreach (array_keys($rules) as $field) {
            if (in_array($field, ['reference', 'client_id', 'delegataire_id', 'version'], true)
                || str_starts_with($field, 'client_contact') || str_starts_with($field, 'delegataire_contact')) {
                unset($rules[$field]);
            }
        }
        $rules['submission_id'] = ['required', 'uuid'];
        $rules['service_ids.*'] = ['required', 'integer', 'distinct', Rule::exists('services', 'id')->whereNull('retired_at')];

        return $rules;
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'submission_id' => 'identifiant de transmission'];
    }

    public function messages(): array
    {
        return ['submission_id.uuid' => 'Le formulaire a expiré ou est invalide. Rechargez la page avant de réessayer.'];
    }
}
