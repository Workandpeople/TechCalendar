<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCService;
use Carbon\Carbon;

class AppointmentController extends Controller
{
    public function index()
    {
        // Récupérer la liste des techniciens et services
        $technicians = WAPetGCTech::with('user')->get();
        $services    = WAPetGCService::all();

        // Passer les variables à la vue "takeAppointment" avec des valeurs par défaut
        return view('takeAppointment', [
            'technicians' => $technicians,
            'services' => $services,
            'selectedTechs' => [], // Liste vide par défaut
            'appointments' => [],  // Liste vide par défaut
        ]);
    }

    public function store(Request $request, \App\Providers\MapboxService $mapboxService)
    {
        Log::info('Tentative de création d\'un nouveau rendez-vous.', ['requestData' => $request->all()]);

        // Validation des données
        $validated = $request->validate([
            'tech_id'         => 'required|exists:WAPetGC_Tech,id',
            'service_id'      => 'required|exists:WAPetGC_Services,id',
            'client_fname'    => 'required|string|max:255',
            'client_lname'    => 'required|string|max:255',
            'client_adresse'  => 'required|string|max:255',
            'client_zip_code' => 'required|string|max:10',
            'client_city'     => 'required|string|max:255',
            'client_phone'    => 'required|string|max:20',
            'start_at'        => 'required|date',
            'duration'        => 'required|integer|min:1',
            'end_at'          => 'required|string', // On accepte temporairement en string
            'comment'         => 'nullable|string',
        ]);

        Log::info('Données validées avec succès.', ['validatedData' => $validated]);

        try {
            // 1) Convertir end_at "05/03/2025 à 07:50" => "05/03/2025 07:50"
            $endAtStr = $validated['end_at'];
            $endAtStr = str_replace(' à ', ' ', $endAtStr);

            // 2) Parser "05/03/2025 07:50"
            $endAtFormatted = \DateTime::createFromFormat('d/m/Y H:i', $endAtStr);

            // Si non reconnu, on teste éventuellement d’autres formats
            if (!$endAtFormatted) {
                // Par exemple, tenter "Y-m-d\TH:i" si besoin, ou renvoyer une erreur
                Log::error('Format de end_at invalide.', ['end_at' => $validated['end_at']]);
                return redirect()->route('appointment.index')
                    ->withErrors("Format de l'heure de fin invalide (end_at).");
            }

            // On remplace la valeur dans validated par le bon format pour la BDD
            $validated['end_at'] = $endAtFormatted->format('Y-m-d H:i:s');

            // Vérifier si `tech_id` est vide et le remplacer par `NULL`
            if (empty($validated['tech_id'])) {
                $validated['tech_id'] = null;
            }

            // Gestion du trajet uniquement si un technicien est assigné
            if ($validated['tech_id']) {
                $tech = WAPetGCTech::find($validated['tech_id']);
                if (!$tech) {
                    Log::error("Technicien introuvable.", ['tech_id' => $validated['tech_id']]);
                    return redirect()->route('appointment.index')
                        ->withErrors("Technicien introuvable.");
                }

                // Récupérer le dernier RDV du jour
                $lastAppointment = WAPetGCAppointment::where('tech_id', $validated['tech_id'])
                    ->whereDate('start_at', $validated['start_at'])
                    ->orderBy('end_at', 'desc')
                    ->first();

                if ($lastAppointment) {
                    $fromAddress = "{$lastAppointment->client_adresse} {$lastAppointment->client_zip_code} {$lastAppointment->client_city}";
                } else {
                    $fromAddress = "{$tech->adresse} {$tech->zip_code} {$tech->city}";
                }

                // Appel Mapbox
                $route = $mapboxService->calculateRouteBetweenAddresses(
                    $fromAddress,
                    "{$validated['client_adresse']} {$validated['client_zip_code']} {$validated['client_city']}"
                );

                if ($route) {
                    $validated['trajet_time']     = $route['duration_minutes'];
                    $validated['trajet_distance'] = $route['distance_km'];
                } else {
                    // Valeurs par défaut si on ne peut pas calculer
                    $validated['trajet_time']     = 100;
                    $validated['trajet_distance'] = 100;
                }
            } else {
                $validated['trajet_time']     = 100;
                $validated['trajet_distance'] = 100;
            }

            // Création du rendez-vous
            $appointment = WAPetGCAppointment::create($validated);

            Log::info('Rendez-vous créé avec succès.', ['id' => $appointment->id]);

            return redirect()->route('appointment.index')
                ->with('success', 'Rendez-vous créé avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du rendez-vous.', [
                'error' => $e->getMessage(),
                'data'  => $request->all(),
            ]);

            return redirect()
                ->route('appointment.index')
                ->withErrors('Une erreur s\'est produite lors de la création du rendez-vous.');
        }
    }

