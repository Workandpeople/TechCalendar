<?php

namespace App\Console\Commands;

use App\Models\TechnicianIncident;
use App\Services\Tech\TechnicianOnSiteReports;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillTechnicianIncidents extends Command
{
    protected $signature = 'tech:backfill-incidents {--apply : Importer les signalements déjà conservés sur les rendez-vous}';

    protected $description = 'Reprend les preuves de signalements existantes sans notification, envoi ni changement de statut.';

    public function handle(): int
    {
        if (! config('crm.v2_enabled')) {
            $this->error('V2 inactive. Aucune reprise effectuée.');

            return self::FAILURE;
        }
        $counts = ['existing' => 0, 'eligible' => 0, 'imported' => 0, 'skipped' => 0];
        DB::table('appointments')->whereNotNull('external_payload->techcalendar_on_site')->orderBy('id')
            ->chunkById(100, function ($appointments) use (&$counts): void {
                foreach ($appointments as $appointment) {
                    // Only copy proofs whose author, photo and dossier still agree. Never reconstruct a missing proof.
                    $report = json_decode($appointment->external_payload, true)['techcalendar_on_site'] ?? null;
                    if (! is_array($report) || ! Str::isUuid($report['request_id'] ?? '')
                        || ! is_string($report['reason'] ?? null)
                        || ! isset(TechnicianOnSiteReports::REASONS[$report['reason'] ?? ''])
                        || ! is_int($report['author_id'] ?? null) || $report['author_id'] < 1
                        || ! is_int($report['document_id'] ?? null) || $report['document_id'] < 1
                        || ! is_string($report['reported_at'] ?? null)) {
                        $counts['skipped']++;

                        continue;
                    }
                    if (TechnicianIncident::where('request_id', $report['request_id'])->exists()) {
                        $counts['existing']++;

                        continue;
                    }
                    $dossierId = DB::table('dossier_operations')->where('id', $appointment->dossier_operation_id)->value('dossier_id');
                    $document = DB::table('dossier_documents')->where('id', $report['document_id'] ?? 0)->first();
                    if (! $document || ! $dossierId || (int) $document->dossier_id !== (int) $dossierId
                        || (int) $document->author_id !== (int) ($report['author_id'] ?? 0)
                        || (int) $document->dossier_operation_id !== (int) $appointment->dossier_operation_id
                        || $document->mime_type !== 'image/jpeg' || $document->document_type !== 'free'
                        || ! DB::table('users')->where('id', $report['author_id'] ?? 0)->exists()) {
                        $counts['skipped']++;

                        continue;
                    }
                    try {
                        $reportedAt = CarbonImmutable::parse($report['reported_at'])->setTimezone(config('app.timezone'));
                    } catch (\Throwable) {
                        $counts['skipped']++;

                        continue;
                    }
                    $counts['eligible']++;
                    if (! $this->option('apply')) {
                        continue;
                    }
                    $result = DB::transaction(function () use ($appointment, $report, $dossierId, $reportedAt): string {
                        DB::table('dossiers')->where('id', $dossierId)->lockForUpdate()->first();
                        $locked = DB::table('appointments')->where('id', $appointment->id)->lockForUpdate()->first();
                        if (! $locked || $locked->external_payload !== $appointment->external_payload) {
                            return 'skipped';
                        }
                        $incident = TechnicianIncident::firstOrCreate(['request_id' => $report['request_id']], [
                            'dossier_id' => $dossierId, 'appointment_id' => $appointment->id,
                            'technician_id' => $report['author_id'], 'document_id' => $report['document_id'],
                            'reason' => $report['reason'], 'reported_at' => $reportedAt,
                            'appointment_starts_at' => $appointment->status === 'problem' ? $appointment->starts_at : null,
                            'comment' => 'Signalement antérieur repris. Le commentaire d’origine reste dans le dossier.',
                        ]);

                        return $incident->wasRecentlyCreated ? 'imported' : 'existing';
                    }, 3);
                    $counts[$result]++;
                }
            });
        $this->line(json_encode(['dry_run' => ! $this->option('apply'), ...$counts, 'messages_sent' => false], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
