<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\DossierOperation;
use App\Services\Crm\AppointmentDateTime;
use App\Services\Crm\DossierAppointmentRescheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DossierAppointmentRescheduleController extends Controller
{
    public function show(Request $request, Dossier $dossier, DossierOperation $operation, DossierAppointmentRescheduler $rescheduler): JsonResponse
    {
        return response()->json($rescheduler->context($request->user(), $dossier, $operation))
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Dossier $dossier, DossierOperation $operation, DossierAppointmentRescheduler $rescheduler): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'appointment_id' => ['present', 'nullable', 'integer', 'min:1'],
            'updated_at' => ['present', 'nullable', 'date'],
            'technician_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'force_placement' => ['sometimes', 'boolean'],
            'force_confirmed' => [Rule::excludeIf(! $request->boolean('force_placement')), 'required', 'accepted'],
        ]);
        $appointment = $rescheduler->reschedule($request->user(), $dossier, $operation, $data);

        return response()->json([
            'message' => 'RDV replacé. Le dossier est en attente de visite.',
            'dossier_id' => $dossier->id, 'operation_id' => $operation->id, 'version' => $dossier->fresh()->version,
            'created' => $data['appointment_id'] === null, 'service' => $operation->service->name, 'address' => $appointment->address,
            'appointments_count' => $operation->appointments()->count(), 'dossier_appointments_count' => $dossier->appointments()->withTrashed()->count(),
            'appointment_id' => $appointment->id, 'updated_at' => $appointment->updated_at?->toIso8601String(),
            'technician_id' => $appointment->technician_id, 'technician_name' => $appointment->technician->full_name,
            'starts_at' => AppointmentDateTime::formatStored($appointment->starts_at),
            'ends_at' => AppointmentDateTime::formatStored($appointment->ends_at),
            'duration_minutes' => $appointment->duration_minutes,
            'planning_url' => route('planner.tracking', ['appointment_id' => $appointment->id,
                'technician_id' => $appointment->technician_id, 'date' => $appointment->starts_at->toDateString()], absolute: false),
        ])->header('Cache-Control', 'private, no-store');
    }
}
