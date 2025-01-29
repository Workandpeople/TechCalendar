<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\WAPetGCAppointment;

class TechCalendarController extends Controller
{
    /**
     * Affichage du calendrier des rendez-vous du technicien connecté.
     */
    public function index()
    {
        // Récupérer l'ID du technicien connecté
        $techId = Auth::user()->tech->id ?? null;

        return view('techCalendar', compact('techId'));
    }

    /**
     * Récupérer les rendez-vous du technicien connecté via AJAX.
     */
    public function getAppointments(Request $request)
    {
        try {
            // Récupérer l'ID du technicien connecté
            $techId = Auth::user()->tech->id ?? null;

            if (!$techId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun technicien associé à votre compte.',
                ], 403);
            }

            // Récupérer les rendez-vous du technicien connecté
            $appointments = WAPetGCAppointment::where('tech_id', $techId)
                ->with(['service', 'tech.user'])
                ->get()
                ->map(function ($appoint) {
                    return [
                        'id' => $appoint->id,
                        'title' => $appoint->client_fname . ' ' . $appoint->client_lname,
                        'start' => $appoint->start_at,
                        'end' => $appoint->end_at,
                        'backgroundColor' => '#'.substr(md5($appoint->tech_id), 0, 6),
                        'extendedProps' => [
                            'techName' => optional($appoint->tech->user)->prenom . ' ' . optional($appoint->tech->user)->nom,
                            'serviceName' => optional($appoint->service)->name ?? 'Non spécifié',
                            'comment' => $appoint->comment ?? 'Aucun commentaire',
                            'clientAddress' => trim("{$appoint->client_adresse}, {$appoint->client_zip_code} {$appoint->client_city}"),
                        ]
                    ];
                });

            return response()->json([
                'success' => true,
                'appointments' => $appointments,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne du serveur',
            ], 500);
        }
    }
}
