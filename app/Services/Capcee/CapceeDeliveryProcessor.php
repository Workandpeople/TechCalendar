<?php

namespace App\Services\Capcee;

use App\Models\CapceeDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class CapceeDeliveryProcessor
{
    public const LEASE_SECONDS = 600;

    public function __construct(private CapceeClient $client) {}

    public function process(int $deliveryId): void
    {
        $delivery = CapceeDelivery::query()->find($deliveryId);
        if (! $delivery || ! $delivery->active()) {
            return;
        }
        $lock = Cache::lock('capcee:report:'.$delivery->capcee_report_id, self::LEASE_SECONDS);
        if (! $lock->get()) {
            return;
        }
        try {
            $delivery->refresh()->load('report.operation.dossier');
            if (! $delivery->active() || $delivery->next_attempt_at?->isFuture()) {
                return;
            }
            if ($delivery->status === 'processing' && $delivery->started_at?->gt(now()->subSeconds(self::LEASE_SECONDS))) {
                return;
            }
            if (in_array($delivery->stage, ['uploading', 'linking'], true)) {
                $this->recordFailure($delivery, new CapceeApiException('Le worker a été interrompu pendant une écriture CAPCEE. Vérification distante nécessaire ; aucun renvoi automatique.', outcomeUnknown: true));

                return;
            }
            $delivery->update(['status' => 'processing', 'started_at' => now(), 'next_attempt_at' => null,
                'attempts' => $delivery->attempts + 1, 'last_error' => null]);
            Log::info('CAPCEE : tentative démarrée.', ['delivery_id' => $delivery->id, 'attempt' => $delivery->attempts, 'stage' => $delivery->stage]);
            try {
                app(CapceeReports::class)->assertReady();
                if ($delivery->report->environment !== config('capcee.environment')) {
                    throw new CapceeApiException('L’environnement CAPCEE a changé depuis la validation. Rétablis l’environnement initial avant de reprendre.');
                }
                $this->deliver($delivery);
            } catch (Throwable $exception) {
                $this->recordFailure($delivery, $exception);
            }
        } finally {
            $lock->release();
        }
    }

    private function deliver(CapceeDelivery $delivery): void
    {
        $report = $delivery->report;
        if ($delivery->stage === 'resolve') {
            $target = $this->client->resolveTarget($report->emmy_reference, $report->object_type);
            if ($report->parent_id && $report->parent_id !== $target['id']) {
                throw new CapceeApiException('La référence EMMY pointe désormais vers une autre cible. Envoi refusé.');
            }
            $report->update(['parent_id' => $target['id']]);
            $delivery->update(['stage' => 'check_report']);
        }
        if ($delivery->stage === 'check_report') {
            $existing = $this->client->findReport('GC_'.$report->reference, $report->parent_id);
            if ($delivery->revision === 1 && $existing) {
                throw new CapceeApiException('Un rapport porte déjà cette référence dans CAPCEE. Vérifie son contenu avant toute nouvelle version.', outcomeUnknown: true);
            }
            if ($delivery->revision > 1 && (! $existing || $existing['document_id'] !== $report->document_id)) {
                throw new CapceeApiException('Le document CAPCEE de la version précédente est absent ou a changé. Aucun nouveau document créé.');
            }
            $delivery->update(['stage' => 'upload']);
        }
        if ($delivery->stage === 'upload') {
            $pdf = $this->readPdf($delivery);
            $metadata = [...$delivery->metadata,
                'emmy_reference' => $report->emmy_reference, 'report_reference' => $report->reference,
                'object_type' => $report->object_type, 'inspection_type' => $report->inspection_type];
            // Persist the intent BEFORE a non-idempotent POST. A killed worker must not repeat it.
            $delivery->update(['stage' => 'uploading']);
            $versionId = $this->client->createReportVersion($metadata, $pdf, $report->parent_id, $delivery->revision > 1 ? $report->document_id : null);
            unset($pdf);
            $delivery->update(['version_id' => $versionId, 'stage' => 'document']);
        }
        if ($delivery->stage === 'document') {
            $documentId = $this->client->documentIdForVersion($delivery->version_id);
            if ($report->document_id && $report->document_id !== $documentId) {
                throw new CapceeApiException('La nouvelle version est liée à un document inattendu. Vérification manuelle requise.', outcomeUnknown: true);
            }
            $report->update(['document_id' => $documentId]);
            $delivery->update(['document_id' => $documentId, 'stage' => 'link']);
        }
        if ($delivery->stage === 'link') {
            $linkId = $this->client->findDocumentLink($delivery->document_id, $report->parent_id);
            if (! $linkId) {
                $delivery->update(['stage' => 'linking']);
                $linkId = $this->client->linkDocument($delivery->document_id, $report->parent_id);
            }
            $delivery->update(['link_id' => $linkId, 'stage' => 'done', 'status' => 'sent', 'sent_at' => now(), 'last_error' => null]);
            $this->audit($delivery, 'Rapport envoyé et rattaché à sa cible CAPCEE.');
            Log::info('CAPCEE : livraison terminée.', ['delivery_id' => $delivery->id, 'version_id' => $delivery->version_id, 'document_id' => $delivery->document_id]);
        }
    }

    private function readPdf(CapceeDelivery $delivery): string
    {
        if (! str_starts_with($delivery->path, 'crm/capcee/'.$delivery->capcee_report_id.'/')
            || str_contains($delivery->path, '..') || ! Storage::disk('local')->exists($delivery->path)
            || Storage::disk('local')->size($delivery->path) !== $delivery->size
            || $delivery->size > CapceeReportPayload::MAX_PDF_BYTES) {
            throw new CapceeApiException('Le PDF validé est absent ou a été modifié. Aucun document envoyé.');
        }
        $limit = ini_parse_quantity(ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $delivery->size * 6 + 8 * 1024 * 1024 > $limit) {
            throw new CapceeApiException('Mémoire du worker insuffisante pour ce PDF. Augmente sa limite mémoire avant de reprendre.');
        }
        $pdf = Storage::disk('local')->get($delivery->path);
        if (! is_string($pdf) || ! hash_equals($delivery->sha256, hash('sha256', $pdf))) {
            throw new CapceeApiException('L’empreinte du PDF ne correspond plus au rapport validé. Aucun document envoyé.');
        }

        return $pdf;
    }

    private function recordFailure(CapceeDelivery $delivery, Throwable $exception): void
    {
        if ($delivery->status === 'sent') {
            Log::warning('CAPCEE : livraison confirmée, historique indisponible.', ['delivery_id' => $delivery->id, 'exception' => $exception::class]);

            return;
        }
        $writing = in_array($delivery->stage, ['uploading', 'linking'], true);
        $unknown = $exception instanceof CapceeApiException ? $exception->outcomeUnknown : $writing && ! $exception instanceof ValidationException;
        $retryable = $exception instanceof CapceeApiException && $exception->retryable && ! $unknown;
        $message = $exception instanceof CapceeApiException ? $exception->getMessage()
            : ($exception instanceof ValidationException ? 'Les métadonnées ou le PDF ne respectent pas le contrat CAPCEE.' : 'Erreur interne de transmission. Consulter le journal avec la référence de cet envoi.');
        $stage = $delivery->stage;
        if (! $unknown && $writing) {
            $stage = $stage === 'uploading' ? 'upload' : 'link';
        }
        $status = $unknown ? 'review' : ($retryable && $delivery->attempts < 5 ? 'queued' : 'failed');
        $delivery->update(['status' => $status, 'stage' => $stage, 'last_error' => $message,
            'next_attempt_at' => $status === 'queued' ? now()->addSeconds(min(900, 60 * max(1, $delivery->attempts))) : null]);
        $this->audit($delivery, CapceeDelivery::LABELS[$status].' : '.$message);
        Log::warning('CAPCEE : tentative interrompue.', [
            'delivery_id' => $delivery->id, 'attempt' => $delivery->attempts, 'stage' => $stage,
            'status' => $status, 'http_status' => $exception instanceof CapceeApiException ? $exception->httpStatus : null,
            'exception' => $exception::class,
        ]);
    }

    private function audit(CapceeDelivery $delivery, string $message): void
    {
        $delivery->report->operation->dossier->activities()->create([
            'kind' => 'capcee', 'body' => 'CAPCEE #'.$delivery->id.' · V'.$delivery->revision.' · '.$message,
            'changed_fields' => ['capcee_delivery_id' => $delivery->id, 'attempt' => $delivery->attempts, 'stage' => $delivery->stage],
        ]);
    }
}
