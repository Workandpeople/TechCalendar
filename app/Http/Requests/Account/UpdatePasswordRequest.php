<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    protected $errorBag = 'password';

    public function authorize(): bool
    {
        return $this->user() && ! $this->user()->must_change_password;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:4096', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'max:72', 'different:current_password', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return ['current_password.current_password' => 'Le mot de passe actuel est incorrect.'];
    }
}
