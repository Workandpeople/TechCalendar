<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\CoffracAppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MobileAppointmentRefreshController extends Controller
{
    public function __invoke(
        Request $request,
        Appointment $appointment,
        CoffracAppointmentService $coffracAppointments,
    ): JsonResponse {
        $technician = $request->user();

        abort_unless(
            $technician && (int) $appointment->technician_id === (int) $technician->id,
            403,
            'Ce rendez-vous ne fait pas partie de ton planning.'
        );

        if ($appointment->external_source !== CoffracAppointmentService::SOURCE || ! filled($appointment->external_reference)) {
            throw ValidationException::withMessages([
                'appointment' => 'Ce rendez-vous n’est pas rattaché à un dossier Coffrac.',
            ]);
        }

        try {
            $refresh = $coffracAppointments->refreshAppointment($appointment);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'appointment' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Documents Coffrac mis à jour.',
            'documents' => $refresh['documents'],
            'status' => $refresh['status'],
            'remote_status_name' => $refresh['remote_status_name'],
            'fetched_at' => $refresh['fetched_at'],
        ]);
    }
}
