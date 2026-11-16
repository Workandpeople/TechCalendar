<?php

namespace App\Http\Requests\Tech;

use App\Models\Appointment;
use App\Services\Tech\OnSiteVisitContext;
use App\Services\Tech\TechnicianOnSiteReports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreOnSiteReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && $this->user()
            && (Gate::allows('view', $appointment)
                || app(OnSiteVisitContext::class)->allowsHistorical($this->user(), $appointment, $this->only(['report_context', 'context_signature'])));
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'snapshot' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'report_context' => ['nullable', 'string', 'max:4096'],
            'context_signature' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'evidence_only' => ['sometimes', 'boolean'],
            'visit_date' => ['nullable', 'date_format:Y-m-d', 'required_with:visit_time'],
            'visit_time' => ['nullable', 'date_format:H:i', 'required_with:visit_date'],
            'reason' => ['required', Rule::in(array_keys(TechnicianOnSiteReports::REASONS))],
            'comment' => ['nullable', 'required_if:reason,other', 'string', 'max:2000'],
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'photo.required' => 'Prenez une photo sur place pour valider le signalement.',
            'photo.image' => 'Choisissez une photo JPEG, PNG ou WebP, sans document ni capture active.',
            'photo.mimes' => 'La photo doit être au format JPEG, PNG ou WebP.',
            'photo.max' => 'La photo ne doit pas dépasser 10 Mo. Réduisez sa taille avant de réessayer.',
            'comment.required_if' => 'Précisez le problème rencontré.',
            'snapshot.*' => 'Rechargez ce rendez-vous avant de signaler un problème.',
        ];
    }
}
