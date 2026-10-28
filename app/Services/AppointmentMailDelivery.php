<?php

namespace App\Services;

use App\Mail\MailTemplateMailable;
use App\Models\Appointment;
use App\Models\AppointmentMailDelivery as Delivery;
use App\Models\User;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

final class AppointmentMailDelivery
{
    public function queue(User $actor, Appointment $appointment, string $recipient, MailTemplateMailable $mail): array
    {
        $delivery = null;
        try {
            DB::transaction(function () use ($actor, $appointment, $recipient, $mail, &$delivery): void {
                $simulated = $this->disabled();
                $delivery = Delivery::create(['appointment_id' => $appointment->id, 'author_id' => $actor->id,
                    'recipient' => $recipient, 'subject' => mb_substr($mail->renderedSubject, 0, 500),
                    'status' => $simulated ? 'simulated' : 'queued']);
                if (! $simulated) {
                    $mail->appointmentMailDeliveryId = $delivery->id;
                    $mail->tries = 1;
                    $mail->timeout = 90;
                    $mail->failOnTimeout = true;
                    Mail::to($recipient)->queue($mail->afterCommit());
                }
            });
        } catch (Throwable $exception) {
            if ($delivery?->exists) {
                $this->finish($delivery->id, 'failed', 'queue_failed');
            }
            report($exception);
            throw ValidationException::withMessages(['recipient_email' => 'La demande d’envoi n’a pas pu être enregistrée. Aucun succès d’envoi n’est confirmé.']);
        }

        return ['message' => $delivery->status === 'simulated' ? 'Simulation enregistrée : aucun email envoyé.'
            : 'Demande d’envoi enregistrée avec succès. Votre email sera envoyé automatiquement. Le résultat apparaît dans le suivi ci-dessous.',
            'messages' => $this->recent($appointment)];
    }

    public function deliver(int $id, callable $send): ?SentMessage
    {
        $claimed = DB::transaction(function () use ($id): bool {
            $delivery = Delivery::query()->lockForUpdate()->find($id);
            if ($delivery?->status !== 'queued') {
                return false;
            }
            $delivery->update(['status' => 'sending', 'started_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return null;
        }
        try {
            app(GlobalMailConfiguration::class)->refresh();
            if ($this->disabled()) {
                $this->finish($id, 'blocked', 'delivery_disabled');

                return null;
            }
            $result = $send();
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            $refused = $exception instanceof TransportExceptionInterface && $code >= 400 && $code <= 599;
            $this->finish($id, $refused ? 'failed' : 'unknown', $refused ? 'smtp_refused' : 'delivery_unconfirmed');
            throw $exception;
        }
        $this->finish($id, $result instanceof SentMessage ? 'sent' : 'blocked', $result instanceof SentMessage ? null : 'delivery_cancelled');

        return $result;
    }

    public function failed(int $id): void
    {
        $this->finish($id, 'unknown', 'delivery_unconfirmed');
    }

    private function finish(int $id, string $status, ?string $reason = null): void
    {
        Delivery::query()->whereKey($id)->whereIn('status', ['queued', 'sending'])
            ->update(['status' => $status, 'failure_reason' => $reason, 'finished_at' => now(), 'updated_at' => now()]);
    }

    private function disabled(): bool
    {
        return ! config('mail.delivery_enabled') || config('simulation.enabled') || app()->environment('demo')
            || in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function recent(Appointment $appointment): array
    {
        return Delivery::query()->where('appointment_id', $appointment->id)->latest('id')->limit(10)->get()
            ->map(function (Delivery $delivery): array {
                $status = $delivery->status === 'sending' && $delivery->started_at?->lt(now()->subMinutes(3)) ? 'unknown' : $delivery->status;
                [$label, $detail] = match ($status) {
                    'queued' => ['En attente d’envoi', 'Demande enregistrée. L’envoi sera traité automatiquement.'],
                    'sending' => ['Envoi en cours', 'Le service mail traite votre demande.'],
                    'sent' => ['Envoyé', 'Accepté par le serveur mail. La réception et la lecture ne sont pas confirmées.'],
                    'failed' => ['Échec d’envoi', $delivery->failure_reason === 'queue_failed' ? 'La demande n’a pas pu être transmise au service d’envoi.' : 'Le serveur mail a refusé l’envoi.'],
                    'blocked' => ['Envoi bloqué', 'L’envoi a été empêché par la configuration ou un contrôle de sécurité.'],
                    'simulated' => ['Simulation sans envoi', 'Aucun email réel n’a été envoyé.'],
                    default => ['Envoi non confirmé', 'Le résultat ne peut pas être confirmé. Vérifiez avant de renvoyer pour éviter un doublon.'],
                };

                return ['id' => $delivery->id, 'status' => $status, 'label' => $label, 'detail' => $detail,
                    'recipient' => $delivery->recipient, 'subject' => $delivery->subject,
                    'at' => ($delivery->finished_at ?? $delivery->created_at)->format('d/m/Y H:i'),
                    'pending' => in_array($status, ['queued', 'sending'], true)];
            })->all();
    }
}
