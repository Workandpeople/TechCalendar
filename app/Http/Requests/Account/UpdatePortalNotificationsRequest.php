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

    public function rules(): array
    {
        return [
            'email_enabled' => ['required', 'boolean'],
            'comment_added' => ['required', 'boolean'],
            'document_added' => ['required', 'boolean'],
            'statuses' => ['sometimes', 'array', 'max:20'],
            'statuses.*' => ['required', 'string', 'distinct', Rule::in(array_keys(app(PortalNotificationSettings::class)->categories($this->user())))],
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
