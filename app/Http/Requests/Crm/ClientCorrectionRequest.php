<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class ClientCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('respondToNonConformity', $this->route('dossier')) ?? false;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'string', 'max:10000'],
            'document' => ['nullable', 'file', 'max:10240',
                'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx', 'extensions:pdf,jpg,jpeg,png,webp,docx,xlsx'],
            'document_name' => ['nullable', 'string', 'max:190'],
        ];
    }

    public function attributes(): array
    {
        return ['comment' => 'commentaire', 'document' => 'document', 'document_name' => 'nom du document', 'version' => 'version du dossier'];
    }
}
