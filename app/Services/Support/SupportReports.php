<?php

namespace App\Services\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SupportReports
{
    public function __construct(private SupportReportDelivery $delivery) {}

    public function root(): string
    {
        return storage_path('app/support-reports');
    }

    public function prune(): int
    {
        if (! is_dir($this->root()) || is_link($this->root())) {
            return 0;
        }
        $removed = 0;
        foreach (new \FilesystemIterator($this->root(), \FilesystemIterator::SKIP_DOTS) as $directory) {
            if (! $directory->isDir() || $directory->isLink() || ! Str::isUuid($directory->getFilename())) {
                continue;
            }
            $report = $directory->getPathname().'/report.json';
            if (! is_file($report) || is_link($report) || filemtime($report) >= now()->subDays(30)->timestamp) {
                continue;
            }
            $lock = fopen($directory->getPathname().'/report.lock', 'c');
            if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                clearstatcache(true, $report);
                if (is_file($report) && filemtime($report) < now()->subDays(30)->timestamp
                    && File::deleteDirectory($directory->getPathname())) {
                    $removed++;
                }
                flock($lock, LOCK_UN);
            }
            if ($lock) {
                fclose($lock);
            }
        }

        return $removed;
    }

    public function submit(array $report, array $screenshots, Request $request, bool $anonymous = false): array
    {
        abort_unless(Str::isUuid($report['id'] ?? ''), 422);
        $limiter = new RateLimiter(Cache::store('file'));
        $rateKey = 'support:'.hash_hmac('sha256', $anonymous ? 'guest:'.$request->ip() : 'user:'.$report['actor_id'], config('app.key'));
        if ($limiter->tooManyAttempts($rateKey, $anonymous ? 5 : 10)
            || ($anonymous && $limiter->tooManyAttempts('support:errors:global', 50))) {
            abort(429, 'La limite de signalements est atteinte. Réessayez plus tard.', ['Retry-After' => '3600']);
        }
        $limiter->hit($rateKey, 3600);
        if ($anonymous) {
            $limiter->hit('support:errors:global', 3600);
        }

        $root = $this->root();
        $directory = $root.'/'.$report['id'];
        foreach ([$root, $directory] as $path) {
            if (is_link($path) || (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path))) {
                throw new RuntimeException('Support storage unavailable.');
            }
        }
        $lock = fopen($directory.'/report.lock', 'c');
        if (! $lock) {
            throw new RuntimeException('Support lock unavailable.');
        }
        chmod($directory.'/report.lock', 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            abort(409, 'Ce signalement est déjà en cours de transmission.');
        }
        try {
            $digest = hash('sha256', json_encode([$report['actor_id'] ?? null, $report['kind'], $report['title'], $report['message'], $report['page']], JSON_THROW_ON_ERROR));
            $path = $directory.'/report.json';
            if (is_file($path)) {
                $stored = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                abort_unless(hash_equals($stored['digest'], $digest), 409, 'Cette référence correspond déjà à un autre signalement.');
                if (in_array($stored['delivery'], ['sent', 'simulated'], true)) {
                    return $this->result($stored);
                }
                $report = $stored;
            } else {
                $report['screenshots'] = [];
                foreach ($screenshots as $index => $screenshot) {
                    $mime = $screenshot->getMimeType();
                    $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
                    if (! $extension) {
                        throw ValidationException::withMessages(['screenshots' => 'Utilisez des captures PNG, JPEG ou WebP.']);
                    }
                    $name = 'capture-'.($index + 1).'.'.$extension;
                    if (! copy($screenshot->getRealPath(), $directory.'/'.$name)) {
                        throw new RuntimeException('Screenshot storage unavailable.');
                    }
                    chmod($directory.'/'.$name, 0600);
                    $report['screenshots'][] = ['name' => $name, 'mime' => $mime];
                }
                $report += ['digest' => $digest, 'delivery' => 'pending', 'received_at' => now()->toIso8601String()];
                $this->persist($path, $report);
            }
            try {
                $report['delivery'] = $this->delivery->send($report, $directory);
            } catch (Throwable $exception) {
                $report['delivery'] = 'failed';
                try {
                    Log::warning('Support report delivery failed.', ['reference' => $report['id'], 'exception_class' => $exception::class]);
                } catch (Throwable) {
                    // Persist the report even if the application's logger is also unavailable.
                }
            }
            $this->persist($path, $report);
            if ($report['delivery'] === 'sent') {
                foreach ($report['screenshots'] as $file) {
                    @unlink($directory.'/'.$file['name']);
                }
            }

            return $this->result($report);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function persist(string $path, array $report): void
    {
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Support report could not be saved.');
        }
        chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            throw new RuntimeException('Support report could not be saved.');
        }
    }

    private function result(array $report): array
    {
        return ['reference' => $report['id'], 'delivery' => $report['delivery'], 'message' => match ($report['delivery']) {
            'sent' => 'Votre signalement a été envoyé au développeur. Merci pour votre aide !',
            'simulated' => 'Signalement enregistré sans email : la livraison est désactivée ou en simulation.',
            default => 'Signalement conservé, mais l’email n’a pas pu être transmis. Réessayez avec ce formulaire dans quelques instants.',
        }];
    }
}
