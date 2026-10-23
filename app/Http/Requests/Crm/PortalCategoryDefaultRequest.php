<?php

namespace App\Http\Requests\Crm;

use App\Services\Crm\ClientAccess;
use App\Services\Crm\PortalCategoryAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortalCategoryDefaultRequest extends FormRequest
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
        return ['categories' => ['sometimes', 'array', 'max:'.count(PortalCategoryAccess::catalogue())],
            'categories.*' => ['required', 'string', 'distinct', Rule::in(array_keys(PortalCategoryAccess::catalogue()))]];
    }
}
