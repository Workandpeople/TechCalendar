<?php

namespace App\Services\Mymar;

use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class MymarCalendarSearch
{
    public function __construct(private readonly MymarPilotSettings $settings, private readonly MymarCalendarFeed $feed) {}

    public function search(array $filters): array
    {
        $empty = ['events' => [], 'state' => 'disabled', 'message' => ''];
        if (! config('crm.v2_enabled')) {
            return $empty;
        }
        try {
            $pilot = $this->settings->load();
            if (! $pilot->displayEnabled) {
                return $empty;
            }
            $snapshot = Cache::get($pilot->cacheKey());
            if (! is_array($snapshot)) {
                throw new \RuntimeException('Missing MYMAR snapshot');
            }
            MymarCachedObservation::validate($snapshot, $pilot);
            $feed = $this->feed->window(($filters['technician_ids'] ?? []) ?: array_values($pilot->technicianMap),
                MymarCachedObservation::instant($snapshot['window_start']), MymarCachedObservation::instant($snapshot['window_end']));
            $service = empty($filters['service_id']) ? null : Service::query()->find($filters['service_id']);
            $terms = preg_split('/\s+/u', Str::lower(Str::ascii(trim($filters['q'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $from = empty($filters['date_from']) ? null : CarbonImmutable::parse($filters['date_from'], 'Europe/Paris')->startOfDay();
            $to = empty($filters['date_to']) ? null : CarbonImmutable::parse($filters['date_to'], 'Europe/Paris')->endOfDay();
            $events = array_values(array_filter($feed['events'], function (array $event) use ($filters, $service, $terms, $from, $to): bool {
                $props = $event['extendedProps'];
                if (($filters['appointment_status'] ?? 'all') === 'problem' || ($service && ($service->type !== Service::TYPE_MAR || $props['visit_phase'] !== 'after_works'))) {
                    return false;
                }
                $start = CarbonImmutable::parse($event['start']);
                if (($from && $start < $from) || ($to && $start > $to)) {
                    return false;
                }
                $text = Str::lower(Str::ascii(implode(' ', [$event['id'], $props['customer_name'], $props['address'],
                    $props['postal_code'], $props['city'], $props['technician_name'], $props['service_label']])));
                foreach ($terms as $term) {
                    if (! str_contains($text, $term)) {
                        return false;
                    }
                }

                return true;
            }));
            $coverage = 'RDV MAR recherchés dans la période synchronisée du '
                .CarbonImmutable::parse($snapshot['window_start'])->tz('Europe/Paris')->format('d/m/Y').' au '
                .CarbonImmutable::parse($snapshot['window_end'])->tz('Europe/Paris')->subSecond()->format('d/m/Y').'.';
            $state = $feed['state'];
            if (! in_array($state, ['observing', 'partial'], true)) {
                $coverage .= ' Synchronisation à vérifier : les résultats MAR peuvent être incomplets.';
            } elseif (collect($feed['events'])->contains(fn ($event) => $event['extendedProps']['customer_name'] === '')) {
                $coverage .= ' Certains noms/adresses MAR ne sont pas encore synchronisés.';
            }

            return ['events' => $events, 'state' => $state, 'message' => $coverage];
        } catch (Throwable) {
            return ['events' => [], 'state' => 'unavailable', 'message' => 'La recherche MAR est temporairement indisponible. Les résultats Cofrac restent consultables.'];
        }
    }
}
