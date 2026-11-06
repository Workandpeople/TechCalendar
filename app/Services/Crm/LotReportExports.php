<?php

namespace App\Services\Crm;

use App\Jobs\BuildLotReportExport;
use App\Models\LotReportExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

final class LotReportExports
{
    public function __construct(private LotReportSelection $selection, private ReportExportStorage $storage) {}

    public function request(User $actor, string $lot): LotReportExport
    {
        $lot = trim($lot);
        abort_unless($this->selection->dossiers($actor, $lot)->exists(), 404);
        if (! class_exists(ZipArchive::class) || in_array(config('queue.default'), ['sync', 'null'], true)) {
            throw ValidationException::withMessages(['lot' => 'La préparation en arrière-plan est indisponible. Contactez notre équipe.']);
        }
        try {
            $this->prune();
            $requestLock = $this->storage->lock('requests');
        } catch (ReportExportFailure $exception) {
            throw ValidationException::withMessages(['lot' => $exception->getMessage()]);
        }
        abort_unless($requestLock, 429, 'Une demande d’archive est en cours d’enregistrement. Réessayez dans quelques secondes.', ['Retry-After' => '2']);
        try {
            return DB::transaction(function () use ($actor, $lot): LotReportExport {
                User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $current = LotReportExport::query()->where('user_id', $actor->id)->where('expires_at', '>', now())
                    ->whereIn('status', ['queued', 'building'])->first();
                if ($current) {
                    if ($current->lot_name === $lot && $current->external_client_id === $actor->external_client_id) {
                        return $current;
                    }
                    throw ValidationException::withMessages(['lot' => 'Une archive est déjà en préparation pour votre compte. Attendez sa fin avant d’en demander une autre.']);
                }
                if (LotReportExport::query()->whereIn('status', ['queued', 'building'])->where('expires_at', '>', now())->count() >= config('report_exports.max_pending')) {
                    throw ValidationException::withMessages(['lot' => 'Le service prépare déjà plusieurs archives. Réessayez dans quelques minutes.']);
                }
                $export = LotReportExport::create(['id' => (string) Str::uuid(), 'user_id' => $actor->id,
                    'external_client_id' => $actor->external_client_id, 'lot_name' => $lot, 'status' => 'queued',
                    'expires_at' => now()->addHours(config('report_exports.lifetime_hours'))]);
                BuildLotReportExport::dispatch($export->id)->afterCommit();

                return $export;
            });
        } finally {
            flock($requestLock, LOCK_UN);
            fclose($requestLock);
        }
    }

    public function recent(User $actor): array
    {
        $this->selection->authorize($actor);

        return LotReportExport::query()->where('user_id', $actor->id)->where('external_client_id', $actor->external_client_id)
            ->where('created_at', '>', now()->subDay())->latest('created_at')->limit(5)->get()->map($this->result(...))->all();
    }

    public function result(LotReportExport $export): array
    {
        $status = $export->expires_at->isPast() ? 'expired' : $export->status;

        return ['id' => $export->id, 'lot' => $export->lot_name, 'status' => $status,
            'file_count' => (int) $export->file_count, 'unavailable_count' => (int) $export->unavailable_count,
            'expires_at' => $export->expires_at->toIso8601String(),
            'download_url' => $status === 'ready' ? route('client.report-exports.download', $export->id) : null,
            'message' => match ($status) {
                'queued' => 'Demande enregistrée. La préparation va commencer automatiquement.',
                'building' => 'Préparation des rapports en cours. Vous pouvez continuer à travailler.',
                'ready' => $export->file_count.' rapport(s) prêt(s).'.($export->unavailable_count > 0
                    ? ' Attention : '.$export->unavailable_count.' rapport(s) indisponible(s), non inclus dans l’archive.' : ''),
                'expired' => 'Cette archive a expiré. Demandez une nouvelle préparation.',
                default => $export->failure_message ?: 'La préparation a échoué. Réessayez ou contactez notre équipe.',
            }];
    }

