<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\WAPetGCAppointment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;


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

        return view('techDashboard', compact(
            'techId', 'rdvEffectuesAujd', 'rdvAVenirAujd', 'rdvEffectuesMois', 'rdvAVenirMois'
        ));
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
                    // Couleur de fond par défaut basée sur le tech_id
                    $bgColor = '#' . substr(md5($appoint->tech_id), 0, 6);
                    $isDeleted = $appoint->deleted_at !== null;
                    if ($isDeleted) {
                        // Si soft deleted, on utilise une couleur orange
                        $bgColor = '#FFA500';
                    }
                    return [
                        'id' => $appoint->id,
                        'title' => $appoint->client_fname . ' ' . $appoint->client_lname,
                        'start' => $appoint->start_at,
                        'end' => $appoint->end_at,
                        'backgroundColor' => $bgColor,
                        'extendedProps' => [
                            'techName' => optional($appoint->tech->user)->prenom . ' ' . optional($appoint->tech->user)->nom,
                            'serviceName' => optional($appoint->service)->name ?? 'Non spécifié',
                            'comment' => $appoint->comment ?? 'Aucun commentaire',
                            'clientAddress' => trim("{$appoint->client_adresse}, {$appoint->client_zip_code} {$appoint->client_city}"),
                            'clientPhone' => $appoint->client_phone,
                            'isDeleted' => $isDeleted,
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

    public function sync()
    {
        $techId = Auth::user()->tech->id ?? null;

        if (!$techId) {
            Log::warning("Sync échouée : utilisateur sans tech_id.");
            return response()->json(['success' => false, 'message' => 'Aucun technicien trouvé.'], 403);
        }

        $appointments = WAPetGCAppointment::where('tech_id', $techId)
            ->where('start_at', '>=', now())
            ->get();

        Log::info("Génération ICS pour {$appointments->count()} RDV (tech_id: $techId)");

        $icsContent = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//TechCalendar//FR\n";
        foreach ($appointments as $appt) {
            $start = \Carbon\Carbon::parse($appt->start_at)->format('Ymd\THis');
            $end = \Carbon\Carbon::parse($appt->end_at)->format('Ymd\THis');
            $icsContent .= "BEGIN:VEVENT\n";
            $icsContent .= "UID:appt-{$appt->id}@techcalendar\n";
            $icsContent .= "DTSTAMP:".now()->format('Ymd\THis')."\n";
            $icsContent .= "DTSTART:$start\n";
            $icsContent .= "DTEND:$end\n";
            $icsContent .= "SUMMARY:RDV avec {$appt->client_fname} {$appt->client_lname}\n";
            $icsContent .= "LOCATION:{$appt->client_adresse}, {$appt->client_zip_code} {$appt->client_city}\n";
            $icsContent .= "DESCRIPTION:Technicien: {$appt->tech->user->prenom} {$appt->tech->user->nom}\n";
            $icsContent .= "END:VEVENT\n";
        }
        $icsContent .= "END:VCALENDAR";

        return response($icsContent, 200)
            ->header('Content-Type', 'text/calendar')
            ->header('Content-Disposition', 'attachment; filename="rendez-vous.ics"');
    }
}
