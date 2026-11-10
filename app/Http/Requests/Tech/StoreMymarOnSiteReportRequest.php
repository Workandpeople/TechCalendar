<?php

namespace App\Http\Requests\Tech;

class StoreMymarOnSiteReportRequest extends StoreOnSiteReportRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'request_id' => ['required', 'uuid:4']];
    }

    public function authorize(): bool
    {
        $actor = $this->user();

        return config('crm.v2_enabled') && $actor && ! $actor->trashed() && ! $actor->isExternal()
            && ! $actor->must_change_password && $actor->role === 2 && ! $actor->admin;
    }
}
