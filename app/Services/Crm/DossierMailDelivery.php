<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Services\GlobalMailConfiguration;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

final class DossierMailDelivery
{
    public const KINDS = ['mail_queued', 'mail_sent', 'mail_failed', 'mail_unknown', 'mail_blocked', 'mail_simulated'];

    private const LABELS = [
        'queued' => 'En attente d’envoi', 'sending' => 'Envoi en cours',
        'sent' => 'Envoyé', 'failed' => 'Échec d’envoi', 'unknown' => 'Envoi non confirmé',
        'blocked' => 'Envoi bloqué', 'simulated' => 'Simulation sans envoi',
        'legacy' => 'Ancien envoi : résultat non disponible',
    ];

    public function deliver(int $activityId, callable $send): ?SentMessage
    {
        $claimed = DB::transaction(function () use ($activityId): bool {
            $activity = DossierActivity::query()->whereIn('kind', self::KINDS)->lockForUpdate()->find($activityId);
            $fields = $activity?->changed_fields ?? [];
            if (! $activity || ($fields['delivery_status'] ?? null) !== 'queued') {
                return false;
            }
            $fields['delivery_status'] = 'sending';
            $fields['started_at'] = now()->toIso8601String();
            $activity->forceFill(['changed_fields' => $fields])->save();

            return true;
        });
        // A retry or a duplicate queue reservation must never submit the same email twice.
        if (! $claimed) {
            return null;
        }
        try {
            app(GlobalMailConfiguration::class)->refresh();
            if (! config('mail.delivery_enabled') || config('simulation.enabled') || app()->environment('demo')
                || in_array(config('mail.default'), ['log', 'array'], true)) {
                $this->finish($activityId, 'blocked', 'delivery_disabled');

                return null;
            }
            $result = $send();
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            $refused = $exception instanceof TransportExceptionInterface && $code >= 400 && $code <= 599;
            $this->finish($activityId, $refused ? 'failed' : 'unknown', $refused ? 'smtp_refused' : 'delivery_unconfirmed');
            throw $exception;
        }
        if (! $result instanceof SentMessage) {
            $this->finish($activityId, 'blocked', 'delivery_cancelled');

            return null;
        }
        // Keep this outside the transport catch: a DB failure after SMTP acceptance is not a transport rejection.
        $this->finish($activityId, 'sent');

        return $result;
    }

    public function failed(int $activityId, ?Throwable $exception = null): void
    {
        $this->finish($activityId, 'unknown', 'delivery_unconfirmed');
    }

    public function queueFailed(int $activityId): void
    {
        $this->finish($activityId, 'failed', 'queue_failed');
    }

    private function finish(int $activityId, string $status, ?string $reason = null): void
    {
        DB::transaction(function () use ($activityId, $status, $reason): void {
            $activity = DossierActivity::query()->whereIn('kind', self::KINDS)->lockForUpdate()->find($activityId);
            $fields = $activity?->changed_fields ?? [];
            if (! $activity || ! in_array($fields['delivery_status'] ?? null, ['queued', 'sending'], true)) {
                return;
            }
            $fields['delivery_status'] = $status;
            $fields['finished_at'] = now()->toIso8601String();
            $fields['failure_reason'] = $reason;
            $activity->forceFill(['kind' => 'mail_'.$status, 'changed_fields' => $fields,
                'body' => self::LABELS[$status].' à '.($fields['recipient'] ?? '').' : '.($fields['subject'] ?? '')])->save();
        });
    }

    public function recent(Dossier $dossier): array
    {
        return $dossier->activities()->whereIn('kind', self::KINDS)->latest('id')->limit(10)
            ->get(['id', 'kind', 'created_at', 'changed_fields->delivery_status as mail_status',
                'changed_fields->recipient as mail_recipient', 'changed_fields->subject as mail_subject',
                'changed_fields->started_at as mail_started', 'changed_fields->finished_at as mail_finished',
                'changed_fields->failure_reason as mail_failure'])->map(fn ($activity) => $this->present($activity))->all();
    }

    private function present(DossierActivity $activity): array
    {
        $fields = ['delivery_status' => $activity->mail_status, 'recipient' => $activity->mail_recipient,
            'subject' => $activity->mail_subject, 'started_at' => $activity->mail_started,
            'finished_at' => $activity->mail_finished, 'failure_reason' => $activity->mail_failure];
        $status = $activity->kind === 'mail_simulated' ? 'simulated' : ($fields['delivery_status'] ?? 'legacy');
        if (! isset(self::LABELS[$status])) {
            $status = 'legacy';
        }
        if ($status === 'sending' && isset($fields['started_at']) && Carbon::parse($fields['started_at'])->lt(now()->subMinutes(3))) {
            $status = 'unknown';
        }
        $detail = match ($status) {
            'queued' => 'Demande enregistrée. L’envoi sera traité automatiquement.',
            'sending' => 'Le service mail traite votre demande.',
            'sent' => 'Accepté par le serveur mail. La réception et la lecture ne sont pas confirmées.',
            'failed' => ($fields['failure_reason'] ?? '') === 'queue_failed' ? 'La demande n’a pas pu être transmise au service d’envoi.' : 'Le serveur mail a refusé l’envoi.',
            'unknown' => 'Le résultat ne peut pas être confirmé. Vérifiez avant de renvoyer pour éviter un doublon.',
            'blocked' => 'L’envoi a été empêché par la configuration ou un contrôle de sécurité.',
            'simulated' => 'Aucun email réel n’a été envoyé.',
            default => 'Cette demande précède le suivi des résultats. Son envoi ne peut pas être vérifié ici.',
        };
        $at = isset($fields['finished_at']) ? Carbon::parse($fields['finished_at']) : $activity->created_at;

        return ['id' => $activity->id, 'status' => $status, 'label' => self::LABELS[$status], 'detail' => $detail,
            'recipient' => (string) ($fields['recipient'] ?? ''), 'subject' => (string) ($fields['subject'] ?? ''),
            'at' => $at?->format('d/m/Y H:i'), 'pending' => in_array($status, ['queued', 'sending'], true)];
    }
}
