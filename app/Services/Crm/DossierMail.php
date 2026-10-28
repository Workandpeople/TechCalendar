<?php

namespace App\Services\Crm;

use App\Mail\MailTemplateMailable;
use App\Models\Dossier;
use App\Models\MailTemplate;
use App\Models\User;
use App\Services\AppointmentMailTemplateData;
use App\Services\MailTemplateRenderer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class DossierMail
{
    public function templates(): Collection
    {
        return MailTemplate::query()->with('sender')->where('is_active', true)
            ->when(! config('crm.v2_enabled'), fn ($query) => $query->where(fn ($q) => $q->whereNull('mail_sender_id')->orWhereHas('sender', fn ($s) => $s->where('is_active', true))))
            ->orderBy('name')->get();
    }

    public function preview(User $actor, Dossier $dossier, array $data): array
    {
        Gate::forUser($actor)->authorize('update', $dossier);

        return app(MailTemplateRenderer::class)->preview($this->template($data), $this->variables($dossier, $actor));
    }

    public function send(User $actor, Dossier $dossier, array $data): string
    {
        Gate::forUser($actor)->authorize('update', $dossier);

        $activityId = null;
        try {
            return DB::transaction(function () use ($actor, $dossier, $data, &$activityId): string {
                $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
                Gate::forUser($actor)->authorize('update', $dossier);
                $template = $this->template($data);
                $mailable = new MailTemplateMailable($template, $this->variables($dossier, $actor));
                $simulated = ! config('mail.delivery_enabled') || config('simulation.enabled')
                    || app()->environment('demo') || in_array(config('mail.default'), ['log', 'array'], true);
                $activity = $dossier->activities()->create(['author_id' => $actor->id, 'kind' => $simulated ? 'mail_simulated' : 'mail_queued',
                    'body' => ($simulated ? 'Email simulé, sans livraison' : 'Email mis en file d’envoi').' à '.$data['recipient_email'].' : '.$mailable->renderedSubject,
                    'changed_fields' => ['recipient' => $data['recipient_email'], 'template_id' => $data['mail_template_id'] ?? null,
                        'subject' => $mailable->renderedSubject, 'body' => $mailable->renderedMarkdown,
                        'delivery_status' => $simulated ? 'simulated' : 'queued'],
                    'client_visible' => false, 'delegataire_visible' => false]);
                $activityId = $activity->id;
                if (! $simulated) {
                    $mailable->dossierMailActivityId = $activityId;
                    // No automatic replay: a timeout after SMTP acceptance must not create duplicate emails.
                    $mailable->tries = 1;
                    $mailable->timeout = 90;
                    $mailable->failOnTimeout = true;
                    Mail::to($data['recipient_email'])->queue($mailable->afterCommit());
                }

                return $simulated ? 'Simulation enregistrée : aucun email envoyé.'
                    : 'Demande d’envoi enregistrée avec succès. Votre email sera envoyé automatiquement. Suivez son résultat dans la section « Envoyer un mail ».';
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($activityId !== null) {
                try {
                    app(DossierMailDelivery::class)->queueFailed($activityId);
                } catch (\Throwable $trackingException) {
                    report($trackingException);
                }
            }
            report($exception);
            throw ValidationException::withMessages(['recipient_email' => 'La demande d’envoi n’a pas pu être enregistrée. Aucun succès d’envoi n’est confirmé.']);
        }
    }

    private function template(array $data): MailTemplate
    {
        $source = empty($data['mail_template_id']) ? null : $this->templates()->firstWhere('id', (int) $data['mail_template_id']);
        if (! empty($data['mail_template_id']) && ! $source) {
            throw ValidationException::withMessages(['mail_template_id' => config('crm.v2_enabled')
                ? 'Ce modèle n’est plus actif.' : 'Ce modèle ou son expéditeur n’est plus actif.']);
        }
        $template = new MailTemplate(['name' => 'Email dossier', 'subject' => $data['subject'],
            'markdown_body' => $data['markdown_body'], 'mail_sender_id' => $source?->mail_sender_id,
            'logo_path' => $source?->logo_path, 'is_active' => true]);
        $template->setRelation('sender', $source?->sender);

        return $template;
    }

    private function variables(Dossier $dossier, User $actor): array
    {
        $appointment = $dossier->appointments()->with('technician', 'service', 'creator')->latest('starts_at')->first();
        $dossier->loadMissing('operations.service');

        return [
            ...($appointment ? app(AppointmentMailTemplateData::class)->forAppointment($appointment) : array_fill_keys(array_keys(app(MailTemplateRenderer::class)->sampleData()), '')),
            'app_name' => config('app.name'), 'client_name' => $dossier->beneficiaryLabel(), 'customer_name' => $dossier->beneficiaryLabel(),
            'customer_email' => $dossier->email ?: '', 'customer_phone' => $dossier->phone ?: '',
            'customer_first_name' => $dossier->first_name ?: '', 'customer_last_name' => $dossier->last_name ?: '',
            'company_name' => $dossier->company_name ?: '', 'site_name' => $dossier->site_name ?: '',
            'service_name' => $dossier->operations->map(fn ($op) => $op->service?->name ?: $op->legacy_service_name)->filter()->implode(', '),
            'address' => trim($dossier->address.' '.$dossier->postal_code.' '.$dossier->city), 'manager_name' => $actor->full_name,
            // Appointment notes are internal, never interpolate them into beneficiary emails.
            'comment' => '',
        ];
    }
}
