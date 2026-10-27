<?php

namespace App\Http\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Tech\TechnicianDocuments;
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
        ]);
    }

    public function download(Request $request, Appointment $appointment, string $kind, int $document, TechnicianDocuments $documents): Response
    {
        return $documents->download($request->user(), $appointment, $kind, $document, $request->boolean('preview'));
    }
}
