<?php

declare(strict_types=1);

namespace App\Services\Mymar;

use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Calendar reads use only the local cache. No token loading, HTTP, source SQL, queue dispatch or placement logic. */
class MymarCalendarFeed
{
    public function __construct(private readonly MymarPilotSettings $settings, private readonly MymarActionSettings $actions) {}

    public function window(array $technicianIds, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $empty = ['events' => [], 'state' => 'disabled', 'blocking_enabled' => false];
        if (! config('crm.v2_enabled')) {
            return $empty;
        }
        try {
            $pilot = $this->settings->load();
            if (! $pilot->displayEnabled) {
                return $empty;
            }
            $selected = array_values(array_intersect(array_values($pilot->technicianMap), array_map('intval', $technicianIds)));
            $blocking = (bool) ($this->actions->load()['guard_enabled'] ?? false);
            $meta = ['events' => [], 'state' => 'unavailable', 'blocking_enabled' => $blocking, 'pilot_technicians' => count($pilot->technicianMap),
                'covered_technicians' => count($selected), 'selected_technicians' => count(array_unique($technicianIds))];
            if ($selected === []) {
                return array_replace($meta, ['state' => 'outside_pilot']);
            }
            $values = Cache::many([$pilot->cacheKey(), $pilot->cacheKey().':failure']);
            $snapshot = $values[$pilot->cacheKey()] ?? null;
            if (! is_array($snapshot)) {
                return $meta;
            }
            MymarCachedObservation::validate($snapshot, $pilot);
            $technicians = User::query()->internal()->where('role', 2)->where('admin', false)
                ->whereIn('id', array_values($pilot->technicianMap))->get(['id', 'first_name', 'last_name']);
            if ($technicians->count() !== count($pilot->technicianMap)) {
                return array_replace($meta, ['state' => 'mapping_changed']);
            }
            $names = $technicians->mapWithKeys(fn (User $user) => [$user->id => trim($user->first_name.' '.$user->last_name)])->all();
            $now = new DateTimeImmutable;
            $age = $now->getTimestamp() - MymarCachedObservation::instant($snapshot['generated_at'])->getTimestamp();
            if ($age < -60) {
                return array_replace($meta, ['state' => 'invalid']);
            }
            $state = $age > 300 ? 'stale' : (isset($values[$pilot->cacheKey().':failure']) ? 'degraded' : 'observing');
            $partial = MymarCachedObservation::instant($snapshot['window_start']) > $from || MymarCachedObservation::instant($snapshot['window_end']) < $to;
            if ($state === 'observing' && $partial) {
                $state = 'partial';
            }
            $events = [];
            foreach ($snapshot['periods'] as $period) {
                $start = MymarCachedObservation::instant($period['starts_at_utc']);
                $end = MymarCachedObservation::instant($period['ends_at_utc']);
                if (! in_array($period['cofrac_user_id'], $selected, true) || $start >= $to || $end <= $from) {
                    continue;
                }
                $label = $period['visit_phase'] === 'after_works' ? 'MAR après travaux' : 'MAR visite initiale';
                $events[] = ['id' => 'mymar:'.$period['source_rdv_id'], 'title' => $label.($blocking ? ' · Lecture seule' : ' · Observation'),
                    'start' => $start->setTimezone(new DateTimeZone('Europe/Paris'))->format(DATE_ATOM),
                    'end' => $end->setTimezone(new DateTimeZone('Europe/Paris'))->format(DATE_ATOM),
                    'editable' => false, 'startEditable' => false, 'durationEditable' => false, 'overlap' => true,
                    'backgroundColor' => '#fff7db', 'borderColor' => '#c58615', 'textColor' => '#71450a', 'classNames' => ['mymar-observation-event'],
                    'extendedProps' => ['is_mymar' => true, 'observation_only' => true, 'read_only' => true, 'availability_guard_enabled' => $blocking,
                        'technician_id' => $period['cofrac_user_id'], 'technician_name' => $names[$period['cofrac_user_id']],
                        'technician_full_name' => $names[$period['cofrac_user_id']], 'service_label' => $label, 'service_name' => $label,
                        'customer_name' => $label, 'duration_minutes' => $period['duration_minutes'], 'location_precision' => 'unknown',
                        'synced_at' => $snapshot['generated_at'], 'cache_state' => $state]];
            }

            return array_replace($meta, ['state' => $state, 'events' => $events, 'generated_at' => $snapshot['generated_at'],
                'window_start' => $snapshot['window_start'], 'window_end' => $snapshot['window_end'], 'warnings' => $snapshot['warnings']]);
        } catch (Throwable) {
            return array_replace($empty, ['state' => 'unavailable']);
        }
    }
}