    /** One builder at a time bounds temporary disk consumption, even with several workers. */
    public function build(string $id): bool
    {
        $lock = $this->storage->lock();
        if (! $lock) {
            return false;
        }
        try {
            $this->pruneUnlocked();
            $export = LotReportExport::query()->find($id);
            if (! $export || $export->status !== 'queued' || $export->expires_at->isPast()) {
                return true;
            }
            $export->update(['status' => 'building']);
            try {
                $actor = $this->actor($export);
                $files = $this->selection->collect($actor, $export->lot_name);
                if ($files['entries'] === []) {
                    throw new ReportExportFailure('Aucun rapport PDF partagé et disponible dans ce lot.');
                }
                $required = $files['bytes'] + count($files['entries']) * 2048 + 1024 ** 2;
                $this->storage->checkCapacity($required);
                $this->writeArchive($export, $files);
                // Catch sharing/category changes during a long ZIP build before publishing it.
                $current = $this->selection->collect($this->actor($export), $export->lot_name);
                if (! hash_equals($files['fingerprint'], $current['fingerprint']) || $export->expires_at->isPast()) {
                    throw new ReportExportFailure('Le contenu ou les accès du lot ont changé pendant la préparation. Demandez une nouvelle archive.');
                }
                $export->update(['status' => 'ready', 'file_count' => count($files['entries']),
                    'unavailable_count' => $files['unavailable'], 'archive_bytes' => filesize($this->storage->path($id, 'reports.zip'))]);
            } catch (Throwable $exception) {
                $this->storage->remove($id);
                $export->update(['status' => 'failed', 'failure_message' => $exception instanceof ReportExportFailure
                    ? $exception->getMessage() : 'La préparation a été interrompue ou les accès ont changé. Réessayez ou contactez notre équipe.']);
                Log::warning('Lot report export failed.', ['export_id' => $id, 'exception_class' => $exception::class]);
            }

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeArchive(LotReportExport $export, array $files): void
    {
        $path = $this->storage->path($export->id, 'reports.zip.part');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new ReportExportFailure('Impossible de préparer l’archive. Réessayez plus tard.');
        }
        $names = [];
        try {
            foreach ($files['entries'] as $entry) {
                $name = $entry['name'];
                $suffix = 1;
                while (isset($names[mb_strtolower($name)])) {
                    $name = pathinfo($entry['name'], PATHINFO_FILENAME).' - '.(++$suffix).'.pdf';
                }
                $names[mb_strtolower($name)] = true;
                $handle = @fopen($entry['path'], 'rb');
                $header = $handle ? fread($handle, 1024) : false;
                if ($handle) {
                    fclose($handle);
                }
                if ($header === false || ! str_contains($header, '%PDF-') || ! $zip->addFile($entry['path'], $name)
                    || ! $zip->setCompressionName($name, ZipArchive::CM_STORE)) {
                    throw new ReportExportFailure('Un rapport PDF est illisible. Aucun export partiel n’a été publié. Contactez notre équipe.');
                }
            }
            $zip->addFromString('Informations.txt', 'Rapports partagés du lot : '.$export->lot_name."\n"
                .count($files['entries'])." rapport(s) PDF inclus.\n".$files['unavailable']." rapport(s) indisponible(s), non inclus.\n"
                ."Seuls les dossiers et documents accessibles à votre compte sont inclus.\nLes fichiers originaux ne sont pas modifiés.\n");
        } finally {
            $closed = $zip->close();
        }
        if (! $closed || ! is_file($path) || ! chmod($path, 0600)
            || ! rename($path, $this->storage->path($export->id, 'reports.zip'))) {
            throw new ReportExportFailure('Impossible de finaliser l’archive. Réessayez plus tard.');
        }
        $manifest = $this->storage->path($export->id, 'manifest.json');
        if (file_put_contents($manifest, json_encode(['fingerprint' => $files['fingerprint']], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new ReportExportFailure('Impossible de finaliser l’archive. Réessayez plus tard.');
        }
        chmod($manifest, 0600);
    }

    public function download(User $actor, LotReportExport $export): StreamedResponse
    {
        $this->selection->authorize($actor);
        abort_unless($export->user_id === $actor->id && $export->external_client_id === $actor->external_client_id, 404);
        abort_if($export->expires_at->isPast(), 410, 'Cette archive a expiré. Demandez une nouvelle préparation.');
        abort_unless($export->status === 'ready', 409, 'Cette archive n’est pas disponible.');
        try {
            $files = $this->selection->collect($actor, $export->lot_name);
        } catch (ReportExportFailure $exception) {
            abort(409, $exception->getMessage());
        }
        $manifestPath = $this->storage->path($export->id, 'manifest.json');
        $manifest = is_file($manifestPath) && filesize($manifestPath) < 1024
            ? json_decode(file_get_contents($manifestPath), true) : null;
        abort_unless(is_string($manifest['fingerprint'] ?? null) && hash_equals($manifest['fingerprint'], $files['fingerprint']),
            409, 'Le contenu ou les accès du lot ont changé. Demandez une nouvelle archive.');
        $path = $this->storage->path($export->id, 'reports.zip');
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        abort_unless($handle, 410, 'Cette archive a expiré. Demandez une nouvelle préparation.');
        $size = fstat($handle)['size'];
        $name = DossierDownloadName::make('Rapports - '.$export->lot_name, 'zip');

        // Keep the already-open descriptor: expiration cleanup cannot truncate an active download.
        return response()->streamDownload(function () use ($handle): void {
            try {
                while (! feof($handle) && ! connection_aborted()) {
                    echo fread($handle, 64 * 1024);
                }
            } finally {
                fclose($handle);
            }
        }, $name, ['Content-Type' => 'application/zip', 'Content-Length' => (string) $size,
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actor(LotReportExport $export): User
    {
        $actor = User::query()->with('externalClient')->findOrFail($export->user_id);
        $this->selection->authorize($actor);
        abort_unless($actor->external_client_id === $export->external_client_id, 403);

        return $actor;
    }

    public function prune(): int
    {
        $lock = $this->storage->lock();
        if (! $lock) {
            return 0;
        }
        try {
            return $this->pruneUnlocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function pruneUnlocked(): int
    {
        $count = 0;
        // With the global builder lock held, a leftover "building" row has no live builder.
        foreach (LotReportExport::query()->where('status', 'building')->cursor() as $interrupted) {
            $this->storage->remove($interrupted->id);
            $interrupted->update(['status' => 'failed', 'failure_message' => 'La préparation a été interrompue. Demandez une nouvelle archive.']);
        }
        LotReportExport::query()->where('expires_at', '<=', now())->where('status', '<>', 'expired')
            ->chunkById(100, function ($exports) use (&$count): void {
                foreach ($exports as $export) {
                    $this->storage->remove($export->id);
                    $export->update(['status' => 'expired']);
                    $count++;
                }
            });
        // Includes orphaned partial files left by a hard-killed worker or deleted account.
        foreach (new \FilesystemIterator($this->storage->root()) as $directory) {
            if (! $directory->isLink() && $directory->isDir() && Str::isUuid($directory->getFilename())
                && $directory->getMTime() < now()->subHours(config('report_exports.lifetime_hours'))->timestamp) {
                $this->storage->remove($directory->getFilename());
            }
        }
        LotReportExport::query()->where('status', 'expired')->where('expires_at', '<', now()->subDays(7))->delete();

        return $count;
    }
}
