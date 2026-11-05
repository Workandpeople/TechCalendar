<?php

namespace App\Services\Crm;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class TrackingSearchPage
{
    public function paginate(Builder $nativeQuery, array $marEvents, int $page, int $limit): array
    {
        $total = (clone $nativeQuery)->count() + count($marEvents);
        $offset = ($page - 1) * $limit;
        // At most all cached MAR rows can precede this page. Never load the full native history.
        $nativeOffset = max(0, $offset - count($marEvents));
        $nativeRows = (clone $nativeQuery)->withoutEagerLoads()->select(['appointments.id', 'appointments.starts_at'])
            ->reorder()->orderByDesc('starts_at')->orderByDesc('id')->offset($nativeOffset)->limit($limit + count($marEvents))->get();
        $rows = $nativeRows->map(fn ($row) => ['id' => $row->id, 'time' => $row->starts_at?->getTimestamp() ?? PHP_INT_MIN, 'native' => 1])->all();
        foreach ($marEvents as $event) {
            $rows[] = ['id' => $event['extendedProps']['source_rdv_id'], 'time' => CarbonImmutable::parse($event['start'])->getTimestamp(), 'native' => 0, 'event' => $event];
        }
        usort($rows, fn ($a, $b) => [$b['time'], $b['native'], $b['id']] <=> [$a['time'], $a['native'], $a['id']]);
        $rows = array_slice($rows, $offset - $nativeOffset, $limit);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit, 'last_page' => max(1, (int) ceil($total / $limit))];
    }
}