    public function search(Request $request, \App\Providers\MapboxService $mapboxService)
    {
        Log::info('Début de la recherche de techniciens.', ['requestData' => $request->all()]);

        // 1) Construit l'adresse
        $clientAddress = trim(
            $request->input('client_adresse', '').' '.
            $request->input('client_zip_code', '').' '.
            $request->input('client_city', '')
        );

        // 2) Trouve 5 techniciens
        $dept = substr($request->input('client_zip_code'), 0, 2);
        $deptTech = WAPetGCTech::where('zip_code', 'like', $dept.'%')->get();

        $deptDistances = [];
        foreach ($deptTech as $tech) {
            $techAddress = $tech->adresse.' '.$tech->zip_code.' '.$tech->city;
            $route = $mapboxService->calculateRouteBetweenAddresses($techAddress, $clientAddress);
            if ($route) {
                $deptDistances[] = [
                    'tech'     => $tech,
                    'distance' => $route['distance_km'],
                    'duration' => $route['duration_minutes'],
                ];
            }
        }
        usort($deptDistances, fn($a,$b) => $a['duration'] <=> $b['duration']);
        $selectedTechs = array_slice($deptDistances, 0, 5);

        // Gérer éventuellement le cas où on n'a pas 5 dans le dept
        if (count($selectedTechs) < 5) {
            $others = WAPetGCTech::where('zip_code', 'not like', $dept . '%')->get();

            $otherDistances = [];
            foreach ($others as $tech) {
                $techAddress = $tech->adresse . ' ' . $tech->zip_code . ' ' . $tech->city;
                $route = $mapboxService->calculateRouteBetweenAddresses($techAddress, $clientAddress);
                if ($route) {
                    $otherDistances[] = [
                        'tech'     => $tech,
                        'distance' => $route['distance_km'],
                        'duration' => $route['duration_minutes'],
                    ];
                }
            }
            usort($otherDistances, fn($a, $b) => $a['duration'] <=> $b['duration']);
            $needed = 5 - count($selectedTechs);
            $selectedTechs = array_merge($selectedTechs, array_slice($otherDistances, 0, $needed));
        }

        // 3) Stocker ID tech en session + colorMap en session
        $techIds = [];
        $colorMap = ['#ff9999','#99ff99','#9999ff','#ffcc99','#99ccff'];
        $i = 0;
        foreach ($selectedTechs as $t) {
            $techIds[] = $t['tech']->id;
            // On mémorise l'ID => Couleur
            session(["color_for_{$t['tech']->id}" => ($colorMap[$i] ?? '#cccccc')]);
            $i++;
        }
        session(['selected_tech_ids' => $techIds]);

        // 5) Stocker en session les paramètres de recherche
        session([
            'selected_tech_ids' => $techIds,
            'client_adresse' => $request->input('client_adresse', ''),
            'client_zip_code' => $request->input('client_zip_code', ''),
            'client_city' => $request->input('client_city', ''),
        ]);

        // 6) Retourner la vue avec les techniciens et services, SANS rendez-vous
        $services = WAPetGCService::all();
        return view('takeAppointment', [
            'selectedTechs' => $selectedTechs,   // pour la légende + map
            'services'      => $services,
            'requestData'   => $request->all(),
        ]);
    }

