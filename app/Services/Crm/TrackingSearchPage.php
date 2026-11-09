<?php

namespace App\Services\Crm;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class TrackingSearchPage
{
    public function paginate(Builder $nativeQuery, array $marEvents, int $page, int $limit, string $sort = 'starts_desc'): array
    {
        $direction = str_ends_with($sort, '_asc') ? 1 : -1;
        $column = str_starts_with($sort, 'duration_') ? 'duration_minutes' : 'starts_at';
        $order = $direction === 1 ? 'asc' : 'desc';
        $total = (clone $nativeQuery)->count() + count($marEvents);
        $offset = ($page - 1) * $limit;
        // At most all cached MAR rows can precede this page. Never load the full native history.
        $nativeOffset = max(0, $offset - count($marEvents));
        $nativeRows = (clone $nativeQuery)->withoutEagerLoads()->select(['appointments.id', 'appointments.starts_at', 'appointments.duration_minutes'])
            ->reorder()->orderBy($column, $order)->orderBy('starts_at', $order)->orderByDesc('id')->offset($nativeOffset)->limit($limit + count($marEvents))->get();
        $rows = $nativeRows->map(fn ($row) => ['id' => $row->id, 'time' => $row->starts_at?->getTimestamp() ?? PHP_INT_MIN,
            'sort' => $column === 'duration_minutes' ? $row->duration_minutes : $row->starts_at?->getTimestamp() ?? PHP_INT_MIN, 'native' => 1])->all();
        foreach ($marEvents as $event) {
            $time = CarbonImmutable::parse($event['start'])->getTimestamp();
            $rows[] = ['id' => $event['extendedProps']['source_rdv_id'], 'time' => $time,
                'sort' => $column === 'duration_minutes' ? ($event['extendedProps']['duration_minutes'] ?? 0) : $time, 'native' => 0, 'event' => $event];
        }
        usort($rows, fn ($a, $b) => $direction * ([$a['sort'], $a['time']] <=> [$b['sort'], $b['time']])
            ?: [$b['native'], $b['id']] <=> [$a['native'], $a['id']]);
        $rows = array_slice($rows, $offset - $nativeOffset, $limit);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit, 'last_page' => max(1, (int) ceil($total / $limit))];
    }
}
