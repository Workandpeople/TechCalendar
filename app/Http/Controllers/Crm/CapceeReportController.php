<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CapceeReportRequest;
use App\Models\CapceeDelivery;
use App\Models\CapceeReport;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Services\Capcee\CapceeApiException;
use App\Services\Capcee\CapceeReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CapceeReportController extends Controller
{
    public function create(Dossier $dossier, DossierOperation $operation, CapceeReports $reports, ?CapceeReport $report = null): View
    {
        Gate::authorize('transmitReport', $dossier);
        $reports->assertScope($dossier, $operation, $report);
        $operation->load('service');
        $report?->load('latestDelivery');

        return view('crm.capcee.form', compact('dossier', 'operation', 'report') + ['capceeUnavailable' => $reports->availability()]);
    }

    public function store(CapceeReportRequest $request, Dossier $dossier, DossierOperation $operation, CapceeReports $reports, ?CapceeReport $report = null): RedirectResponse
    {
        try {
            $reports->submit($request->user(), $dossier, $operation, $request->validated(), $request->file('report_file'), $report);
        } catch (CapceeApiException $exception) {
            throw ValidationException::withMessages(['capcee' => $exception->getMessage()]);
        }

        return redirect(route('v2.dossiers.show', $dossier).'#capcee')->with('status', 'Rapport enregistré pour transmission en arrière-plan. Le suivi ci-dessous confirme sa réception et son rattachement.');
    }

    public function retry(Request $request, Dossier $dossier, CapceeDelivery $delivery, CapceeReports $reports): RedirectResponse
    {
        try {
            $reports->retry($request->user(), $dossier, $delivery);
        } catch (CapceeApiException $exception) {
            throw ValidationException::withMessages(['capcee' => $exception->getMessage()]);
        }

        return redirect(route('v2.dossiers.show', $dossier).'#capcee')->with('status', 'Reprise mise en attente à la dernière étape confirmée.');
    }

    public function status(Dossier $dossier, CapceeReports $reports): JsonResponse
    {
        Gate::authorize('view', $dossier);
        $dossier->load('operations.service', 'operations.capceeReports.latestDelivery');

        return response()->json([
            'html' => view('crm.capcee.reports', ['dossier' => $dossier, 'capceeUnavailable' => $reports->availability()])->render(),
            'active' => $dossier->operations->contains(fn ($operation) => $operation->capceeReports->contains(fn ($report) => $report->latestDelivery?->active())),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function download(Dossier $dossier, CapceeDelivery $delivery): StreamedResponse
    {
        Gate::authorize('view', $dossier);
        abort_unless($delivery->report->operation->dossier_id === $dossier->id, 404);
        abort_unless(str_starts_with($delivery->path, 'crm/capcee/'.$delivery->capcee_report_id.'/')
            && ! str_contains($delivery->path, '..') && Storage::disk('local')->exists($delivery->path), 404);

        return Storage::disk('local')->download($delivery->path, 'Rapport-'.$delivery->report->reference.'-V'.$delivery->revision.'.pdf', [
            'Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
