<?php

namespace App\Http\Requests\Crm;

use App\Models\ExternalDelegataire;
use App\Services\Crm\ClientAccess;
use App\Services\Crm\PortalCategoryAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()) {
            return false;
        }
        app(ClientAccess::class)->manage($this->user());

        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(ExternalDelegataire::KINDS))],
            'name' => ['required', 'string', 'max:190'],
            'company_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'is_active' => ['required', 'boolean'],
            'category_mode' => ['sometimes', 'required', Rule::in(['inherit', 'custom'])],
            'categories' => ['sometimes', 'array', 'max:'.count(PortalCategoryAccess::catalogue())],
            'categories.*' => ['required', 'string', 'distinct', Rule::in(array_keys(PortalCategoryAccess::catalogue()))],
        ];
    }
}
