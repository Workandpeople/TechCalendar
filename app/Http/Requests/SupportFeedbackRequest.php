<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupportFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->trashed();
    }

    public function rules(): array
    {
        return [
            'submission_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['bug', 'improvement'])],
            'title' => ['required', 'string', 'min:3', 'max:160', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
            'page_path' => ['required', 'string', 'max:2000', 'starts_with:/'],
            'viewport' => ['nullable', 'string', 'regex:/\A\d{2,5}x\d{2,5}\z/'],
            'screenshots' => ['sometimes', 'array', 'max:3'],
            'screenshots.*' => ['bail', 'required', 'file', 'image', 'mimes:png,jpg,jpeg,webp',
                'extensions:png,jpg,jpeg,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }

    public function messages(): array
    {
        return ['screenshots.max' => 'Ajoutez au maximum 3 captures.',
            'screenshots.*.max' => 'Chaque capture doit faire 4 Mo maximum.',
            'screenshots.*.dimensions' => 'Les captures ne doivent pas dépasser 6 000 pixels de côté.',
            'screenshots.*.image' => 'Utilisez une véritable image PNG, JPEG ou WebP.',
            'screenshots.*.mimes' => 'Utilisez des captures PNG, JPEG ou WebP.',
            'screenshots.*.extensions' => 'Utilisez des captures PNG, JPEG ou WebP.'];
    }
}
