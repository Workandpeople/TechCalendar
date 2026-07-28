<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Mail\MailTemplateMailable;
use App\Models\Appointment;
use App\Models\MailTemplate;
use App\Services\AppointmentMailTemplateData;
use App\Services\MailTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class PlannerAppointmentMailController extends Controller
{
    public function preview(
        Request $request,
        Appointment $appointment,
        AppointmentMailTemplateData $templateData,
        MailTemplateRenderer $renderer
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'mail_template_id' => ['nullable', 'integer', Rule::exists('mail_templates', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'subject' => ['required', 'string', 'max:190'],
            'markdown_body' => ['required', 'string', 'max:60000'],
        ]);
        $sourceTemplate = ! empty($payload['mail_template_id'])
            ? MailTemplate::query()->find($payload['mail_template_id'])
            : null;

        $template = $this->oneShotTemplate($payload['subject'], $payload['markdown_body'], $sourceTemplate);

        return response()->json($renderer->preview($template, $templateData->forAppointment($appointment)));
    }

    public function send(
        Request $request,
        Appointment $appointment,
        AppointmentMailTemplateData $templateData
    ): JsonResponse {
        abort_unless($this->canAccess($request), 403);

        $payload = $request->validate([
            'mail_template_id' => ['nullable', 'integer', Rule::exists('mail_templates', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'recipient_email' => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:190'],
            'markdown_body' => ['required', 'string', 'max:60000'],
        ], [
            'recipient_email.required' => 'Renseigne l’adresse e-mail du destinataire.',
            'recipient_email.email' => 'Renseigne une adresse e-mail valide.',
            'subject.required' => 'Le sujet du mail est obligatoire.',
            'markdown_body.required' => 'Le contenu du mail est obligatoire.',
        ]);
        $sourceTemplate = ! empty($payload['mail_template_id'])
            ? MailTemplate::query()->find($payload['mail_template_id'])
            : null;

        $template = $this->oneShotTemplate($payload['subject'], $payload['markdown_body'], $sourceTemplate);

        Mail::to($payload['recipient_email'])->queue(
            new MailTemplateMailable($template, $templateData->forAppointment($appointment))
        );

        return response()->json([
            'message' => 'Mail ajouté à la file d’envoi.',
        ]);
    }

    private function oneShotTemplate(string $subject, string $markdownBody, ?MailTemplate $sourceTemplate = null): MailTemplate
    {
        return new MailTemplate([
            'name' => 'Envoi ponctuel RDV',
            'slug' => 'one-shot-appointment-mail',
            'subject' => $subject,
            'markdown_body' => $markdownBody,
            'logo_path' => $sourceTemplate?->logo_path,
            'is_active' => true,
        ]);
    }

    private function canAccess(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->admin || in_array($user->role, [0, 1], true));
    }
}
