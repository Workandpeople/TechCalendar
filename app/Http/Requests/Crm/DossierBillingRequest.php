<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class DossierBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageBilling', $this->route('dossier')) ?? false;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'invoice_reference' => ['nullable', 'string', 'max:190'],
            'confirmed' => ['accepted'],
        ];
    }
}
