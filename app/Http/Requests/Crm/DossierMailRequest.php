<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

final class DossierMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('dossier')) ?? false;
    }

    public function rules(): array
    {
        return [
            'mail_template_id' => ['nullable', 'integer', 'min:1'],
            'recipient_email' => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:190', 'not_regex:/[\r\n]/'],
            'markdown_body' => ['required', 'string', 'max:60000'],
        ];
    }
}
