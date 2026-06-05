<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemErrorEvent;
use App\Models\SystemHealthSnapshot;
use App\Services\SystemHealthMonitor;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, SystemHealthMonitor $healthMonitor): View
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $latestSnapshot = SystemHealthSnapshot::query()
            ->with('checks')
            ->latest('checked_at')
            ->first();

        if (! $latestSnapshot) {
            $latestSnapshot = $healthMonitor->run();
        }

        $recentSnapshots = SystemHealthSnapshot::query()
            ->latest('checked_at')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();
        $recentErrors = SystemErrorEvent::query()
            ->latest('last_seen_at')
            ->limit(8)
            ->get();

        return view('admin.dashboard', [
            'latestSnapshot' => $latestSnapshot,
            'checks' => $latestSnapshot->checks->sortBy(fn ($check) => ['fail' => 0, 'warn' => 1, 'ok' => 2][$check->status] ?? 3)->values(),
            'recentErrors' => $recentErrors,
            'stats' => [
                [
                    'label' => 'Score sante',
                    'value' => $latestSnapshot->score.'/100',
                    'detail' => 'Dernier check '.$latestSnapshot->checked_at->diffForHumans(),
                    'tone' => $latestSnapshot->overall_status,
                ],
                [
                    'label' => 'Checks en erreur',
                    'value' => $latestSnapshot->checks->where('status', 'fail')->count(),
                    'detail' => $latestSnapshot->checks->where('status', 'warn')->count().' warning(s)',
                    'tone' => $latestSnapshot->checks->where('status', 'fail')->isNotEmpty() ? 'fail' : 'ok',
                ],
                [
                    'label' => 'Erreurs 24h',
                    'value' => SystemErrorEvent::query()->where('last_seen_at', '>=', now()->subDay())->sum('occurrences'),
                    'detail' => $recentErrors->count().' signature(s) recentes',
                    'tone' => $recentErrors->isNotEmpty() ? 'warn' : 'ok',
                ],
                [
                    'label' => 'Checks historises',
                    'value' => SystemHealthSnapshot::query()->count(),
                    'detail' => 'Snapshots de monitoring',
                    'tone' => 'ok',
                ],
            ],
            'charts' => [
                'scoreTrend' => $recentSnapshots->map(fn (SystemHealthSnapshot $snapshot): array => [
                    'label' => $snapshot->checked_at->format('H:i'),
                    'value' => $snapshot->score,
                    'status' => $snapshot->overall_status,
                ]),
                'statusSplit' => collect(['ok', 'warn', 'fail'])->map(fn (string $status): array => [
                    'label' => strtoupper($status),
                    'value' => $latestSnapshot->checks->where('status', $status)->count(),
                ]),
            ],
        ]);
    }
}
