<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCTech;
use Illuminate\Support\Facades\Log;

class CalendarController extends Controller
{
    public function techCalendar(Request $request)
    {
        try {
            $techIds = $request->query('tech_ids', []);

            // Vérifiez et nettoyez les IDs
            if (!is_array($techIds)) {
                $techIds = explode(',', $techIds); // Convertir en tableau si une chaîne est passée
            }

            $techIds = array_map('trim', $techIds); // Supprimer les espaces inutiles
            $techIds = array_filter($techIds); // Supprimer les valeurs vides

            Log::info('Tech IDs spécifiés : ', $techIds);

            // Récupérer tous les techniciens
            $technicians = WAPetGCTech::with('user')->get();

            // Filtrer les rendez-vous selon les techniciens sélectionnés
            $appointments = WAPetGCAppointment::with('tech.user')
                ->when(!empty($techIds), function ($query) use ($techIds) {
                    $query->whereIn('tech_id', $techIds);
                })
                ->get();

            Log::info('Rendez-vous récupérés : ', $appointments->toArray());

            // Formater les événements pour FullCalendar
            $events = $appointments->map(function ($appointment) {
                $colorMap = [
                    'MAR' => '#007bff',
                    'AUDIT' => '#28a745',
                    'COFRAC' => '#ffc107',
                ];

                $type = $appointment->service->type ?? 'MAR';
                $color = $colorMap[$type] ?? '#007bff';

                return [
                    'title' => "{$appointment->client_fname} {$appointment->client_lname}",
                    'start' => $appointment->start_at,
                    'end' => $appointment->end_at,
                    'color' => $color,
                    'description' => [
                        'durée' => $appointment->duration . ' minutes',
                        'commentaire' => $appointment->comment ?? 'Non spécifié',
                    ],
                ];
            });

            return view('assistant.comparative_schedule', [
                'events' => $events,
                'technicians' => $technicians,
                'preSelectedTechIds' => $techIds,
            ]);
        } catch (\Throwable $e) {
            Log::error("Erreur dans techCalendar : " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->view('errors.500', ['message' => $e->getMessage()], 500);
        }
    }

    public function getCalendarEvents(Request $request)
    {
        try {
            $techIds = $request->input('tech_ids', []);

            if (!is_array($techIds)) {
                $techIds = explode(',', $techIds);
            }

            $appointments = WAPetGCAppointment::with('tech.user')
                ->when(!empty($techIds), function ($query) use ($techIds) {
                    $query->whereIn('tech_id', $techIds);
                })
                ->get();

            $events = $appointments->map(function ($appointment) {
                $colorMap = [
                    'MAR' => '#007bff',
                    'AUDIT' => '#28a745',
                    'COFRAC' => '#ffc107',
                ];

                $type = $appointment->service->type ?? 'MAR'; // Assurez-vous que `$appointment->service` est bien défini
                $color = $colorMap[$type] ?? '#007bff';

                return [
                    'title' => "{$appointment->client_fname} {$appointment->client_lname}",
                    'start' => $appointment->start_at,
                    'end' => $appointment->end_at,
                    'color' => $color,
                    'description' => [
                        'durée' => $appointment->duration . ' minutes',
                        'commentaire' => $appointment->comment ?? 'Non spécifié',
                    ],
                    'extendedProps' => [
                        'techName' => $appointment->tech->user->prenom . ' ' . $appointment->tech->user->nom,
                        'adresse' => $appointment->client_adresse,
                    ],
                ];
            });

            Log::info('Données formatées pour FullCalendar : ', $events->toArray());

            return response()->json($events);
        } catch (\Throwable $e) {
            Log::error("Erreur dans getCalendarEvents : " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Une erreur est survenue lors de la récupération des événements.'], 500);
        }
    }
}