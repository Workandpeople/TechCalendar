<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Services\Mymar\MymarVt2Command;
use Throwable;

/** No source URL is fetched. Only one canonical uploads directory is readable. */
final class MymarDocumentFiles
{
    private array $prepared = [];

    public function __construct(private readonly string $uploads, private readonly string $staging, private readonly int $reserveBytes = 53687091200)
    {
        foreach ([$uploads, $staging] as $root) {
            if (! str_starts_with($root, '/') || realpath($root) !== $root || ! is_dir($root)) {
                throw new MymarDocumentFailure('documents_unsafe_directory', 503);
            }
        }
        if ((fileperms($staging) & 0777) !== 0700 || fileowner($staging) !== posix_geteuid() || $uploads === $staging || str_starts_with($staging, $uploads.'/')
            || str_starts_with($uploads, $staging.'/') || stat($uploads)['dev'] !== stat($staging)['dev'] || $reserveBytes < 0) {
            throw new MymarDocumentFailure('documents_unsafe_staging', 503);
        }
    }

    public function metadata(string $name): array
    {
        $stat = $this->regular($this->path($name));

        return ['bytes' => (int) $stat['size']];
    }

    /** Caller must recheck the current appointment and classification before each open. */
    public function open(string $name)
    {
        return $this->openPdf($this->path($name));
    }

