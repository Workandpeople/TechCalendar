<?php

namespace App\Services\Tech;

use App\Models\Appointment;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\TechnicianIncident;
use App\Models\User;
use App\Services\Crm\ContentAudience;
use App\Services\Crm\DossierWorkflow;
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

    public function __construct(private readonly OnSitePhoto $photos, private readonly DossierWorkflow $workflow) {}

    public function snapshot(Appointment $appointment): string
    {
        return hash_hmac('sha256', json_encode([
            $appointment->id, $appointment->technician_id, $appointment->dossier_operation_id,
            $appointment->starts_at?->toIso8601String(), $appointment->ends_at?->toIso8601String(),
            $appointment->status, $appointment->updated_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function submit(User $actor, Appointment $appointment, array $data, UploadedFile $photo): array
    {
        Gate::forUser($actor)->authorize('view', $appointment);
        if ($result = $this->existing($actor, $appointment, $data['request_id'])) {
            return $result;
        }
        Gate::forUser($actor)->authorize('reportOnSiteProblem', $appointment);
        $this->checkSnapshot($appointment, $data['snapshot']);
        $dossierId = $appointment->dossierOperation->dossier_id;
        $stored = $this->photos->store($photo, $dossierId);
        $used = false;
        try {
            $result = DB::transaction(function () use ($actor, $appointment, $data, $dossierId, $stored, &$used): array {
                $used = false;
                // Same lock order as dossier workflows, then recheck assignment and appointment state.
                $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossierId);
                $operation = $dossier->operations()->lockForUpdate()->findOrFail($appointment->dossier_operation_id);
                $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
                $actor = User::query()->lockForUpdate()->find($actor->id);
                abort_unless($actor, 403);
                Gate::forUser($actor)->authorize('view', $locked);
                if ($result = $this->existing($actor, $locked, $data['request_id'])) {
                    return $result;
                }
                Gate::forUser($actor)->authorize('reportOnSiteProblem', $locked);
                $this->checkSnapshot($locked, $data['snapshot']);
                if ($locked->dossier_operation_id !== $operation->id) {
                    throw ValidationException::withMessages(['snapshot' => 'Le rattachement du rendez-vous a changé. Rechargez sa fiche.']);
                }
                $reportedAt = now();
                $reason = self::REASONS[$data['reason']];
                $comment = $reason.' · Signalement sur place du '.$reportedAt->format('d/m/Y à H:i').'.';
                if (filled($data['comment'] ?? null)) {
                    $comment .= "\n".trim($data['comment']);
                }
                $flags = ContentAudience::flags('client');
                $this->workflow->recordOnSiteProblem($actor, $locked, [
                    'comment' => htmlspecialchars($comment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'problem_type' => $reason, ...$flags,
                ]);
                $document = $dossier->documents()->create([
                    ...$stored, ...$flags, 'author_id' => $actor->id, 'document_type' => 'free',
                    'dossier_operation_id' => $operation->id,
                    'name' => 'Photo du signalement RDV - '.$reportedAt->format('d-m-Y H-i'),
                    'description' => $reason.' · Reçue le '.$reportedAt->format('d/m/Y à H:i').'.',
                ]);
                $report = [
                    'request_id' => $data['request_id'], 'author_id' => $actor->id, 'reason' => $data['reason'],
                    'reported_at' => $reportedAt->toIso8601String(), 'document_id' => $document->id,
                ];
                TechnicianIncident::create([
                    'request_id' => $data['request_id'], 'dossier_id' => $dossier->id,
                    'appointment_id' => $locked->id, 'technician_id' => $actor->id, 'document_id' => $document->id,
                    'reason' => $data['reason'], 'comment' => trim($data['comment'] ?? ''),
                    'reported_at' => $reportedAt, 'appointment_starts_at' => $locked->starts_at,
                ]);
                $locked->forceFill([
                    'status' => Appointment::STATUS_PROBLEM, 'problem_reported_at' => $reportedAt,
                    'external_payload' => [...($locked->external_payload ?? []), 'techcalendar_on_site' => $report,
                        'techcalendar_problem' => ['comment' => $comment, 'problem_type' => $reason, ...$flags]],
                ])->save();
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
                'reported_at' => $incident->reported_at->toIso8601String(), 'document_id' => $incident->document_id];
        }
        $report = $appointment->external_payload['techcalendar_on_site'] ?? null;

        return is_array($report) && ($report['author_id'] ?? null) === $actor->id
            && ($report['request_id'] ?? null) === $requestId ? $report : null;
    }

    private function checkSnapshot(Appointment $appointment, string $snapshot): void
    {
        if (! hash_equals($this->snapshot($appointment), $snapshot)) {
            throw ValidationException::withMessages(['snapshot' => 'Ce rendez-vous a été modifié. Rechargez sa fiche avant de renvoyer le signalement.']);
        }
    }
}
