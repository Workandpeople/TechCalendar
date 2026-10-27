<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Services\Crm\DossierTechnicianAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DossierAppointmentController extends Controller
{
    public function update(Request $request, Dossier $dossier, int $appointment, DossierTechnicianAssignment $assignment): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $dossier);
        $data = $request->validate(['technician_id' => ['required', 'integer', 'exists:users,id'],
            'version' => ['required', 'integer', 'min:1'], 'updated_at' => ['present', 'nullable', 'date'],
            'force_placement' => ['sometimes', 'boolean'],
            'force_confirmed' => [Rule::excludeIf(! $request->boolean('force_placement')), 'required', 'accepted']]);
        $updated = $assignment->update($request->user(), $dossier, $appointment, $data);
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Technicien modifié.', 'version' => $dossier->fresh()->version,
                'appointment_id' => $updated->id, 'updated_at' => $updated->updated_at?->toIso8601String(),
                'technician_id' => (int) $updated->technician_id, 'technician_name' => $updated->technician->full_name]);
        }

        return redirect(route('v2.dossiers.show', $dossier).'#rendez-vous')->with('status', 'Technicien modifié. Le créneau et les disponibilités ont été vérifiés.');
    }
}
