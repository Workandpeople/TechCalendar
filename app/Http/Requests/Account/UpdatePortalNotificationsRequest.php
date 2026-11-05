<?php

namespace App\Http\Requests\Account;

use App\Services\Crm\ClientAccess;
use App\Services\Crm\PortalNotificationSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePortalNotificationsRequest extends FormRequest
{
    protected $errorBag = 'notifications';

    public function authorize(): bool
    {
        return $this->user() && app(ClientAccess::class)->active($this->user()) && ! $this->user()->must_change_password;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->user()) {
            return;
        }

        // Preserve preferences from forms opened before the recipient field was deployed.
        $recipients = $this->input('recipients', app(PortalNotificationSettings::class)->values($this->user())['recipients']);
        if ($recipients === null) {
            $recipients = [];
        }
        if (is_string($recipients) && strlen($recipients) <= PortalNotificationSettings::MAX_RECIPIENTS * 256) {
            $recipients = preg_split('/[,;\r\n]+/', $recipients, PortalNotificationSettings::MAX_RECIPIENTS + 1, PREG_SPLIT_NO_EMPTY);
            $recipients = array_values(array_filter($recipients, fn (string $email) => trim($email) !== ''));
        }
        if (is_array($recipients)) {
            $recipients = array_map(fn ($email) => is_string($email) ? strtolower(trim($email)) : $email, $recipients);
        }
        $this->merge(['recipients' => $recipients]);
    }

    public function rules(): array
    {
        return [
            'email_enabled' => ['required', 'boolean'],
            'recipients' => ['present', 'array', 'max:'.PortalNotificationSettings::MAX_RECIPIENTS, Rule::requiredIf($this->boolean('email_enabled'))],
            'recipients.*' => ['bail', 'required', 'string', 'max:254', 'email:filter'],
            'comment_added' => ['required', 'boolean'],
            'document_added' => ['required', 'boolean'],
            'statuses' => ['sometimes', 'array', 'max:20'],
            'statuses.*' => ['required', 'string', 'distinct', Rule::in(array_keys(app(PortalNotificationSettings::class)->categories($this->user())))],
        ];
    }

    public function messages(): array
    {
        return [
            'recipients.required' => 'Ajoutez au moins une adresse destinataire pour recevoir des notifications.',
            'recipients.present' => 'Renseignez les adresses destinataires.',
            'recipients.array' => 'Saisissez une adresse email par ligne, dans la limite de '.PortalNotificationSettings::MAX_RECIPIENTS.' adresses.',
            'recipients.max' => 'Vous pouvez renseigner au maximum '.PortalNotificationSettings::MAX_RECIPIENTS.' adresses destinataires.',
            'recipients.*.required' => 'Chaque destinataire doit être une adresse email valide.',
            'recipients.*.string' => 'Chaque destinataire doit être une adresse email valide.',
            'recipients.*.email' => 'Une des adresses destinataires est invalide. Vérifiez chaque adresse, sans nom ni autre texte.',
            'recipients.*.max' => 'Une adresse email ne peut pas dépasser 254 caractères.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->boolean('email_enabled')
                && ! $this->boolean('comment_added') && ! $this->boolean('document_added') && empty($this->input('statuses', []))) {
                $validator->errors()->add('email_enabled', 'Sélectionnez au moins un événement ou désactivez les emails.');
            }
        }];
    }
}
