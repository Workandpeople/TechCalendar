<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunSystemTestsJob;
use App\Models\SystemErrorEvent;
use App\Models\SystemHealthSnapshot;
use App\Models\SystemTestRun;
use App\Services\SystemHealthMonitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
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
        $latestTestRun = SystemTestRun::query()
            ->with('triggeredBy:id,first_name,last_name')
            ->latest('id')
            ->first();
        $recentTestRuns = SystemTestRun::query()
            ->with('triggeredBy:id,first_name,last_name')
            ->latest('id')
            ->limit(5)
            ->get();
        $activeTestRun = SystemTestRun::query()
            ->whereIn('status', [SystemTestRun::STATUS_QUEUED, SystemTestRun::STATUS_RUNNING])
            ->latest('id')
            ->first();

        return view('admin.dashboard', [
            'latestSnapshot' => $latestSnapshot,
            'checks' => $latestSnapshot->checks->sortBy(fn ($check) => ['fail' => 0, 'warn' => 1, 'ok' => 2][$check->status] ?? 3)->values(),
            'recentErrors' => $recentErrors,
            'latestTestRun' => $latestTestRun,
            'recentTestRuns' => $recentTestRuns,
            'activeTestRun' => $activeTestRun,
            'stats' => [
                [
                    'label' => 'Score santé',
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
                    'detail' => $recentErrors->count().' signature(s) récentes',
                    'tone' => $recentErrors->isNotEmpty() ? 'warn' : 'ok',
                ],
                [
                    'label' => 'Checks historisés',
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

    public function clearLogs(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $logsPath = storage_path('logs');
        $clearedFiles = 0;

        foreach (File::glob($logsPath.DIRECTORY_SEPARATOR.'*.log') ?: [] as $path) {
            $realPath = realpath($path);

            if (! $realPath || ! str_starts_with($realPath, realpath($logsPath) ?: $logsPath)) {
                continue;
            }

            File::put($realPath, '');
            $clearedFiles++;
        }

        SystemErrorEvent::query()->delete();

        return back()->with('status', sprintf('%d fichier(s) de log vidé(s).', $clearedFiles));
    }

    public function runTests(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->admin, 403);

        $payload = $request->validate([
            'suite' => ['required', Rule::in([
                SystemTestRun::SUITE_ALL,
                SystemTestRun::SUITE_UNIT,
                SystemTestRun::SUITE_FEATURE,
            ])],
        ]);

        $activeRunExists = SystemTestRun::query()
            ->whereIn('status', [SystemTestRun::STATUS_QUEUED, SystemTestRun::STATUS_RUNNING])
            ->exists();

        if ($activeRunExists) {
            return back()->withErrors([
                'tests' => 'Une exécution de tests est déjà en cours.',
            ]);
        }

        $run = SystemTestRun::query()->create([
            'triggered_by' => $request->user()->id,
            'suite' => $payload['suite'],
            'status' => SystemTestRun::STATUS_QUEUED,
        ]);

        RunSystemTestsJob::dispatch($run->id);

        return back()->with('status', 'Suite de tests ajoutée à la file de traitement.');
    }
}
