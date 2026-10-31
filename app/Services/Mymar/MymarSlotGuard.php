<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use App\Models\MymarBookingRequest;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Called under the native technician lock; a failed MAR read is never a free slot. */
class MymarSlotGuard
{
    public function __construct(private MymarActionSettings $settings, private MymarPilotSettings $pilot, private Factory $http) {}

    public function pendingConflicts(int $technicianId, CarbonInterface $start, CarbonInterface $end, ?string $ignore = null): array
    {
        if (! $this->covered($technicianId)) {
            return [];
        }
        $busy = MymarBookingRequest::query()->where('cofrac_technician_id', $technicianId)
            ->whereIn('status', ['queued', 'dispatching', 'uncertain'])->where('reserved_starts_at', '<', $end)->where('reserved_ends_at', '>', $start)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore))->exists();

        return $busy ? [['field' => 'starts_at', 'type' => 'mymar_pending', 'message' => 'Une confirmation MAR est encore en cours sur ce créneau. Ne renouvelez pas la demande.']] : [];
    }

    public function liveConflicts(int $technicianId, CarbonInterface $start, CarbonInterface $end): array
    {
        $sourceId = $this->covered($technicianId);
        if ($sourceId === null) {
            return [];
        }
        try {
            $report = (new MymarGatewayClient($this->http, enabled: true, token: $this->pilot->token()))->observe(
                new MymarReadWindow($start->toIso8601String(), $end->toIso8601String(), [$sourceId]),
                new MymarObservationPolicy(timezoneConfirmed: true, durationUnitConfirmed: true, statusScopeConfirmed: true),
                new DateTimeImmutable, [$sourceId => $technicianId]);
            if ($report['issues'] !== []) {
                throw new \RuntimeException('mymar.incomplete_live_window');
            }
            foreach ($report['candidates'] as $period) {
                if (new DateTimeImmutable($period['starts_at']) < $end && new DateTimeImmutable($period['ends_at']) > $start) {
                    return [['field' => 'starts_at', 'type' => 'mymar_overlap', 'message' => 'Ce technicien a déjà un rendez-vous MAR sur ce créneau.']];
                }
            }

            return [];
        } catch (Throwable) {
            return [['field' => 'starts_at', 'type' => 'mymar_unknown', 'message' => 'La disponibilité MAR ne peut pas être vérifiée. Réessayez avant de placer ce rendez-vous.']];
        }
    }

    public function filterSuggestions(array $suggestions): array
    {
        $config = $this->settings->load();
        if (! ($config['guard_enabled'] ?? false) || $suggestions === []) {
            return $suggestions;
        }
        try {
            $pilot = $this->pilot->load();
            $values = Cache::many([$pilot->cacheKey(), $pilot->cacheKey().':failure']);
            $snapshot = MymarOccupancySnapshot::fromCache($pilot, $values[$pilot->cacheKey()] ?? null, isset($values[$pilot->cacheKey().':failure']), new DateTimeImmutable);

            return array_values(array_filter($suggestions, function (array $suggestion) use ($snapshot, $config): bool {
                $id = (int) ($suggestion['extendedProps']['technician_id'] ?? 0);
                if (! in_array($id, array_column($config['bindings'], 'cofrac_user_id'), true)) {
                    return true;
                }
                $result = $snapshot->inspect($id, new DateTimeImmutable($suggestion['start']), new DateTimeImmutable($suggestion['end']));

                return $result['known'] && $result['overlaps'] === [];
            }));
        } catch (Throwable) {
            $mapped = array_column($config['bindings'], 'cofrac_user_id');

            return array_values(array_filter($suggestions, fn ($item) => ! in_array((int) ($item['extendedProps']['technician_id'] ?? 0), $mapped, true)));
        }
    }

    private function covered(int $nativeId): ?int
    {
        $config = $this->settings->load();
        if (config('crm.v2_enabled') && ($config['guard_enabled'] ?? false)) {
            foreach ($config['bindings'] as $binding) {
                if ($binding['cofrac_user_id'] === $nativeId) {
                    return $binding['source_technician_id'];
                }
            }
        }

        return null;
    }
}
