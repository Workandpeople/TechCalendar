<?php

namespace App\Http\Requests\Admin;

use App\Services\ApplicationSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ApplicationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->admin;
    }

    public function rules(): array
    {
        $definitions = app(ApplicationSettings::class)->definitions();
        $rules = ['settings' => ['required', 'array', 'min:1']];
        foreach ($definitions as $key => $definition) {
            // Bracketed form keys contain literal dots, not nested configuration paths.
            $rules['settings.'.str_replace('.', '\\.', $key)] = ['sometimes', ...($definition['rules'] ?? ['nullable', 'string'])];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $submitted = $this->input('settings');
            if (is_array($submitted) && array_diff(array_keys($submitted), array_keys(app(ApplicationSettings::class)->definitions()))) {
                $validator->errors()->add('settings', 'Le formulaire contient des paramètres inconnus. Rechargez la page puis réessayez.');
            }
        }];
    }

    public function attributes(): array
    {
        return collect(app(ApplicationSettings::class)->definitions())
            ->mapWithKeys(fn (array $definition, string $key): array => ['settings.'.$key => $definition['label']])
            ->all();
    }

    protected function failedValidation(Validator $validator): void
    {
        $response = null;
        if (! $this->expectsJson()) {
            $submitted = $this->input('settings');
            $safeInput = [];
            foreach (app(ApplicationSettings::class)->definitions() as $key => $definition) {
                if (! ($definition['secret'] ?? false) && is_array($submitted) && array_key_exists($key, $submitted)
                    && (is_scalar($submitted[$key]) || $submitted[$key] === null)) {
                    $safeInput[$key] = $submitted[$key];
                }
            }
            $response = redirect()->to($this->getRedirectUrl())->withErrors($validator, $this->errorBag)
                ->withInput(['settings' => $safeInput]);
        }

        throw new ValidationException($validator, $response, $this->errorBag);
    }
}
