<?php

declare(strict_types=1);

namespace Cofrac2\Deploy\Mymar;

use App\Models\User;
use App\Services\Mymar\MymarActionSettings;
use App\Services\Mymar\MymarBookingClient;
use App\Services\Mymar\MymarBookingReaderSettings;
use App\Services\Mymar\MymarObservation;
use App\Services\Mymar\MymarObservationPolicy;
use App\Services\Mymar\MymarPendingBookings;
use App\Services\Mymar\MymarPilotSettings;
use App\Services\Mymar\MymarReadWindow;
use App\Services\Mymar\MymarSlotGuard;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Explicit GET diagnostics only. No commands, reservations or source writes. */
final class ReadinessDiagnostic
{
    public function __construct(private MymarActionSettings $actions, private MymarBookingReaderSettings $reader,
        private MymarPilotSettings $pilot, private MymarSlotGuard $guard, private Factory $http, private MymarPendingBookings $pending) {}

    public function inspect(): array
    {
        $checks = [];
        $config = null;
        $check = static function (string $name, \Closure $callback) use (&$checks): void {
            try {
                $checks[$name] = ['ok' => true, ...$callback()];
            } catch (Throwable $error) {
                // Exception text/arguments can contain credentials; never forward them.
                $reason = $error->getMessage();
                $checks[$name] = ['ok' => false, 'exception_class' => $error::class, 'reason' => in_array($reason,
                    ['mymar.booking_reader_busy', 'mymar.booking_reader_disabled', 'mymar.booking_pending_unavailable',
                        'mymar.unsafe_actions_settings', 'mymar.invalid_actions_token', 'mymar.unsafe_reader_settings',
                        'mymar.unsafe_pilot_file', 'mymar.invalid_pilot_token'], true) ? $reason : 'redacted'];
            }
        };
        $check('actions_configuration', function () use (&$config): array {
            $config = $this->actions->load();
            if ($config === null) {
                throw new RuntimeException;
            }

            return ['ok' => config('crm.v2_enabled') && $config['guard_enabled'] === true && $config['writes_enabled'] === false,
                'guard_enabled' => $config['guard_enabled'], 'writes_enabled' => $config['writes_enabled'], 'bindings' => count($config['bindings'])];
        });
        $check('journal_schema', fn () => ['ok' => Schema::hasColumns('mymar_booking_requests',
            ['id', 'command', 'status', 'cofrac_technician_id', 'reserved_starts_at', 'reserved_ends_at'])]);
        if ($config !== null) {
            $check('active_native_technicians', function () use ($config): array {
                $ids = array_column($config['bindings'], 'cofrac_user_id');
                $active = User::query()->internal()->where('role', 2)->where('admin', false)->whereIn('id', $ids)->count();

                return ['ok' => $active === count($ids), 'expected' => count($ids), 'active' => $active];
            });
            $check('actions_token', function (): array {
                $this->actions->token();

                return [];
            });
        }
        $check('pending_reader', function (): array {
            $reader = $this->reader->load();
            if ($reader === null) {
                throw new RuntimeException;
            }
            $page = (new MymarBookingClient($this->http, enabled: true, port: $reader['port'], token: $this->reader->token()))->pending(0, 1);

            return ['items_probed' => count($page->items)];
        });
        $check('pending_projection', function (): array {
            $page = $this->pending->page(0, 1, true);

            return ['items_probed' => count($page['items'])];
        });
        $check('occupation_configuration', function () use ($config): array {
            $pilot = $this->pilot->load();
            $this->pilot->token();
            $matches = $config !== null;
            foreach ($config['bindings'] ?? [] as $binding) {
                $matches = $matches && ($pilot->technicianMap[$binding['source_technician_id']] ?? null) === $binding['cofrac_user_id'];
            }

            return ['ok' => $matches, 'bindings_match' => $matches, 'sync_enabled' => $pilot->syncEnabled];
        });
        foreach ($config['bindings'] ?? [] as $binding) {
            $native = $binding['cofrac_user_id'];
            $check('live_availability_'.$native, function () use ($binding): array {
                $from = Carbon::now('Europe/Paris');
                $to = $from->copy()->addHour();
                $types = array_column($this->guard->liveConflicts($binding['cofrac_user_id'], $from, $to), 'type');
                if (! in_array('mymar_unknown', $types, true)) {
                    return ['conflict_types' => $types];
                }

                return ['ok' => false, 'conflict_types' => $types,
                    'gateway' => $this->readOccupation(new MymarReadWindow($from->toIso8601String(), $to->toIso8601String(), [$binding['source_technician_id']]), $binding)];
            });
        }

        return ['format' => 'cofrac.mymar-actions-readiness-diagnostic', 'version' => 1, 'checked_at_utc' => gmdate(DATE_ATOM),
            'readiness_passed' => $checks !== [] && ! in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks,
            'php_version' => PHP_VERSION, 'peak_memory_bytes' => memory_get_peak_usage(true),
            'source_post_requests' => false, 'business_writes' => false, 'messages_sent' => false, 'activation_performed' => false];
    }

