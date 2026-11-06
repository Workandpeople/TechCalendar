<?php

namespace App\Services\Crm;

use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** The export deliberately uses the same report metadata and audience rules as individual downloads. */
final class LotReportSelection
{
    public function __construct(private ClientAccess $access, private CollectedDossierDocuments $collected) {}

    public function authorize(User $actor): void
    {
        abort_unless($this->access->allows($actor, 'documents'), 403);
    }

    public function lots(User $actor, string $term): array
    {
        $this->authorize($actor);
        $visible = $this->access->dossiers($actor);
        $legacy = (clone $visible)->whereNotNull('legacy_lot_name')->selectRaw('TRIM(legacy_lot_name) as name')->toBase();
        $linked = DB::table('lots')->join('lot_appointments', 'lot_appointments.lot_id', '=', 'lots.id')
            ->join('appointments', 'appointments.id', '=', 'lot_appointments.appointment_id')
            ->join('dossier_operations', 'dossier_operations.id', '=', 'appointments.dossier_operation_id')
            ->whereIn('dossier_operations.dossier_id', (clone $visible)->select('dossiers.id'))
            ->selectRaw('TRIM(lots.name) as name');
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term)).'%';

        return DB::query()->fromSub($legacy->union($linked), 'visible_lots')->where('name', '<>', '')
            ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->distinct()->orderBy('name')->limit(20)->pluck('name')->all();
    }

    public function dossiers(User $actor, string $lot): Builder
    {
        $this->authorize($actor);
        $query = $this->access->dossiers($actor);
        DossierLotReference::exact($query, $lot);

        return $query;
    }

    public function collect(User $actor, string $lot): array
    {
        $query = $this->dossiers($actor, $lot);
        $entries = [];
        $unavailable = 0;
        $bytes = 0;
        $seen = [];
        $audience = $this->access->audienceColumn($actor);
        $query->with(['client', 'delegataire'])
            ->chunkById(100, function ($dossiers) use ($actor, $audience, &$entries, &$unavailable, &$bytes, &$seen): void {
                $byId = $dossiers->keyBy('id');
                $documents = DossierDocument::query()->whereIn('dossier_id', $byId->keys())
                    ->where('document_type', 'report')->where($audience, true)->orderBy('id')->cursor();
                foreach ($documents as $document) {
                    $this->append($entries, $seen, $bytes, $unavailable, $this->native($document, $byId[$document->dossier_id]), 'native:'.$document->id);
                }
                $records = $this->access->filterLegacyDocuments(DB::table('legacy_records')
                    ->where('source_table', 'dossier_document')->whereIn('dossier_id', $byId->keys()), $actor)->orderBy('id')->cursor();
                foreach ($records as $record) {
                    $payload = json_decode($record->payload, true) ?: [];
                    if (! filled($payload['reference_controle'] ?? null) && ($payload['document_type'] ?? null) !== 'report') {
                        continue;
                    }
                    $file = $this->collected->reportFile($actor, $byId[$record->dossier_id], $record);
                    $this->append($entries, $seen, $bytes, $unavailable, $file, 'archive:'.$record->id);
                }
            });
        usort($entries, fn ($left, $right) => strcmp($left['key'], $right['key']));

        return ['entries' => $entries, 'unavailable' => $unavailable, 'bytes' => $bytes,
            'fingerprint' => hash('sha256', json_encode([$entries, $unavailable], JSON_THROW_ON_ERROR))];
    }

    private function native(DossierDocument $document, Dossier $dossier): ?array
    {
        $prefix = 'crm/dossiers/'.$dossier->id.'/';
        $relative = $document->path;
        if (! is_string($relative) || ! str_starts_with($relative, $prefix)
            || substr($relative, strlen($prefix)) !== basename($relative)
            || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'pdf') {
            return null;
        }
        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        if (! $root) {
            return null;
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if ($part === '' || str_starts_with($part, '.') || is_link($path)) {
                return null;
            }
        }

        return ['path' => $path, 'name' => DossierDownloadName::make($document->name, 'pdf', $dossier,
            isReport: true, reportOutcome: $document->control_outcome)];
    }

    private function append(array &$entries, array &$seen, int &$bytes, int &$unavailable, ?array $file, string $key): void
    {
        // Resolve aliases before counting; duplicate references do not consume the archive quota.
        $identity = $file ? $file['path'].'\0'.$file['name'] : null;
        if ($identity !== null && isset($seen[$identity])) {
            return;
        }
        if (count($entries) + $unavailable >= config('report_exports.max_reports')) {
            throw new ReportExportFailure('Ce lot dépasse la limite de rapports par archive. Contactez notre équipe pour un export fractionné.');
        }
        if ($identity !== null) {
            $seen[$identity] = true;
        }
        $stat = $file ? @stat($file['path']) : false;
        if (! $file || ! $stat || ! is_file($file['path']) || ! is_readable($file['path']) || $stat['size'] <= 0
            || strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)) !== 'pdf') {
            $unavailable++;

            return;
        }
        $bytes += $stat['size'];
        if ($bytes > config('report_exports.max_source_bytes')) {
            throw new ReportExportFailure('Les rapports de ce lot dépassent la taille maximale d’une archive. Contactez notre équipe pour un export fractionné.');
        }
        $entries[] = [...$file, 'key' => $key, 'size' => $stat['size'], 'modified' => $stat['mtime'],
            'changed' => $stat['ctime'], 'inode' => $stat['ino']];
    }
}
