<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCAppointment;
use Illuminate\Support\Facades\Log;

class CalendarController extends Controller
{
    /**
     * Affichage initial de la page avec la liste des techniciens et le calendrier vide.
     */
    public function index()
    {
        // Récupérer tous les techniciens avec leurs informations utilisateur
        $technicians = WAPetGCTech::with('user')->get();

        return view('calendar', compact('technicians'));
    }

    /**
     * Récupérer les rendez-vous des techniciens sélectionnés via AJAX.
     */
    public function getAppointments(Request $request)
    {
        try {
            $selectedTechs = $request->input('techs', []);
            $start = $request->input('start'); // Ex: "2025-03-01T00:00:00Z"
            $end   = $request->input('end');

            // Si aucun technicien n'est sélectionné, renvoyer un tableau vide.
            if (empty($selectedTechs)) {
                return response()->json([
                    'success' => true,
                    'appointments' => [],
                ]);
            }

            $query = WAPetGCAppointment::whereIn('tech_id', $selectedTechs)
                ->with(['service', 'tech.user']);

            // Si une plage de dates est transmise, filtrer sur start_at.
            if ($start && $end) {
                $query->where('start_at', '>=', $start)
                    ->where('start_at', '<', $end);
            }

            $appointments = $query->get()->map(function ($appoint) {
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

        } catch (\Exception $e) {
            Log::error("Erreur lors du chargement des rendez-vous : " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne du serveur',
            ], 500);
        }
    }
}
