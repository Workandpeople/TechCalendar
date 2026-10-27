<?php

namespace App\Http\Controllers\Planner;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Services\Crm\AppointmentCancellation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlannerAppointmentCancellationController extends Controller
{
    public function destroy(Request $request, int $appointment, AppointmentCancellation $cancellation): JsonResponse
    {
        abort_unless(config('crm.v2_enabled'), 404);
        Gate::authorize('viewAny', Dossier::class);
        $data = $request->validate(['updated_at' => ['present', 'nullable', 'date'], 'confirmed' => ['required', 'accepted']]);
        $cancellation->cancel($request->user(), $appointment, $data['updated_at']);

        return response()->json(['message' => 'Rendez-vous supprimé du planning et remis en prise de RDV.', 'appointment_id' => $appointment]);
    }
}
