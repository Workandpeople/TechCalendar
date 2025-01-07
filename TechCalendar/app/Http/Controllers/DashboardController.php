<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCAppointment;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        return view('tech.dashboard');
    }

    public function getAppointments()
    {
        // Récupérer l'utilisateur connecté
        $user = Auth::user();

        // Charger les rendez-vous du technicien connecté
        $appointments = WAPetGCAppointment::where('tech_id', $user->id)->get();

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