    private function readOccupation(MymarReadWindow $window, array $binding): array
    {
        $stream = null;
        $status = null;
        try {
            $response = $this->http->withToken($this->pilot->token())->acceptJson()->withoutRedirecting()
                ->connectTimeout(1)->timeout(5)->withOptions(['proxy' => '', 'stream' => true, 'read_timeout' => 2, 'decode_content' => false,
                    'progress' => static function (float $total, float $received): void {
                        if (max($total, $received) > 2097152) {
                            throw new RuntimeException;
                        }
                    }])->get('http://127.0.0.1:19846/v1/occupations', $window->query());
            $status = $response->status();
            $stream = $response->toPsrResponse()->getBody();
            if (strtolower(trim(explode(';', $response->header('Content-Type'))[0])) !== 'application/json'
                || ! in_array(strtolower($response->header('Content-Encoding')), ['', 'identity'], true)) {
                return ['http_status' => $status, 'json_response' => false];
            }
            $body = '';
            $deadline = hrtime(true) + 5_000_000_000;
            while (! $stream->eof()) {
                $chunk = $stream->read(min(4096, 2097153 - strlen($body)));
                $body .= $chunk;
                if ($chunk === '' || strlen($body) > 2097152 || hrtime(true) > $deadline) {
                    throw new RuntimeException;
                }
            }
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if ($status !== 200) {
                $code = $data['error'] ?? null;

                return ['http_status' => $status, 'source_error' => in_array($code,
                    ['scope_unavailable', 'technician_scope_denied', 'access_denied', 'source_unavailable', 'gateway_disabled', 'invalid_scope', 'unknown_endpoint'], true) ? $code : 'redacted'];
            }
            $report = (new MymarObservation)->inspect($data,
                new MymarObservationPolicy(timezoneConfirmed: true, durationUnitConfirmed: true, statusScopeConfirmed: true),
                new DateTimeImmutable, [$binding['source_technician_id'] => $binding['cofrac_user_id']]);
            $dossiers = array_column($data['appointments'], 'dossier_id', 'rdv_id');
            $references = array_map(static function (array $issue) use ($dossiers): array {
                $reference = array_intersect_key($issue, ['code' => true, 'source_rdv_id' => true]);
                if (isset($reference['source_rdv_id'], $dossiers[$reference['source_rdv_id']])) {
                    $reference['source_dossier_id'] = $dossiers[$reference['source_rdv_id']];
                }

                return $reference;
            }, array_slice($report['issues'], 0, 20));

            return ['http_status' => $status, 'issue_codes' => array_values(array_unique(array_column($report['issues'], 'code'))),
                'issue_references' => $references,
                'counts' => $report['counts'], 'scope_matches' => $report['scope_technician_ids'] === $window->technicianIds,
                'window_matches' => $report['window_start'] === $window->from->format(DATE_ATOM) && $report['window_end'] === $window->to->format(DATE_ATOM)];
        } catch (Throwable $error) {
            return ['http_status' => $status, 'exception_class' => $error::class];
        } finally {
            $stream?->close();
        }
    }
}
