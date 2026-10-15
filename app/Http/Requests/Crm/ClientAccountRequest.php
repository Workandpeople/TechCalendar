<?php

namespace App\Http\Requests\Crm;

use App\Services\Crm\ClientAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ClientAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

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
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users')->ignore($this->route('account'))],
            'password' => [$this->route('account') ? 'nullable' : 'required', 'confirmed', Password::min(8), 'max:72'],
            'portal_enabled' => ['required', 'boolean'],
            'permissions' => ['required', 'array:'.implode(',', array_keys(ClientAccess::PERMISSIONS))],
            'permissions.*' => ['boolean'],
        ];
    }
}
