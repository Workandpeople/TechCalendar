<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCAppointment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index()
    {
        // Simple vue renvoyée
        return view('tech.dashboard');
    }

    public function getAppointments()
    {
        $user = Auth::user();
        Log::info('[DashboardController] getAppointments() - user_id = '.$user->id);
    
        // 1) Récupérer le "tech" correspondant à ce user (s’il existe)
        $tech = $user->tech; // ou WAPetGCTech::where('user_id', $user->id)->first();
    
        if (!$tech) {
            Log::warning('[DashboardController] Aucune fiche tech associée à user_id='.$user->id);
            $appointments = collect();
        } else {
            // 2) Sélectionner les RDV du tech
            Log::info('[DashboardController] Fiche tech trouvée: tech_id='.$tech->id);
            $appointments = WAPetGCAppointment::where('tech_id', $tech->id)->get();
        }
    
        Log::info('[DashboardController] Nombre de RDV trouvés = '.$appointments->count());
        Log::debug('[DashboardController] Détails RDV = ', $appointments->toArray());
    
        $events = $appointments->map(function ($appointment) {
            return [
                'title' => "{$appointment->client_fname} {$appointment->client_lname}",
                'start' => $appointment->start_at,
                'end'   => $appointment->end_at,
                'extendedProps' => [
                    'adresse'    => $appointment->client_adresse,
                    'durée'      => "{$appointment->duration} minutes",
                    'commentaire'=> $appointment->comment ?? 'Non spécifié',
                ],
            ];
        });
    
        Log::info('[DashboardController] Events envoyés à FullCalendar : '.$events->count());
    
        return response()->json($events);
    }
}