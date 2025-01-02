<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCAppointment;
use Illuminate\Support\Facades\Log;

class CalendarController extends Controller
{
    public function techCalendar(Request $request)
    {
        try {
            // Récupérer les IDs des techniciens à partir de la requête
            $techIds = explode(',', $request->query('tech_ids', ''));

            Log::info('Tech IDs spécifiés : ', $techIds);

            if (empty($techIds)) {
                throw new \Exception("Aucun technicien spécifié.");
            }

            // Récupérer les rendez-vous des techniciens spécifiques
            $appointments = WAPetGCAppointment::with('tech.user')
                ->whereIn('tech_id', $techIds)
                ->get();

            Log::info('Rendez-vous récupérés : ', $appointments->toArray());

            // Formater les événements pour FullCalendar
            $events = $appointments->map(function ($appointment) {
                $colorMap = [
                    'MAR' => '#007bff',
                    'AUDIT' => '#28a745',
                    'COFRAC' => '#ffc107',
                ];
            
                $type = $appointment->service->type ?? 'MAR'; // Défaut : MAR
                $color = $colorMap[$type] ?? '#007bff'; // Couleur par défaut
            
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
            // Optionnel : récupérer une liste de techniciens spécifiques
            $techIds = $request->input('tech_ids', []);

            $query = WAPetGCAppointment::query();

            if (!empty($techIds)) {
                $query->whereIn('tech_id', $techIds);
            }

            $appointments = $query->with('tech')->get();

            // Convertir les rendez-vous au format FullCalendar
            $events = $appointments->map(function ($appointment) {
                return [
                    'title' => $appointment->tech->name ?? 'Technicien inconnu',
                    'start' => $appointment->start_at ?? null,
                    'end' => $appointment->end_at ?? null,
                    'description' => $appointment->comment ?? 'Pas de description',
                    'color' => '#007bff',
                    'extendedProps' => [
                        'techName' => $appointment->tech->name ?? '',
                        'client' => $appointment->client_fname . ' ' . $appointment->client_lname,
                        'adresse' => $appointment->client_adresse,
                    ],
                ];
            });

            return response()->json($events);
        } catch (\Throwable $e) {
            Log::error("Erreur dans getCalendarEvents : " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Une erreur est survenue lors de la récupération des événements.'], 500);
        }
    }
}