<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCTech;

class OneCalendarController extends Controller
{

    public function singleTechSchedule()
    {
        // Charger tous les techniciens pour la recherche dynamique
        $technicians = WAPetGCTech::with('user')->get();

        return view('assistant.single_tech_schedule', compact('technicians'));
    }

    public function searchTechnicians(Request $request)
    {
        $query = $request->input('query', '');

        $technicians = WAPetGCTech::whereHas('user', function ($q) use ($query) {
            $q->where('prenom', 'LIKE', "%$query%")
            ->orWhere('nom', 'LIKE', "%$query%");
        })->with('user')->get();

        return response()->json($technicians->map(function ($tech) {
            return [
                'id' => $tech->id,
                'prenom' => $tech->user->prenom,
                'nom' => $tech->user->nom,
            ];
        }));
    }

    public function getTechAppointments(Request $request)
    {
        $techId = $request->input('tech_id');

        $appointments = WAPetGCAppointment::where('tech_id', $techId)->with('tech.user')->get();

        $events = $appointments->map(function ($appointment) {
            return [
                'title' => "{$appointment->client_fname} {$appointment->client_lname}",
                'start' => $appointment->start_at,
                'end' => $appointment->end_at,
                'extendedProps' => [
                    'adresse' => $appointment->client_adresse,
                    'durée' => "{$appointment->duration} minutes",
                    'commentaire' => $appointment->comment ?? 'Non spécifié',
                ],
            ];
        });

        return response()->json($events);
    }
}
