<?php

namespace App\Services\Crm;

use App\Jobs\SendPortalNotification;
use App\Mail\PortalNotificationMail;
use App\Models\Dossier;
use App\Models\DossierActivity;
use App\Models\DossierDocument;
use App\Models\DossierOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PortalNotifications
{
    public function __construct(private readonly ClientAccess $access, private readonly PortalNotificationSettings $settings) {}

    public function enabled(): bool
    {
        return config('crm.v2_enabled') && config('mail.delivery_enabled') && ! config('simulation.enabled')
            && ! app()->environment('demo') && ! in_array(config('mail.default'), ['log', 'null'], true);
    }

    public function schedule(string $event, int $dossierId, int $sourceId, ?string $status = null, ?int $actorId = null, ?array $audiences = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        // Observe native writes only. Imports using the query builder do not replay notifications.
        DB::afterCommit(function () use ($event, $dossierId, $sourceId, $status, $actorId, $audiences): void {
            try {
                if (! $this->enabled() || ! ($dossier = Dossier::find($dossierId))) {
                    return;
                }
                $organisationIds = array_values(array_filter([$dossier->client_id, $dossier->delegataire_id]));
                if ($organisationIds === []) {
                    return;
                }
                $recipients = User::query()->where('role', User::ROLE_EXTERNAL)->where('admin', false)
                    ->where('portal_enabled', true)->where('must_change_password', false)
                    ->whereIn('external_client_id', $organisationIds)->whereNotNull('portal_notification_preferences')
                    ->when($actorId, fn ($query) => $query->whereKeyNot($actorId))->with('externalClient')->get();
                foreach ($recipients as $recipient) {
                    if (($audiences === null || in_array($recipient->externalClient?->kind, $audiences, true))
                        && $this->context($recipient, $event, $dossierId, $sourceId, $status)) {
                        SendPortalNotification::dispatch($recipient->id, $event, $dossierId, $sourceId, $status);
                    }
                }
            } catch (Throwable $exception) {
                // A notification failure must not fail an already committed business action.
                Log::warning('Portal notification scheduling failed.', ['event' => $event, 'exception_type' => $exception::class]);
            }
        });
    }

    public function deliver(int $userId, string $event, int $dossierId, int $sourceId, ?string $status): void
    {
        $recipient = User::query()->with('externalClient')->find($userId);
        if (! $recipient || ! ($context = $this->context($recipient, $event, $dossierId, $sourceId, $status))) {
            return;
        }

        // Recheck live preferences, assignment and visibility in the worker, not just at dispatch.
        Mail::to($recipient->email)->send(new PortalNotificationMail($context));
    }

    public function context(User $recipient, string $event, int $dossierId, int $sourceId, ?string $status): ?array
    {
        $preferences = $this->settings->values($recipient);
        if (! $this->enabled() || ! $preferences['email_enabled'] || ! $this->access->active($recipient)
            || $recipient->must_change_password || ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $dossier = Dossier::find($dossierId);
        if (! $dossier || ! $this->access->viewDossier($recipient, $dossier)) {
            return null;
        }

        if ($event === 'status_changed') {
            $operation = DossierOperation::where('dossier_id', $dossierId)->find($sourceId);
            if (! $operation || DossierStatusCatalog::describe($operation->legacy_status)['key'] !== $status
                || ! in_array($status, $preferences['statuses'], true)
                || ! array_key_exists($status, $this->settings->categories($recipient))) {
                return null;
            }
            $title = 'Changement de statut';
            $description = 'Le dossier est maintenant dans la catégorie « '.DossierStatusCatalog::STATUSES[$status]['label'].' ».';
        } elseif ($event === 'comment_added') {
            $comment = DossierActivity::where('dossier_id', $dossierId)->whereIn('kind', DossierActivity::COMMENT_KINDS)->find($sourceId);
            if (! $preferences['comment_added'] || ! $this->access->allows($recipient, 'comments') || ! $comment
                || ! $this->access->commentVisibleToClient($comment, $recipient->externalClient->kind)) {
                return null;
            }
            $title = 'Nouveau commentaire';
            $description = 'Un commentaire partagé avec vous est disponible sur ce dossier.';
        } elseif ($event === 'document_added') {
            $document = DossierDocument::where('dossier_id', $dossierId)->find($sourceId);
            if (! $preferences['document_added'] || ! $this->access->allows($recipient, 'documents') || ! $document
                || ! $document->{$this->access->audienceColumn($recipient)}) {
                return null;
            }
            $title = 'Nouveau document';
            $description = 'Un document partagé avec vous est disponible sur ce dossier.';
        } else {
            return null;
        }

        // No comment body, attachment or private technical result is copied into the email.
        return ['title' => $title, 'description' => $description, 'recipientName' => $recipient->full_name,
            'dossierLabel' => $dossier->beneficiaryLabel(), 'url' => route('client.dossiers.show', $dossier)];
    }
}
