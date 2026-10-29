<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planner\BlockTechnicianAbsencesRequest;
use App\Models\TechnicianAbsence;
use App\Models\User;
use App\Services\Crm\AppointmentDateTime;
use App\Services\TechnicianAbsenceBlocking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PlannerAbsenceController extends Controller
{
    public function technicians(TechnicianAbsenceBlocking $blocking): JsonResponse
    {
        Gate::authorize('create', TechnicianAbsence::class);

        $technicians = $blocking->eligibleTechnicians()->with('departments:code')
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'role', 'department_code'])
            ->map(fn (User $technician): array => ['id' => $technician->id, 'label' => $technician->full_name_with_departments]);

        return response()->json([
            'technicians' => $technicians,
            'max_technicians' => TechnicianAbsenceBlocking::MAX_TECHNICIANS,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(BlockTechnicianAbsencesRequest $request, TechnicianAbsenceBlocking $blocking): JsonResponse
    {
        $absences = $blocking->block($request->user(), $request->validated());

        return response()->json([
            'message' => $absences->count().' blocage(s) créé(s). Aucun RDV modifié et aucun email envoyé.',
            'count' => $absences->count(),
            'absences' => $absences->map(fn (TechnicianAbsence $absence): array => [
                'id' => $absence->id,
                'technician_id' => $absence->technician_id,
                'starts_at' => AppointmentDateTime::formatStored($absence->starts_at),
                'ends_at' => AppointmentDateTime::formatStored($absence->ends_at),
                'reason' => $absence->reason,
            ]),
        ], 201);
    }
}
