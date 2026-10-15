<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Services\Crm\DossierPlanning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DossierPlanningController extends Controller
{
    public function index(Request $request, Dossier $dossier, DossierOperation $operation, DossierPlanning $planning): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'date' => ['nullable', 'date_format:Y-m-d']]);

        return view('crm.dossiers.appointments', [
            'dossier' => $dossier, 'operation' => $operation->load('service'), 'filters' => $filters,
            'appointments' => $planning->search($request->user(), $dossier, $operation, $filters),
        ]);
    }

    public function store(Request $request, Dossier $dossier, DossierOperation $operation, DossierPlanning $planning): RedirectResponse
    {
        $data = $request->validate(['appointment_id' => ['required', 'integer', 'min:1'], 'version' => ['required', 'integer', 'min:1']]);
        $planning->link($request->user(), $dossier, $operation, $data['appointment_id'], $data['version']);

        return redirect()->to(route('v2.dossiers.show', $dossier).'#rendez-vous')->with('status', 'Rendez-vous rattaché. Son créneau, ses données et ses liens CRM sont conservés.');
    }

    public function destroy(Request $request, Dossier $dossier, DossierOperation $operation, int $appointment, DossierPlanning $planning): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $planning->unlink($request->user(), $dossier, $operation, $appointment, $data['version']);

        return redirect()->to(route('v2.dossiers.show', $dossier).'#rendez-vous')->with('status', 'Liaison retirée. Le rendez-vous existe toujours dans le planning et dans son lot.');
    }
}
