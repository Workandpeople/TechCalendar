<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/** Short-lived encrypted projection, never a native dossier/import or a placement action. */
class MymarPendingBookings
{
    public function __construct(private readonly MymarBookingReaderSettings $settings, private readonly Factory $http) {}

    public function page(int $after = 0, int $limit = 25, bool $refresh = false): array
    {
        if ($after < 0 || $limit < 1 || $limit > 100) {
            throw new RuntimeException('mymar.invalid_booking_page');
        }
        $configuration = $this->settings->load();
        if ($configuration === null) {
            throw new RuntimeException('mymar.booking_reader_disabled');
        }
        $namespace = hash('sha256', $configuration['generation']);
        $epoch = Cache::get('mymar:pending:epoch:'.$namespace);
        $key = 'mymar:pending:v1:'.$namespace.":$after:$limit".(is_string($epoch) ? ':'.hash('sha256', $epoch) : '');
        $cached = $this->cached($key, $after, $limit);
        if ($cached && (! $refresh || now()->timestamp - $cached['checked_at'] < 5)) {
            return $cached;
        }
        $lock = Cache::lock($key.':lock', 8);
        if (! $lock->get()) {
            throw new RuntimeException('mymar.booking_reader_busy');
        }
        try {
            $cached = $this->cached($key, $after, $limit);
            if ($cached && (! $refresh || now()->timestamp - $cached['checked_at'] < 5)) {
                return $cached;
            }
            $page = (new MymarBookingClient($this->http, enabled: true, writesEnabled: false, port: $configuration['port'], token: $this->settings->token()))->pending($after, $limit);
            $value = ['format' => 'cofrac.mymar-pending-bookings', 'version' => 1, 'items' => $page->items, 'next_after' => $page->nextAfter, 'checked_at' => now()->timestamp];
            Cache::put($key, Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), 30);

            return $value;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) { /* The bounded lease expires even after a cache outage. */
            }
        }
    }

    public function find(int $id, bool $fresh = false): ?array
    {
        if ($id < 1) {
            throw new RuntimeException('mymar.invalid_booking_id');
        }
        $page = $this->page($id - 1, 1, $fresh);
        $item = $page['items'][0] ?? null;

        return ($item['dossier_id'] ?? null) === $id ? $item : null;
    }

    public function invalidate(): void
    {
        try {
            $configuration = $this->settings->load();
            if ($configuration !== null) {
                Cache::put('mymar:pending:epoch:'.hash('sha256', $configuration['generation']), bin2hex(random_bytes(16)), 86400);
            }
        } catch (Throwable) {
            // The source receipt remains authoritative; short-lived pages expire on their own.
        }
    }

    private function cached(string $key, int $after, int $limit): ?array
    {
        $encrypted = Cache::get($key);
        if (! is_string($encrypted)) {
            return null;
        }
        try {
            $value = json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR);
            $stamp = $value['checked_at'] ?? null;
            if (! is_int($stamp) || $stamp > now()->timestamp + 5 || $stamp < now()->timestamp - 30) {
                return null;
            }
            unset($value['checked_at']);
            MymarPendingBookingPage::fromArray($value, $after, $limit);

            return $value + ['checked_at' => $stamp];
        } catch (Throwable) {
            // Corrupt/expired projections are not an empty successful source page.
            return null;
        }
    }
}
