<?php

namespace App\Services\Tech;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\Crm\AppointmentDateTime;
use App\Services\Crm\ContentAudience;
use App\Services\Crm\DossierWorkflow;
use App\Support\CommentContent;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class TechnicianOnSiteReports
{
    public const REASONS = [
        'absent' => 'Client absent',
        'refused' => 'Client refuse le rendez-vous',
        'forgotten' => 'Client avait oublié le rendez-vous',
        'unreachable' => 'Client injoignable sur place',
        'access' => 'Accès au site impossible',
        'other' => 'Autre problème sur place',
    ];

    public function __construct(
        private readonly OnSitePhoto $photos,
        private readonly DossierWorkflow $workflow,
        private readonly OnSiteVisitContext $contexts,
    ) {}

    public function snapshot(Appointment $appointment): string
    {
        return $this->contexts->fingerprint($appointment);
    }

    public function submit(User $actor, Appointment $appointment, array $data, UploadedFile $photo): array
    {
        $evidenceOnly = (bool) ($data['evidence_only'] ?? false);
        $this->authorizeSubmission($actor, $appointment, $data, $evidenceOnly);
        if ($result = $this->existing($actor, $appointment, $data['request_id'])) {
            return $result;
        }
        if ($evidenceOnly) {
            $this->evidenceDate($actor, $appointment, $data);
        }
        if (! $evidenceOnly) {
            Gate::forUser($actor)->authorize('reportOnSiteProblem', $appointment);
            $this->checkSnapshot($appointment, $data['snapshot']);
        }
        $dossierId = $appointment->dossierOperation->dossier_id;
        $stored = $this->photos->store($photo, $dossierId);
        $used = false;
        try {
            $result = DB::transaction(function () use ($actor, $appointment, $data, $dossierId, $stored, $evidenceOnly, &$used): array {
                $used = false;
                // Same lock order as dossier workflows, then recheck assignment and appointment state.
                $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossierId);
                $operation = $dossier->operations()->lockForUpdate()->findOrFail($appointment->dossier_operation_id);
                $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
                $actor = User::query()->lockForUpdate()->find($actor->id);
                abort_unless($actor, 403);
                $this->authorizeSubmission($actor, $locked, $data, $evidenceOnly);
                if ($result = $this->existing($actor, $locked, $data['request_id'])) {
                    return $result;
                }
                $visitAt = $evidenceOnly ? $this->evidenceDate($actor, $locked, $data) : null;
                if (! $evidenceOnly) {
                    Gate::forUser($actor)->authorize('reportOnSiteProblem', $locked);
                    $this->checkSnapshot($locked, $data['snapshot']);
                }
                if ($locked->dossier_operation_id !== $operation->id) {
                    throw ValidationException::withMessages(['snapshot' => 'Le rattachement du rendez-vous a changé. Rechargez sa fiche.']);
                }
                $reportedAt = now();
                $reason = self::REASONS[$data['reason']];
                $comment = $reason.' · Signalement sur place du '.$reportedAt->format('d/m/Y à H:i').'.';
                if ($evidenceOnly) {
                    if (TechnicianIncident::where('appointment_id', $locked->id)->where('technician_id', $actor->id)
                        ->where('appointment_starts_at', $visitAt->format('Y-m-d H:i:s'))->exists()) {
                        throw ValidationException::withMessages(['visit_date' => 'Un signalement existe déjà pour ce passage. Contactez le planning pour le compléter.']);
                    }
                    $comment = $reason.' · Preuve du passage du '.$visitAt->format('d/m/Y à H:i').'. Reçue le '.$reportedAt->format('d/m/Y à H:i').'. Le rendez-vous actuel reste inchangé.';
                }
                if (filled($data['comment'] ?? null)) {
                    $comment .= "\n".trim($data['comment']);
                }
                $flags = ContentAudience::flags('client');
                if ($evidenceOnly) {
                    $dossier->activities()->create(['author_id' => $actor->id, 'kind' => 'comment',
                        'body' => CommentContent::forStorage(htmlspecialchars($comment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'comment'), ...$flags,
                        'changed_fields' => ['operation_id' => $operation->id, 'appointment_id' => $locked->id,
                            'on_site_evidence' => true, 'visit_at' => $visitAt->toIso8601String()]]);
                    $dossier->increment('version');
                } else {
                    $this->workflow->recordOnSiteProblem($actor, $locked, [
                        'comment' => htmlspecialchars($comment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        'problem_type' => $reason, ...$flags,
                    ]);
                }
                $document = $dossier->documents()->create([
                    ...$stored, ...$flags, 'author_id' => $actor->id, 'document_type' => 'free',
                    'dossier_operation_id' => $operation->id,
                    'name' => ($evidenceOnly ? 'Preuve du passage - '.$visitAt->format('d-m-Y H-i') : 'Photo du signalement RDV - '.$reportedAt->format('d-m-Y H-i')),
                    'description' => $reason.($evidenceOnly ? ' · Ancien RDV du '.$visitAt->format('d/m/Y à H:i') : '').' · Reçue le '.$reportedAt->format('d/m/Y à H:i').'.',
                ]);
                $report = [
                    'request_id' => $data['request_id'], 'author_id' => $actor->id, 'reason' => $data['reason'],
                    'reported_at' => $reportedAt->toIso8601String(), 'document_id' => $document->id,
                    'evidence_only' => $evidenceOnly,
                ];
                TechnicianIncident::create([
                    'request_id' => $data['request_id'], 'dossier_id' => $dossier->id,
                    'appointment_id' => $locked->id, 'technician_id' => $actor->id, 'document_id' => $document->id,
                    'reason' => $data['reason'], 'comment' => trim($data['comment'] ?? ''),
                    'reported_at' => $reportedAt, 'appointment_starts_at' => $visitAt ?? $locked->starts_at,
                    'source_context' => $evidenceOnly ? ['evidence_only' => true, 'visit_at' => $visitAt->toIso8601String(),
                        'date_source' => filled($data['visit_date'] ?? null) ? 'technician_declared' : 'signed_visit_context'] : null,
                ]);
                if (! $evidenceOnly) {
                    $locked->forceFill([
                        'status' => Appointment::STATUS_PROBLEM,
                        'problem_reported_at' => $locked->status === Appointment::STATUS_PROBLEM ? ($locked->problem_reported_at ?? $reportedAt) : $reportedAt,
                        'external_payload' => [...($locked->external_payload ?? []), 'techcalendar_on_site' => $report,
                            'techcalendar_problem' => $locked->status === Appointment::STATUS_PROBLEM && is_array($locked->external_payload['techcalendar_problem'] ?? null)
                                ? $locked->external_payload['techcalendar_problem'] : ['comment' => $comment, 'problem_type' => $reason, ...$flags]],
                    ])->save();
                }
                $used = true;

                return $report;
            }, 3);
        } catch (Throwable $exception) {
            // A late post-commit error must never remove an already attached photograph.
            try {
                if (! DossierDocument::withTrashed()->where('path', $stored['path'])->exists()) {
                    Storage::disk('local')->delete($stored['path']);
                }
            } catch (Throwable) {
                Log::warning('On-site photo retained after uncertain commit.', ['appointment_id' => $appointment->id]);
            }
            throw $exception;
        }
        if (! $used) {
            Storage::disk('local')->delete($stored['path']);
        }

        return $result;
    }

    private function existing(User $actor, Appointment $appointment, string $requestId): ?array
    {
        $incident = TechnicianIncident::where('request_id', $requestId)->where('appointment_id', $appointment->id)
            ->where('technician_id', $actor->id)->first();
        if ($incident) {
            return ['request_id' => $requestId, 'author_id' => $actor->id, 'reason' => $incident->reason,
                'reported_at' => $incident->reported_at->toIso8601String(), 'document_id' => $incident->document_id,
                'evidence_only' => (bool) ($incident->source_context['evidence_only'] ?? false)];
        }
        $report = $appointment->external_payload['techcalendar_on_site'] ?? null;

        return is_array($report) && ($report['author_id'] ?? null) === $actor->id
            && ($report['request_id'] ?? null) === $requestId ? $report : null;
    }

    private function checkSnapshot(Appointment $appointment, string $snapshot): void
    {
        if (! hash_equals($this->snapshot($appointment), $snapshot)) {
            throw ValidationException::withMessages(['snapshot' => 'Ce rendez-vous a été modifié. Pour la preuve de votre ancien passage, choisissez « Preuve d’un ancien passage ». Sinon, rechargez sa fiche.']);
        }
    }

    private function authorizeSubmission(User $actor, Appointment $appointment, array $data, bool $evidenceOnly): void
    {
        $signed = $this->contexts->allowsHistorical($actor, $appointment, $data);
        if (! $evidenceOnly && $signed && $this->contexts->read($actor, $appointment, $data) !== $this->contexts->booking($appointment)) {
            throw ValidationException::withMessages(['snapshot' => 'Le RDV a été replanifié ou réattribué. Choisissez « Preuve d’un ancien passage » pour conserver votre preuve sans modifier le nouveau RDV.']);
        }
        if ($evidenceOnly && $signed) {
            return;
        }
        Gate::forUser($actor)->authorize($evidenceOnly ? 'reportOnSiteEvidence' : 'view', $appointment);
    }

    private function evidenceDate(User $actor, Appointment $appointment, array $data): CarbonImmutable
    {
        if (filled($data['visit_date'] ?? null)) {
            Gate::forUser($actor)->authorize('reportOnSiteEvidence', $appointment);
            $this->checkSnapshot($appointment, $data['snapshot']);
            $date = AppointmentDateTime::parse($data['visit_date'].' '.($data['visit_time'] ?? ''), 'visit_date')->toImmutable();
        } else {
            $booking = $this->contexts->read($actor, $appointment, $data);
            if (! $booking) {
                throw ValidationException::withMessages(['visit_date' => 'Renseignez la date et l’heure réelles de votre ancien passage.']);
            }
            $date = AppointmentDateTime::parse($booking['starts_at'], 'visit_date')->toImmutable();
        }
        if ($date->gt(CarbonImmutable::now('UTC')->setTimezone(AppointmentDateTime::TIMEZONE))
            || $date->toDateString() < $appointment->created_at->format('Y-m-d')) {
            throw ValidationException::withMessages(['visit_date' => 'La preuve doit concerner un passage déjà effectué, après la création du rendez-vous.']);
        }

        return $date;
    }
}
