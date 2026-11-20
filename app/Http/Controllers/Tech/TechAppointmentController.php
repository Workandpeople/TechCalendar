<?php

namespace App\Http\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\StoreOnSiteReportRequest;
use App\Models\Appointment;
use App\Services\Tech\OnSiteVisitContext;
use App\Services\Tech\TechnicianDocuments;
use App\Services\Tech\TechnicianOnSiteReports;
use App\Services\Tech\TechnicianWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class TechAppointmentController extends Controller
{
    public function show(Request $request, Appointment $appointment, TechnicianDocuments $documents, TechnicianWorkspace $workspace): Response
    {
        Gate::authorize('view', $appointment);
        $filters = $request->validate(['document_q' => ['nullable', 'string', 'max:120']]);

        return response()->view('tech.appointment', [
            ...$documents->page($request->user(), $appointment, trim($filters['document_q'] ?? '')),
            'appointment' => $appointment->loadMissing('service', 'dossierOperation.dossier'),
            'card' => $workspace->card($appointment),
            'onSiteSnapshot' => app(TechnicianOnSiteReports::class)->snapshot($appointment),
            'onSiteContext' => app(OnSiteVisitContext::class)->issue($appointment),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function report(StoreOnSiteReportRequest $request, Appointment $appointment, TechnicianOnSiteReports $reports): Response
    {
        $report = $reports->submit($request->user(), $appointment, $request->validated(), $request->file('photo'));
        $message = 'Signalement enregistré. Photo et commentaire client conservés, équipe alertée. Le statut du dossier reste inchangé jusqu’à la décision administrative.';
        if ($report['returned_to_booking'] ?? false) {
            $message = 'Signalement enregistré. La fiche est remise en prise de RDV ; la photo, le commentaire client et l’alerte planning sont conservés.';
        }

        $historical = $request->boolean('evidence_only') || ($report['evidence_only'] ?? false);
        if ($historical) {
            $message = 'Preuve de l’ancien passage enregistrée : photo et commentaire client ajoutés au dossier. Le RDV actuel et son état restent inchangés.';
        }
        $url = $historical ? route('tech.planning') : route('tech.appointments.show', $appointment);
        $request->session()->flash('status', $message);

        return $request->expectsJson() ? response()->json(['message' => $message, 'url' => $url]) : redirect($url);
    }

    public function download(Request $request, Appointment $appointment, string $kind, int $document, TechnicianDocuments $documents): Response
    {
        return $documents->download($request->user(), $appointment, $kind, $document, $request->boolean('preview'));
    }
}
