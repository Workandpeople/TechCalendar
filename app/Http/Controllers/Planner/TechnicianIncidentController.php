<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\ReviewTechnicianIncidentRequest;
use App\Http\Requests\Tech\TechnicianIncidentListRequest;
use App\Models\TechnicianIncident;
use App\Services\Tech\TechnicianIncidentInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TechnicianIncidentController extends Controller
{
    public function index(TechnicianIncidentListRequest $request, TechnicianIncidentInbox $inbox): View
    {
        $filters = $request->validated();

        return view('planner.incidents', ['incidents' => $inbox->search($request->user(), $filters),
            'filters' => $filters, 'counts' => $inbox->counts($request->user())]);
    }

    public function counts(Request $request, TechnicianIncidentInbox $inbox): JsonResponse
    {
        return response()->json($inbox->counts($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function update(ReviewTechnicianIncidentRequest $request, TechnicianIncident $incident, TechnicianIncidentInbox $inbox): RedirectResponse
    {
        $inbox->update($request->user(), $incident, $request->validated());

        return back()->with('status', 'Suivi du signalement enregistré. Aucune facture créée.');
    }
}
