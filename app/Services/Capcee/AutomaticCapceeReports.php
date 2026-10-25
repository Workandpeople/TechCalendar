<?php

namespace App\Services\Capcee;

use App\Jobs\PrepareAutomaticCapceeReport;
use App\Models\CapceeAutomaticSubmission;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\DossierOperation;
use App\Models\ExternalDelegataire;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AutomaticCapceeReports
{
    public static function targetRules(): array
    {
        return ['capcee_emmy_reference' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'capcee_object_type' => ['required', Rule::in(['Operation__c', 'Action__c'])],
            'capcee_inspection_type' => ['required', Rule::in(['site', 'contact'])]];
    }

    /** Only a newly validated report creates an intent. Enabling an organisation never replays its archives. */
    public function capture(User $actor, Dossier $dossier, DossierOperation $operation): void
    {
        $organisation = $dossier->delegataire_id ? ExternalDelegataire::query()->find($dossier->delegataire_id) : null;
        if (! $organisation?->capcee_enabled || $organisation->kind !== 'delegataire' || ! $operation->control_validated_at) {
            return;
        }
        $document = $operation->reportDocument;
        if (! $document || $document->document_type !== 'report') {
            return;
        }
        $submission = CapceeAutomaticSubmission::firstOrCreate(['dossier_document_id' => $document->id], [
            'delegataire_id' => $organisation->id, 'requested_by' => $actor->id,
            'environment' => config('capcee.environment'), 'submission_token' => (string) Str::uuid(),
            'block_reason' => $this->missingTarget($document) ? 'metadata' : 'configuration',
        ]);
        $this->dispatch($submission);
    }

    public function dispatch(CapceeAutomaticSubmission $submission): void
    {
        if ($submission->capcee_delivery_id || $this->missingTarget($submission->document)) {
            return;
        }
        try {
            if (app(CapceeReports::class)->availability() !== null) {
                return;
            }
            PrepareAutomaticCapceeReport::dispatch($submission->id)->onConnection(config('capcee.queue_connection'))
                ->onQueue(config('capcee.queue'))->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('CAPCEE : preparation automatique conservee, queue indisponible.', ['submission_id' => $submission->id, 'exception' => $exception::class]);
        }
    }

    public function updateTarget(User $actor, Dossier $dossier, CapceeAutomaticSubmission $submission, array $data): void
    {
        Gate::forUser($actor)->authorize('transmitReport', $dossier);
        DB::transaction(function () use ($actor, $dossier, $submission, $data): void {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            Gate::forUser($actor)->authorize('transmitReport', $locked);
            $submission = CapceeAutomaticSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $document = $submission->document;
            abort_unless($document && $document->dossier_id === $locked->id, 404);
            abort_if($submission->version !== (int) $data['version'], 409, 'Ce suivi a changé. Actualise la fiche.');
            if ($submission->capcee_delivery_id) {
                throw ValidationException::withMessages(['capcee' => 'Une transmission existe déjà. Utilise son suivi ou une nouvelle version du rapport.']);
            }
            $target = Validator::make($data, self::targetRules())->validate();
            $document->update($target);
            $submission->update(['block_reason' => 'configuration', 'version' => $submission->version + 1]);
            $locked->activities()->create(['author_id' => $actor->id, 'kind' => 'capcee',
                'body' => 'Identifiants CAPCEE complétés pour le rapport #'.$document->id.'. Email bénéficiaire désactivé.',
                'changed_fields' => ['capcee_automatic_submission_id' => $submission->id]]);
            $this->dispatch($submission->fresh());
        });
    }

    public function prepare(int $id): void
    {
        $initial = CapceeAutomaticSubmission::find($id);
        if (! $initial || $initial->capcee_delivery_id) {
            return;
        }
        try {
            DB::transaction(function () use ($initial): void {
                // Same order as report validation/deletion: parent, operation, intent, delivery.
                $document = $initial->document;
                $dossier = $document ? Dossier::withTrashed()->lockForUpdate()->find($document->dossier_id) : null;
                $operation = $document ? DossierOperation::query()->lockForUpdate()->find($document->dossier_operation_id) : null;
                $submission = CapceeAutomaticSubmission::query()->lockForUpdate()->findOrFail($initial->id);
                if ($submission->capcee_delivery_id) {
                    return;
                }
                $document = $submission->document()->first();
                if (! $document || ! $dossier || $dossier->trashed() || ! $operation || ! $operation->control_validated_at
                    || $operation->report_document_id !== $document->id || $document->document_type !== 'report'
                    || $document->mime_type !== 'application/pdf' || ! Storage::disk('local')->exists($document->path)) {
                    $submission->update(['block_reason' => 'document']);

                    return;
                }
                $organisation = ExternalDelegataire::query()->lockForUpdate()->find($dossier->delegataire_id);
                $actor = User::query()->find($submission->requested_by);
                $block = match (true) {
                    ! $organisation || $organisation->id !== $submission->delegataire_id || $organisation->kind !== 'delegataire'
                        || ! $organisation->is_active || ! $organisation->capcee_enabled => 'delegataire',
                    ! $actor || ! Gate::forUser($actor)->allows('transmitReport', $dossier) => 'actor',
                    $submission->environment !== config('capcee.environment') => 'environment',
                    $this->missingTarget($document) => 'metadata',
                    app(CapceeReports::class)->availability() !== null => 'configuration',
                    default => null,
                };
                if ($block !== null) {
                    $submission->update(['block_reason' => $block]);

                    return;
                }
                $data = ['submission_token' => $submission->submission_token,
                    'emmy_reference' => $document->capcee_emmy_reference, 'object_type' => $document->capcee_object_type,
                    'inspection_type' => $document->capcee_inspection_type, 'issued_on' => $document->issued_on?->toDateString(),
                    'inspected_on' => $document->inspected_on?->toDateString(), 'send_email' => false,
                    'conclusion' => match ($document->control_outcome) {
                        'satisfactory' => 'Satisfaisant', 'unsatisfactory' => 'NonSatisfaisant', 'unverifiable' => 'NonVerifiable', default => 'Autre',
                    }];
                $report = $operation->capceeReports()->where('environment', $submission->environment)
                    ->where('inspection_type', $document->capcee_inspection_type)->first();
                $latest = $report?->latestDelivery;
                // A duplicate local PDF can share the existing delivery, never create a second external report.
                $sameTarget = $report && $report->emmy_reference === $data['emmy_reference'] && $report->object_type === $data['object_type'];
                $metadata = Arr::only($data, ['issued_on', 'inspected_on', 'conclusion', 'send_email']);
                if ($sameTarget && $latest && $latest->sha256 === hash_file('sha256', Storage::disk('local')->path($document->path))
                    && Arr::sortRecursive(Arr::except($latest->metadata, 'reason_for_change')) === Arr::sortRecursive($metadata)) {
                    $submission->update(['capcee_delivery_id' => $latest->id, 'block_reason' => null]);

                    return;
                }
                if ($report && (! $sameTarget || ! $latest || $latest->status !== 'sent' || ! $report->document_id)) {
                    $submission->update(['block_reason' => 'previous_delivery']);

                    return;
                }
                if ($report) {
                    $data['reason_for_change'] = 'Rapport corrigé et validé dans Cofrac 2.';
                }
                $file = new UploadedFile(Storage::disk('local')->path($document->path), 'rapport.pdf', 'application/pdf', null, true);
                $delivery = app(CapceeReports::class)->submit($actor, $dossier, $operation, $data, $file, $report);
                $submission->update(['capcee_delivery_id' => $delivery->id, 'block_reason' => null]);
            });
        } catch (Throwable $exception) {
            CapceeAutomaticSubmission::whereKey($id)->whereNull('capcee_delivery_id')->update(['block_reason' => 'preparation']);
            Log::warning('CAPCEE : preparation automatique interrompue, rapport local conserve.', ['submission_id' => $id, 'exception' => $exception::class]);
        }
    }

    private function missingTarget(?DossierDocument $document): bool
    {
        return ! $document || Validator::make($document->only(array_keys(self::targetRules())), self::targetRules())->fails();
    }
}
