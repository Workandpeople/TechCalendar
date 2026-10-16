<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Services\Crm\DossierTechnicianAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DossierAppointmentController extends Controller
{
    public function update(Request $request, Dossier $dossier, int $appointment, DossierTechnicianAssignment $assignment): RedirectResponse
    {
        Gate::authorize('update', $dossier);
        $data = $request->validate(['technician_id' => ['required', 'integer', 'exists:users,id'],
            'version' => ['required', 'integer', 'min:1'], 'updated_at' => ['present', 'nullable', 'date']]);
        $assignment->update($request->user(), $dossier, $appointment, $data);

        return redirect(route('v2.dossiers.show', $dossier).'#rendez-vous')->with('status', 'Technicien modifié. Le créneau et les disponibilités ont été vérifiés.');
    }
}