    public function stage($input, MymarVt2Command $command): void
    {
        if (! is_resource($input) || get_resource_type($input) !== 'stream') {
            throw new MymarDocumentFailure('documents_invalid_upload', 422);
        }
        $data = $command->payload;
        $destination = $this->staged($command);
        if (file_exists($destination) || is_link($destination)) {
            $this->verify($destination, $command);

            return;
        }
        $free = disk_free_space($this->staging);
        if ($free === false || $free < $data['bytes'] + $this->reserveBytes) {
            throw new MymarDocumentFailure('documents_insufficient_space', 507);
        }
        $temporary = $this->staging.'/.partial-'.bin2hex(random_bytes(16));
        $output = fopen($temporary, 'x+b');
        if ($output === false) {
            throw new MymarDocumentFailure('documents_staging_unavailable', 503);
        }
        try {
            if (! chmod($temporary, 0600)) {
                throw new MymarDocumentFailure('documents_staging_unavailable', 503);
            }
            $bytes = 0;
            $hash = hash_init('sha256');
            while (! feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false || ($chunk === '' && ! feof($input)) || $bytes + strlen($chunk) > $data['bytes']) {
                    throw new MymarDocumentFailure('documents_invalid_upload', 422);
                }
                $bytes += strlen($chunk);
                if ($bytes % 16777216 < strlen($chunk) && (disk_free_space($this->staging) ?: 0) < $this->reserveBytes) {
                    throw new MymarDocumentFailure('documents_insufficient_space', 507);
                }
                hash_update($hash, $chunk);
                for ($offset = 0, $length = strlen($chunk); $offset < $length; $offset += $written) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new MymarDocumentFailure('documents_staging_unavailable', 503);
                    }
                }
            }
            if ($bytes !== $data['bytes'] || ! hash_equals($data['sha256'], hash_final($hash)) || ! fflush($output) || ! fsync($output)) {
                throw new MymarDocumentFailure('documents_upload_mismatch', 422);
            }
            fclose($output);
            $output = null;
            $this->verify($temporary, $command);
            // Exclusive publication prevents concurrent staging from replacing an existing file.
            if (! link($temporary, $destination)) {
                if (! file_exists($destination)) {
                    throw new MymarDocumentFailure('documents_staging_unavailable', 503);
                }
            }
            unlink($temporary);
            $this->verify($destination, $command);
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($temporary) && ! is_link($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function publish(MymarVt2Command $command): string
    {
        $data = $command->payload;
        $name = 'cofrac-vt2-'.$data['request_id'].'-'.$data['sha256'].'.pdf';
        $destination = $this->path($name);
        if (! isset($this->prepared[$command->fingerprint()])) {
            $this->preparePublication($command);
        }
        if (file_exists($destination) || is_link($destination)) {
            $this->assertPrepared($destination, $command);

            return $name;
        }
        $staged = $this->staged($command);
        $this->assertPrepared($staged, $command);
        // A new immutable file only. Never overwrite or rename any existing MYMAR document.
        if (! link($staged, $destination)) {
            throw new MymarDocumentFailure('documents_publication_failed', 503);
        }
        if (! unlink($staged)) {
            // Do not claim completion while two paths can mutate the same inode.
            throw new MymarDocumentFailure('documents_publication_failed', 503);
        }
        $this->assertPrepared($destination, $command);

        return $name;
    }

    /** The expensive hash runs before any source SQL transaction or dossier lock. */
    public function preparePublication(MymarVt2Command $command): void
    {
        $name = 'cofrac-vt2-'.$command->payload['request_id'].'-'.$command->payload['sha256'].'.pdf';
        $destination = $this->path($name);
        $path = (file_exists($destination) || is_link($destination)) ? $destination : $this->staged($command);
        $this->verify($path, $command);
        if (fileowner($path) !== posix_geteuid() || ! chmod($path, 0440)) {
            throw new MymarDocumentFailure('documents_publication_failed', 503);
        }
        $this->prepared[$command->fingerprint()] = $this->regular($path);
    }

    private function assertPrepared(string $path, MymarVt2Command $command): void
    {
        $stat = $this->regular($path);
        $proof = $this->prepared[$command->fingerprint()] ?? [];
        // The staging service is the sole owner; the source application has read access only.
        foreach (['dev', 'ino', 'size', 'mtime', 'mode', 'uid', 'gid'] as $field) {
            if (($proof[$field] ?? null) !== $stat[$field] || ($stat['mode'] & 0777) !== 0440) {
                throw new MymarDocumentFailure('documents_upload_changed');
            }
        }
    }

    private function staged(MymarVt2Command $command): string
    {
        return $this->staging.'/'.$command->payload['request_id'].'-'.$command->payload['sha256'].'.pdf';
    }

    private function path(string $name): string
    {
        if (realpath($this->uploads) !== $this->uploads || realpath($this->staging) !== $this->staging
            || (fileperms($this->staging) & 0777) !== 0700) {
            throw new MymarDocumentFailure('documents_unsafe_directory', 503);
        }
        if ($name === '' || strlen($name) > 240 || ! mb_check_encoding($name, 'UTF-8')
            || preg_match('~[/\\\\\x00-\x1F\x7F:%]~', $name) || ! preg_match('/\.pdf$/iD', $name)
            || $name[0] === '.' || str_contains($name, '..')) {
            throw new MymarDocumentFailure('documents_file_unavailable', 404);
        }

        return $this->uploads.'/'.$name;
    }

    private function regular(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || $stat['size'] < 8 || $stat['size'] > MymarVt2Command::MAXIMUM_BYTES || ! is_readable($path)) {
            throw new MymarDocumentFailure('documents_file_unavailable', 404);
        }

        return $stat;
    }

    private function openPdf(string $path)
    {
        $before = $this->regular($path);
        $stream = @fopen($path, 'rb');
        try {
            $after = is_resource($stream) ? fstat($stream) : false;
            if (! $after || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino'] || $after['nlink'] !== 1
                || $after['size'] !== $before['size'] || fread($stream, 5) !== '%PDF-') {
                throw new MymarDocumentFailure('documents_file_unavailable', 404);
            }
            rewind($stream);

            return $stream;
        } catch (Throwable $error) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw $error;
        }
    }

    private function verify(string $path, MymarVt2Command $command): void
    {
        $stream = $this->openPdf($path);
        try {
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);
            if ($bytes !== $command->payload['bytes'] || ! hash_equals($command->payload['sha256'], hash_final($hash))) {
                throw new MymarDocumentFailure('documents_upload_mismatch', 422);
            }
        } finally {
            fclose($stream);
        }
    }
}
