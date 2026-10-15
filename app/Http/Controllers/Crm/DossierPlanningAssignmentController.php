<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Services\Crm\DossierPlanningAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DossierPlanningAssignmentController extends Controller
{
    public function __invoke(Request $request, Dossier $dossier, DossierPlanningAssignment $assignment): JsonResponse
    {
        Gate::authorize('update', $dossier);
        $data = $request->validate([
            'planning_user_id' => ['present', 'nullable', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $dossier = $assignment->assign($request->user(), $dossier,
            $data['planning_user_id'] === null ? null : (int) $data['planning_user_id'], (int) $data['version']);

        return response()->json([
            'planning_user_id' => $dossier->planning_user_id,
            'version' => $dossier->version,
            'message' => $dossier->planning_user_id === null ? 'Affectation retirée.' : 'Affectation enregistrée.',
        ])->header('Cache-Control', 'private, no-store');
    }
}
