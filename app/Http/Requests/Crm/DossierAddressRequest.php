<?php

namespace App\Http\Requests\Crm;

use App\Services\MapboxAddressGeocoder;
use Illuminate\Foundation\Http\FormRequest;

final class DossierAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('dossier')) ?? false;
    }

    public function rules(): array
    {
        return [
            'address_query' => ['required', 'string', 'min:3', 'max:255', 'not_regex:/[;\x00-\x1F\x7F]/'],
            'mapbox_id' => ['nullable', 'string', 'max:200', 'regex:'.MapboxAddressGeocoder::IDENTIFIER_PATTERN],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
