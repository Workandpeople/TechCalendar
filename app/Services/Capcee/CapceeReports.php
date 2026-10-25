<?php

namespace App\Services\Capcee;

use App\Jobs\SendCapceeReport;
use App\Models\CapceeDelivery;
use App\Models\CapceeReport;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CapceeReports
{
    public function assertReady(): void
    {
        app(CapceeClient::class)->assertConfigured();
        $driver = config('queue.connections.'.config('capcee.queue_connection').'.driver');
        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new CapceeApiException('CAPCEE nécessite une queue persistante (database, redis, sqs ou beanstalkd).');
        }
        $retryAfter = config('queue.connections.'.config('capcee.queue_connection').'.retry_after');
        if ($retryAfter !== null && (int) $retryAfter <= CapceeDeliveryProcessor::LEASE_SECONDS) {
            throw new CapceeApiException('Le retry_after de la queue CAPCEE doit dépasser 600 secondes pour éviter les exécutions concurrentes.');
        }
    }

    public function availability(): ?string
    {
        try {
            $this->assertReady();

            return null;
        } catch (CapceeApiException $exception) {
            return $exception->getMessage();
        }
    }

    public function submit(User $actor, Dossier $dossier, DossierOperation $operation, array $data, UploadedFile $file, ?CapceeReport $report = null): CapceeDelivery
    {
        Gate::forUser($actor)->authorize('transmitReport', $dossier);
        $this->assertScope($dossier, $operation, $report);
        $this->assertReady();
        $size = $file->getSize();
        if ($size > CapceeReportPayload::MAX_PDF_BYTES || $file->getMimeType() !== 'application/pdf'
            || file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-') {
            throw ValidationException::withMessages(['report_file' => 'Le rapport doit être un PDF de 35 Mo maximum.']);
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $metadata = Arr::only($data, ['issued_on', 'inspected_on', 'conclusion', 'send_email', 'reason_for_change']);
        $metadata['send_email'] = (bool) $metadata['send_email'];
        $path = null;
        try {
            $delivery = DB::transaction(function () use ($actor, $dossier, $operation, $data, $file, $report, $metadata, $size, $hash, &$path): CapceeDelivery {
                $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
                Gate::forUser($actor)->authorize('transmitReport', $dossier);
                // Serializes creation and revisions without holding a DB lock during HTTP calls.
                DossierOperation::query()->lockForUpdate()->findOrFail($operation->id);
                $existing = CapceeDelivery::query()->where('submission_token', $data['submission_token'])->first();
                if ($existing) {
                    $sameReport = $report ? $existing->capcee_report_id === $report->id : $existing->revision === 1;
                    if (! $sameReport || $existing->report->dossier_operation_id !== $operation->id
                        || $existing->report->environment !== config('capcee.environment')
                        || $existing->report->emmy_reference !== $data['emmy_reference']
                        || $existing->report->object_type !== $data['object_type']
                        || $existing->report->inspection_type !== $data['inspection_type']
                        || $existing->sha256 !== $hash || Arr::sortRecursive($existing->metadata) !== Arr::sortRecursive($metadata)) {
                        throw ValidationException::withMessages(['submission_token' => 'Ce formulaire a déjà servi pour un autre envoi. Recharge la page.']);
                    }

                    return $existing;
                }
                $revision = 1;
                if ($report) {
                    $report = CapceeReport::query()->lockForUpdate()->findOrFail($report->id);
                    $latest = $report->latestDelivery;
                    if (! $latest || $latest->status !== 'sent' || ! $report->document_id) {
                        throw ValidationException::withMessages(['report_file' => 'Termine l’envoi précédent avant de déposer une nouvelle version.']);
                    }
                    if ($report->environment !== config('capcee.environment') || $report->emmy_reference !== $data['emmy_reference']
                        || $report->object_type !== $data['object_type'] || $report->inspection_type !== $data['inspection_type']) {
                        throw ValidationException::withMessages(['emmy_reference' => 'La cible et l’environnement d’un rapport existant ne peuvent pas être changés.']);
                    }
                    if ($latest->sha256 === $hash && Arr::sortRecursive(Arr::except($latest->metadata, 'reason_for_change')) === Arr::sortRecursive(Arr::except($metadata, 'reason_for_change'))) {
                        throw ValidationException::withMessages(['report_file' => 'Ce PDF et ses métadonnées ont déjà été envoyés.']);
                    }
                    $revision = $latest->revision + 1;
                } else {
                    if ($operation->capceeReports()->where('environment', config('capcee.environment'))->where('inspection_type', $data['inspection_type'])->exists()) {
                        throw ValidationException::withMessages(['inspection_type' => 'Un rapport existe déjà pour ce contrôle. Utilise « Nouvelle version » ou reprends son envoi.']);
                    }
                    $report = $operation->capceeReports()->create([
                        'environment' => config('capcee.environment'), 'reference' => 'TC-'.Str::ulid(),
                        ...Arr::only($data, ['emmy_reference', 'object_type', 'inspection_type']),
                    ]);
                }
                $path = $file->store('crm/capcee/'.$report->id, 'local');
                if (! is_string($path)) {
                    throw ValidationException::withMessages(['report_file' => 'Stockage privé indisponible. Aucun envoi créé.']);
                }
                $delivery = $report->deliveries()->create([
                    'requested_by' => $actor->id, 'submission_token' => $data['submission_token'],
                    'revision' => $revision, 'metadata' => $metadata, 'path' => $path, 'size' => $size, 'sha256' => $hash,
                    'status' => 'queued', 'stage' => 'resolve', 'next_attempt_at' => now(),
                ]);
                $dossier->activities()->create([
                    'author_id' => $actor->id, 'kind' => 'capcee',
                    'body' => 'Rapport CAPCEE GC_'.$report->reference.' version '.$revision.' validé pour transmission ('.$report->environment.').',
                    'changed_fields' => ['capcee_delivery_id' => $delivery->id, 'send_email' => $metadata['send_email']],
                ]);

                return $delivery;
            });
        } catch (Throwable $exception) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        $this->dispatch($delivery);

        return $delivery;
    }

    public function retry(User $actor, Dossier $dossier, CapceeDelivery $delivery): void
    {
        Gate::forUser($actor)->authorize('transmitReport', $dossier);
        abort_unless($delivery->report->operation->dossier_id === $dossier->id, 404);
        $this->assertReady();
        DB::transaction(function () use ($dossier, $actor, $delivery): void {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            Gate::forUser($actor)->authorize('transmitReport', $dossier);
            $locked = CapceeDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($locked->status !== 'failed' || in_array($locked->stage, ['uploading', 'linking'], true)
                || $locked->report->environment !== config('capcee.environment')) {
                throw ValidationException::withMessages(['capcee' => 'Cet envoi ne peut pas être relancé sans vérification. Aucun nouvel envoi effectué.']);
            }
            $locked->update(['status' => 'queued', 'last_error' => null, 'next_attempt_at' => now()]);
            $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'capcee', 'body' => 'Reprise CAPCEE demandée pour l’envoi #'.$locked->id.'.']);
        });
        $this->dispatch($delivery->fresh());
    }

    public function assertScope(Dossier $dossier, DossierOperation $operation, ?CapceeReport $report = null): void
    {
        abort_unless($operation->dossier_id === $dossier->id && (! $report || $report->dossier_operation_id === $operation->id), 404);
    }

    public function dispatch(CapceeDelivery $delivery): void
    {
        if (! $delivery->active()) {
            return;
        }
        try {
            SendCapceeReport::dispatch($delivery->id)->onConnection(config('capcee.queue_connection'))
                ->onQueue(config('capcee.queue'))->afterCommit();
        } catch (Throwable $exception) {
            // The persisted outbox is picked up by capcee:dispatch; never expose queue credentials.
            Log::warning('CAPCEE : livraison persistée, queue indisponible.', ['delivery_id' => $delivery->id, 'exception' => $exception::class]);
        }
    }
}
