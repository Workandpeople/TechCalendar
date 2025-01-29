<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\WAPetGCAppointment;
use Carbon\Carbon;

class TechDashboardController extends Controller
{
    /**
     * Affichage du tableau de bord du technicien.
     */
    public function index()
    {
        // Récupérer l'utilisateur connecté
        $user = Auth::user();
        $techId = $user->tech->id ?? null;

        // Vérification si l'utilisateur est un technicien
        if (!$techId) {
            return view('techDashboard', ['techId' => null]);
        }

        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // Comptage des rendez-vous pour les statistiques
        $rdvEffectuesAujd = WAPetGCAppointment::where('tech_id', $techId)
            ->whereDate('start_at', $today)
            ->where('start_at', '<', now())
            ->count();

        $rdvAVenirAujd = WAPetGCAppointment::where('tech_id', $techId)
            ->whereDate('start_at', $today)
            ->where('start_at', '>=', now())
            ->count();

        $rdvEffectuesMois = WAPetGCAppointment::where('tech_id', $techId)
            ->whereBetween('start_at', [$startOfMonth, $endOfMonth])
            ->where('start_at', '<', now())
            ->count();

        $rdvAVenirMois = WAPetGCAppointment::where('tech_id', $techId)
            ->whereBetween('start_at', [$startOfMonth, $endOfMonth])
            ->where('start_at', '>=', now())
            ->count();

        return view('tech-dashboard', compact(
            'techId', 'rdvEffectuesAujd', 'rdvAVenirAujd', 'rdvEffectuesMois', 'rdvAVenirMois'
        ));
    }

    /**
     * Récupérer les rendez-vous du technicien connecté pour le mois en cours via AJAX.
     */
    public function getAppointments(Request $request)
    {
        $user = Auth::user();
        $techId = $user->tech->id ?? null;

        if (!$techId) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas un technicien.',
            ], 403);
        }

        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $appointments = WAPetGCAppointment::where('tech_id', $techId)
            ->whereBetween('start_at', [$startOfMonth, $endOfMonth])
            ->with(['service', 'tech.user'])
            ->get()
            ->map(function ($appoint) {
                return [
                    'id' => $appoint->id,
                    'title' => $appoint->client_fname . ' ' . $appoint->client_lname,
                    'start' => $appoint->start_at,
                    'end' => $appoint->end_at,
                    'backgroundColor' => '#' . substr(md5($appoint->tech_id), 0, 6),
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
    }
}
