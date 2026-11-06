<?php

namespace App\Http\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\StoreOnSiteReportRequest;
use App\Models\Appointment;
use App\Services\Tech\TechnicianDocuments;
use App\Services\Tech\TechnicianOnSiteReports;
use App\Services\Tech\TechnicianWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TechAppointmentController extends Controller
{
    public function show(Request $request, Appointment $appointment, TechnicianDocuments $documents, TechnicianWorkspace $workspace): View
    {
        Gate::authorize('view', $appointment);
        $filters = $request->validate(['document_q' => ['nullable', 'string', 'max:120']]);

        return view('tech.appointment', [
            ...$documents->page($request->user(), $appointment, trim($filters['document_q'] ?? '')),
            'appointment' => $appointment->loadMissing('service', 'dossierOperation.dossier'),
            'card' => $workspace->card($appointment),
            'onSiteSnapshot' => app(TechnicianOnSiteReports::class)->snapshot($appointment),
        ]);
    }

    public function report(StoreOnSiteReportRequest $request, Appointment $appointment, TechnicianOnSiteReports $reports): Response
    {
        $reports->submit($request->user(), $appointment, $request->validated(), $request->file('photo'));
        $message = 'Signalement enregistré. La photo et le commentaire sont ajoutés au dossier ; le RDV est en problème. Les notifications suivent les accès et préférences des destinataires.';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'url' => route('tech.appointments.show', $appointment)])
            : redirect(route('tech.appointments.show', $appointment))->with('status', $message);
    }

    public function download(Request $request, Appointment $appointment, string $kind, int $document, TechnicianDocuments $documents): Response
    {
        return $documents->download($request->user(), $appointment, $kind, $document, $request->boolean('preview'));
    }
}
