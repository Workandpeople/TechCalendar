<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ReportExportStorage
{
    public function root(): string
    {
        if (config('filesystems.disks.local.driver') !== 'local') {
            throw new ReportExportFailure('Le stockage privé des archives est indisponible.');
        }
        $disk = Storage::disk('local');
        $base = realpath($disk->path(''));
        $root = $disk->path('report-exports');
        if (! $base || is_link($root) || (! is_dir($root) && ! @mkdir($root, 0700))) {
            throw new ReportExportFailure('Le stockage privé des archives est indisponible.');
        }
        $real = realpath($root);
        $public = realpath(public_path());
        if ($real !== $base.'/report-exports' || ($public && str_starts_with($real.'/', $public.'/'))) {
            throw new ReportExportFailure('Le stockage privé des archives est indisponible.');
        }
        chmod($root, 0700);

        return $real;
    }

    public function directory(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new ReportExportFailure('Référence d’archive invalide.');
        }
        $directory = $this->root().'/'.$id;
        if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0700))) {
            throw new ReportExportFailure('Le stockage privé des archives est indisponible.');
        }

        return $directory;
    }

    public function path(string $id, string $name): string
    {
        if (! in_array($name, ['reports.zip', 'reports.zip.part', 'manifest.json'], true)) {
            throw new ReportExportFailure('Nom de fichier d’archive invalide.');
        }
        $path = $this->directory($id).'/'.$name;
        if (is_link($path)) {
            throw new ReportExportFailure('Le stockage privé des archives est indisponible.');
        }

        return $path;
    }

    public function lock(string $purpose = 'build')
    {
        if (! in_array($purpose, ['build', 'requests'], true)) {
            throw new ReportExportFailure('Verrou d’archive invalide.');
        }
        $path = $this->root().($purpose === 'build' ? '/.lock' : '/.requests.lock');
        if (is_link($path)) {
            throw new ReportExportFailure('Le stockage privé des archives est indisponible.');
        }
        $lock = @fopen($path, 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }

            return null;
        }
        chmod($path, 0600);

        return $lock;
    }

    public function checkCapacity(int $required): void
    {
        $used = 0;
        foreach (new \FilesystemIterator($this->root()) as $directory) {
            if ($directory->isLink() || ! $directory->isDir() || ! Str::isUuid($directory->getFilename())) {
                continue;
            }
            foreach (new \FilesystemIterator($directory->getPathname()) as $file) {
                if (! $file->isLink() && $file->isFile()) {
                    $used += $file->getSize();
                }
            }
        }
        $free = disk_free_space($this->root());
        if ($used + $required > config('report_exports.max_storage_bytes')
            || $free === false || $free - $required < config('report_exports.reserve_bytes')) {
            throw new ReportExportFailure('Espace temporaire insuffisant. Aucun rapport n’a été supprimé. Réessayez plus tard ou contactez notre équipe.');
        }
    }

    public function remove(string $id): void
    {
        $directory = $this->directory($id);
        foreach (new \FilesystemIterator($directory) as $file) {
            $name = $file->getFilename();
            // libzip may leave an adjacent random temporary file after a hard interruption.
            if (! $file->isLink() && $file->isFile() && (in_array($name, ['reports.zip', 'reports.zip.part', 'manifest.json'], true)
                || preg_match('/^reports\.zip\.part\.[A-Za-z0-9_.-]{1,96}$/D', $name))) {
                @unlink($file->getPathname());
            }
        }
        @rmdir($directory);
    }
}
