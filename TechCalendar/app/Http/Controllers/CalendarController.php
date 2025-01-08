<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCService;
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

            // Récupérer les services
            $services = WAPetGCService::all();

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
                    'end'   => $appointment->end_at,
                    'color' => $color,
                    // On stocke nos champs customs dans extendedProps
                    'extendedProps' => [
                        'appId' => $appointment->id,  // <-- Ici !
                        'description' => [
                            'durée'       => $appointment->duration . ' minutes',
                            'commentaire' => $appointment->comment ?? 'Non spécifié',
                        ],
                        // etc.
                    ],
                ];
            });

            Log::info('Événements formatés : ', $events->toArray());

            return view('assistant.comparative_schedule', [
                'events' => $events,
                'technicians' => $technicians,
                'services' => $services,
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
                'MAR'   => '#007bff',
                'AUDIT' => '#28a745',
                'COFRAC'=> '#ffc107',
            ];

            $type  = $appointment->service->type ?? 'MAR';
            $color = $colorMap[$type] ?? '#007bff';

            return [
                'title' => "{$appointment->client_fname} {$appointment->client_lname}",
                'start' => $appointment->start_at,
                'end'   => $appointment->end_at,
                'color' => $color,
                'extendedProps' => [
                    'appId'          => $appointment->id,
                    // On ajoute tous les champs qu'on veut pour le form
                    'clientFirstName'=> $appointment->client_fname,
                    'clientLastName' => $appointment->client_lname,
                    'clientPhone'    => $appointment->client_phone,
                    'fullAddress'    => $appointment->client_adresse . ', ' . $appointment->client_zip_code . ' ' . $appointment->client_city,
                    'techName'   => $appointment->tech->user->prenom . ' ' . $appointment->tech->user->nom,
                    'clientAddressStreet'    => $appointment->client_adresse,
                    'clientAddressPostalCode'=> $appointment->client_zip_code,
                    'clientAddressCity'      => $appointment->client_city,
                    'techId'         => $appointment->tech_id,
                    'serviceId'      => $appointment->service_id,
                    'duration'       => $appointment->duration,
                    // On peut déduire startTime / endTime d'après $appointment->start_at / end_at
                    'startTime'      => \Carbon\Carbon::parse($appointment->start_at)->format('H:i'),
                    'appointmentDate'=> \Carbon\Carbon::parse($appointment->start_at)->format('Y-m-d'),
                    // On peut calculer l'endTime aussi, si besoin
                    'endTime'        => \Carbon\Carbon::parse($appointment->end_at)->format('H:i'),
                    'comments'       => $appointment->comment,
                    
                    // Optionnel : la description précédente, si tu veux.
                    'description'    => [
                        'durée'       => $appointment->duration . ' minutes',
                        'commentaire' => $appointment->comment ?? 'Non spécifié',
                    ],
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