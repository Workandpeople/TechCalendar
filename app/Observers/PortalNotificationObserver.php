<?php

namespace App\Observers;

use App\Models\DossierActivity;
use App\Models\DossierDocument;
use App\Models\DossierOperation;
use App\Services\Crm\DossierStatusCatalog;
use App\Services\Crm\PortalNotifications;

class PortalNotificationObserver
{
    public function created(DossierActivity|DossierDocument|DossierOperation $model): void
    {
        $this->schedule($model);
    }

    public function updated(DossierActivity|DossierDocument|DossierOperation $model): void
    {
        if ($model instanceof DossierOperation) {
            if ($model->wasChanged('legacy_status') && DossierStatusCatalog::describe($model->getRawOriginal('legacy_status'))['key']
                !== DossierStatusCatalog::describe($model->legacy_status)['key']) {
                $this->schedule($model);
            }

            return;
        }
        $audiences = [];
        foreach (['client_visible' => 'client', 'delegataire_visible' => 'delegataire'] as $column => $kind) {
            if ($model->wasChanged($column) && $model->{$column}) {
                $audiences[] = $kind;
            }
        }
        if ($audiences !== []) {
            $this->schedule($model, $audiences);
        }
    }

    private function schedule(DossierActivity|DossierDocument|DossierOperation $model, ?array $audiences = null): void
    {
        $status = $model instanceof DossierOperation ? DossierStatusCatalog::describe($model->legacy_status)['key'] : null;
        $event = match (true) {
            $model instanceof DossierOperation && $status !== 'unknown' => 'status_changed',
            $model instanceof DossierDocument && ($model->client_visible || $model->delegataire_visible) => 'document_added',
            $model instanceof DossierActivity && in_array($model->kind, ['comment', 'post_visit'], true)
                && ! $model->hidden_at && ($model->client_visible || $model->delegataire_visible) => 'comment_added',
            default => null,
        };
        if ($event) {
            app(PortalNotifications::class)->schedule($event, (int) $model->dossier_id, $model->id, $status,
                $model instanceof DossierOperation ? auth()->id() : $model->author_id, $audiences);
        }
    }
}
