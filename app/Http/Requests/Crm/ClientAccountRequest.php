<?php

namespace App\Http\Requests\Crm;

use App\Models\User;
use App\Rules\AvailableUserEmail;
use App\Services\Crm\ClientAccess;
use Illuminate\Foundation\Http\FormRequest;
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
            'email' => ['bail', 'required', 'email:rfc', 'max:190', new AvailableUserEmail($this->user(), $this->route('account') instanceof User ? $this->route('account')->id : null)],
            'password' => [$this->route('account') ? 'nullable' : 'required', 'confirmed', Password::min(8), 'max:72'],
            'portal_enabled' => ['required', 'boolean'],
            'permissions' => ['required', 'array:'.implode(',', array_keys(ClientAccess::PERMISSIONS))],
            'permissions.*' => ['boolean'],
        ];
    }
}