    public function calculateRoute(Request $request, \App\Providers\MapboxService $mapboxService)
    {
        $techId = $request->input('tech_id');
        $dateSelected = $request->input('date');
        $clientAddress = trim($request->input('client_adresse') . ' ' . $request->input('client_zip_code') . ' ' . $request->input('client_city'));

        if (!$techId) {
            // Sélectionner le premier technicien si aucun n'est spécifié
            $firstTech = WAPetGCTech::first();
            if (!$firstTech) {
                return response()->json(['error' => 'Aucun technicien disponible'], 404);
            }
            $techId = $firstTech->id;
        }

        $tech = WAPetGCTech::find($techId);
        if (!$tech) {
            return response()->json(['error' => 'Technicien introuvable'], 404);
        }

        // Vérifier s'il existe un rendez-vous avant la date sélectionnée ce jour-là
        $lastAppointment = WAPetGCAppointment::where('tech_id', $techId)
            ->whereDate('start_at', $dateSelected)
            ->where('start_at', '<', $dateSelected) // RDV avant le créneau sélectionné
            ->orderBy('end_at', 'desc')
            ->first();

        if ($lastAppointment) {
            $fromAddress = "{$lastAppointment->client_adresse} {$lastAppointment->client_zip_code} {$lastAppointment->client_city}";
        } else {
            $fromAddress = "{$tech->adresse} {$tech->zip_code} {$tech->city}";
        }

        // Calcul de l'itinéraire
        $route = $mapboxService->calculateRouteBetweenAddresses($fromAddress, $clientAddress);

        if ($route) {
            return response()->json([
                'distance_km' => $route['distance_km'],
                'duration_minutes' => $route['duration_minutes'],
            ]);
        }

        return response()->json(['error' => 'Impossible de calculer le trajet'], 500);
    }

    // ----- Ajout : la méthode "ajaxEvents" ------
    public function ajaxEvents(Request $request, \App\Providers\MapboxService $mapboxService)
    {
        // 1) Récupère la plage de dates
        $start = $request->input('start'); // ex: "2025-03-17"
        $end   = $request->input('end');   // ex: "2025-03-24"

        // 2) Récupère l'adresse complète du client
        $clientAddress = trim(
            $request->input('client_adresse','').' '.
            $request->input('client_zip_code','').' '.
            $request->input('client_city','')
        );

        // 3) Récupère les IDs des techniciens cochés dans la légende
        //    (envoyés par AJAX depuis le front)
        $techIds = $request->input('tech_ids', []);

        // Si aucun technicien n'est coché, on renvoie un tableau vide
        if (empty($techIds)) {
            return response()->json([]);
        }

        // 4) Charge les rendez-vous (Appointments) uniquement
        //    pour les techniciens cochés sur la plage demandée
        $appointments = WAPetGCAppointment::whereIn('tech_id', $techIds)
            ->whereBetween('start_at', [$start, $end])
            ->with(['service', 'tech.user'])
            ->get();

        // 5) Construit le tableau d'événements pour FullCalendar
        $events = [];
        foreach ($appointments as $appoint) {
            // Calcul de la distance/temps entre l'adresse client *actuel*
            // et l'adresse client saisie dans le formulaire (pour l'affichage)
            $route = $mapboxService->calculateRouteBetweenAddresses(
                $clientAddress,
                $appoint->client_adresse.' '.$appoint->client_zip_code.' '.$appoint->client_city
            );
            $dist = $route ? $route['distance_km']       : 0;
            $time = $route ? $route['duration_minutes']  : 0;

            // (Optionnel) Récupérer une couleur stockée en session, ou définir une couleur par défaut
            $color = session("color_for_{$appoint->tech_id}", '#cccccc');

            // Exemple de titre : "Jane Doe (34)"
            $dept  = substr($appoint->client_zip_code, 0, 2) ?: 'XX';
            $title = $appoint->client_fname.' '.$appoint->client_lname.' ('.$dept.')';

            $events[] = [
                'id'              => $appoint->id,
                'title'           => $title,
                'start'           => $appoint->start_at,
                'end'             => $appoint->end_at,
                'backgroundColor' => $color, // Couleur de fond associée au technicien
                'extendedProps'   => [
                    'tech_id'        => $appoint->tech_id,
                    'techName'       => optional($appoint->tech->user)->prenom.' '.optional($appoint->tech->user)->nom,
                    'serviceName'    => optional($appoint->service)->name,
                    'comment'        => $appoint->comment,
                    'clientAddress'  => $appoint->client_adresse.' '.$appoint->client_zip_code.' '.$appoint->client_city,
                    'clientPhone'    => $appoint->client_phone,
                    'distanceSearch' => $dist,
                    'timeSearch'     => $time,
                ],
            ];
        }

        // 6) Renvoie les événements au format JSON (pour FullCalendar)
        return response()->json($events);
    }
}
