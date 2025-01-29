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
            // ✅ Convertir `end_at` en format MySQL (YYYY-MM-DD HH:MM:SS)
            $endAtFormatted = \DateTime::createFromFormat('d/m/Y H:i', $validated['end_at']);
            if ($endAtFormatted) {
                $validated['end_at'] = $endAtFormatted->format('Y-m-d H:i:s');
            } else {
                Log::error('Format de end_at invalide.', ['end_at' => $validated['end_at']]);
                return redirect()->route('appointment.index')
                    ->withErrors("Format de l'heure de fin invalide.");
            }

            // Vérifier si `tech_id` est vide et le remplacer par `NULL`
            if (empty($validated['tech_id'])) {
                $validated['tech_id'] = null;
            }

            // Gestion du trajet uniquement si un technicien est assigné
            if ($validated['tech_id']) {
                $tech = WAPetGCTech::find($validated['tech_id']);

                if (!$tech) {
                    Log::error("Technicien introuvable.", ['tech_id' => $validated['tech_id']]);
                    return redirect()->route('appointment.index')->withErrors("Technicien introuvable.");
                }

                $lastAppointment = WAPetGCAppointment::where('tech_id', $validated['tech_id'])
                    ->whereDate('start_at', $validated['start_at'])
                    ->orderBy('end_at', 'desc')
                    ->first();

                if ($lastAppointment) {
                    $fromAddress = "{$lastAppointment->client_adresse} {$lastAppointment->client_zip_code} {$lastAppointment->client_city}";
                } else {
                    $fromAddress = "{$tech->adresse} {$tech->zip_code} {$tech->city}";
                }

                $route = $mapboxService->calculateRouteBetweenAddresses($fromAddress, "{$validated['client_adresse']} {$validated['client_zip_code']} {$validated['client_city']}");

                if ($route) {
                    $validated['trajet_time'] = $route['duration_minutes'];
                    $validated['trajet_distance'] = $route['distance_km'];
                } else {
                    $validated['trajet_time'] = 100;
                    $validated['trajet_distance'] = 100;
                }
            } else {
                $validated['trajet_time'] = 100;
                $validated['trajet_distance'] = 100;
            }

            // Création du rendez-vous
            $appointment = WAPetGCAppointment::create($validated);

            Log::info('Rendez-vous créé avec succès.', ['id' => $appointment->id]);

            return redirect()->route('appointment.index')->with('success', 'Rendez-vous créé avec succès.');

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du rendez-vous.', ['error' => $e->getMessage(), 'data' => $request->all()]);

            return redirect()->route('appointment.index')->withErrors('Une erreur s\'est produite lors de la création du rendez-vous.');
        }
    }

    public function search(Request $request, \App\Providers\MapboxService $mapboxService)
    {
        Log::info('Début de la recherche de techniciens.', ['requestData' => $request->all()]);

        // Étape 1 : Construire l'adresse complète du client
        $clientAddress = trim($request->input('client_adresse') . ' ' . $request->input('client_zip_code') . ' ' . $request->input('client_city'));
        Log::info('Adresse complète du client construite.', ['clientAddress' => $clientAddress]);

        // Étape 2 : Extraire le département
        $dept = substr($request->input('client_zip_code'), 0, 2);
        Log::info('Département extrait.', ['dept' => $dept]);

        // Étape 3 : Récupérer les techniciens du même département
        $deptTech = \App\Models\WAPetGCTech::where('zip_code', 'like', $dept . '%')->get();
        Log::info('Techniciens récupérés dans le département.', ['count' => $deptTech->count(), 'deptTech' => $deptTech->toArray()]);

        // Étape 4 : Calculer les distances/temps pour les techniciens du département
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
                Log::info('Distance et durée calculées pour un technicien.', [
                    'tech_id' => $tech->id,
                    'distance' => $route['distance_km'],
                    'duration' => $route['duration_minutes'],
                ]);
            } else {
                Log::warning('Aucune route trouvée pour un technicien.', ['tech_id' => $tech->id, 'techAddress' => $techAddress]);
            }
        }
        usort($deptDistances, fn($a, $b) => $a['duration'] <=> $b['duration']);
        Log::info('Techniciens du département triés par durée.', ['deptDistances' => $deptDistances]);

        // Étape 5 : Compléter avec les techniciens hors département si besoin
        $selectedTechs = $deptDistances;
        if (count($selectedTechs) < 3) {
            $others = \App\Models\WAPetGCTech::where('zip_code', 'not like', $dept . '%')->get();
            Log::info('Techniciens hors département récupérés.', ['count' => $others->count(), 'others' => $others->toArray()]);

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
                    Log::info('Distance et durée calculées pour un technicien hors département.', [
                        'tech_id' => $tech->id,
                        'distance' => $route['distance_km'],
                        'duration' => $route['duration_minutes'],
                    ]);
                } else {
                    Log::warning('Aucune route trouvée pour un technicien hors département.', ['tech_id' => $tech->id, 'techAddress' => $techAddress]);
                }
            }
            usort($otherDistances, fn($a, $b) => $a['duration'] <=> $b['duration']);
            Log::info('Techniciens hors département triés par durée.', ['otherDistances' => $otherDistances]);

            // Compléter jusqu'à avoir 3 techniciens
            $needed = 3 - count($selectedTechs);
            $selectedTechs = array_merge($selectedTechs, array_slice($otherDistances, 0, $needed));
        }

        Log::info('Liste finale des techniciens sélectionnés.', ['selectedTechs' => $selectedTechs]);

        // Étape 6 : Récupérer les rendez-vous des techniciens sélectionnés
        $techIds = array_map(fn($t) => $t['tech']->id, $selectedTechs);
        $appointments = \App\Models\WAPetGCAppointment::whereIn('tech_id', $techIds)
            ->with(['service', 'tech.user'])
            ->get();
        Log::info('Rendez-vous récupérés pour les techniciens sélectionnés.', ['appointments' => $appointments->toArray()]);

        // Étape 7 : Récupérer la liste des services
        $services = \App\Models\WAPetGCService::all();
        Log::info('Liste des services récupérée.', ['services' => $services->toArray()]);

        // Étape 8 : Retourner la vue avec toutes les données nécessaires
        return view('takeAppointment', [
            'technicians' => WAPetGCTech::with('user')->get(),
            'selectedTechs' => $selectedTechs,
            'appointments' => $appointments,
            'services' => $services,
            'requestData' => $request->all(),
        ]);
    }
}
