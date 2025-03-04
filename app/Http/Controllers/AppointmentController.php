<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCService;

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

        $clientAddress = trim($request->input('client_adresse') . ' ' . $request->input('client_zip_code') . ' ' . $request->input('client_city'));
        Log::info('Adresse complète du client construite.', ['clientAddress' => $clientAddress]);

        $dept = substr($request->input('client_zip_code'), 0, 2);
        Log::info('Département extrait.', ['dept' => $dept]);

        $deptTech = WAPetGCTech::where('zip_code', 'like', $dept . '%')->get();
        Log::info('Techniciens récupérés dans le département.', ['count' => $deptTech->count()]);

        $deptDistances = [];
        foreach ($deptTech as $tech) {
            $techAddress = $tech->adresse . ' ' . $tech->zip_code . ' ' . $tech->city;
            $route = $mapboxService->calculateRouteBetweenAddresses($techAddress, $clientAddress);

            if ($route) {
                $deptDistances[] = [
                    'tech' => $tech,
                    'distance' => $route['distance_km'],
                    'duration' => $route['duration_minutes'],
                ];
            }
        }
        usort($deptDistances, fn($a, $b) => $a['duration'] <=> $b['duration']);

        $selectedTechs = array_slice($deptDistances, 0, 5); // Maintenant 5 techniciens

        if (count($selectedTechs) < 5) {
            $others = WAPetGCTech::where('zip_code', 'not like', $dept . '%')->get();
            Log::info('Techniciens hors département récupérés.', ['count' => $others->count()]);

            $otherDistances = [];
            foreach ($others as $tech) {
                $techAddress = $tech->adresse . ' ' . $tech->zip_code . ' ' . $tech->city;
                $route = $mapboxService->calculateRouteBetweenAddresses($techAddress, $clientAddress);

                if ($route) {
                    $otherDistances[] = [
                        'tech' => $tech,
                        'distance' => $route['distance_km'],
                        'duration' => $route['duration_minutes'],
                    ];
                }
            }
            usort($otherDistances, fn($a, $b) => $a['duration'] <=> $b['duration']);

            $needed = 5 - count($selectedTechs);
            $selectedTechs = array_merge($selectedTechs, array_slice($otherDistances, 0, $needed));
        }

        Log::info('Liste finale des techniciens sélectionnés.', ['selectedTechs' => $selectedTechs]);

        $techIds = array_map(fn($t) => $t['tech']->id, $selectedTechs);
        $appointments = WAPetGCAppointment::whereIn('tech_id', $techIds)
            ->with(['service', 'tech.user'])
            ->get();

        $services = WAPetGCService::all();

        return view('takeAppointment', [
            'technicians' => WAPetGCTech::with('user')->get(),
            'selectedTechs' => $selectedTechs,
            'appointments' => $appointments,
            'services' => $services,
            'requestData' => $request->all(),
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
}
