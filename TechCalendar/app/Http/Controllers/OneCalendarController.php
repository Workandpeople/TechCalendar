<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCTech;
use Illuminate\Support\Facades\Log;

class OneCalendarController extends Controller
{

    public function singleTechSchedule()
    {
        Log::info('[OneCalendarController] Entering singleTechSchedule()');
        $technicians = WAPetGCTech::with('user')->get();
        Log::info('[OneCalendarController] Technicians loaded', [
            'count' => $technicians->count()
        ]);

        return view('assistant.single_tech_schedule', compact('technicians'));
    }

    public function searchTechnicians(Request $request)
    {
        Log::info('[OneCalendarController] Entering searchTechnicians()');
        $query = $request->input('query', '');
        Log::info('[OneCalendarController] search query = '.$query);

        $technicians = WAPetGCTech::whereHas('user', function ($q) use ($query) {
            $q->where('prenom', 'LIKE', "%$query%")
              ->orWhere('nom', 'LIKE', "%$query%");
        })->with('user')->get();

        Log::info('[OneCalendarController] Found technicians', [
            'count' => $technicians->count()
        ]);

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
        Log::info('[OneCalendarController] Entering getTechAppointments()');
        $techId = $request->input('tech_id');
        Log::info('[OneCalendarController] tech_id = '.$techId);

        if (!$techId) {
            Log::warning('[OneCalendarController] No tech_id provided');
        }

        $appointments = WAPetGCAppointment::where('tech_id', $techId)
            ->with('tech.user')
            ->get();

        Log::info('[OneCalendarController] Appointments found', [
            'count' => $appointments->count()
        ]);

        $events = $appointments->map(function ($appointment) {
            // Concaténer l'adresse complète
            $fullAddress = trim("{$appointment->client_adresse}, {$appointment->client_zip_code} {$appointment->client_city}");

            return [
                'title' => "{$appointment->client_fname} {$appointment->client_lname}",
                'start' => $appointment->start_at,
                'end'   => $appointment->end_at,
                'extendedProps' => [
                    'fullAddress' => $fullAddress ?: 'Non spécifiée',
                    'durée'       => "{$appointment->duration} minutes",
                    'commentaire' => $appointment->comment ?? 'Non spécifié',
                ],
            ];
        });

        Log::info('[OneCalendarController] Events response', [
            'events' => $events->toArray()
        ]);

        return response()->json($events);
    }
}