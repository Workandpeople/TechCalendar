<?php

namespace App\Http\Controllers;

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Prestation;
use App\Models\User;
use App\Models\Rendezvous;

use App\Providers\MapboxService;

class AssistantController extends Controller
{
    protected $mapboxService;

    public function __construct(MapboxService $mapboxService)
    {
        $this->mapboxService = $mapboxService;
    }
    public function dashboard()
    {
        Log::info("Accès au tableau de bord de l'assistant.");
        return view('assistant.dashboard');
    }

    public function prendreRdv()
    {
        Log::info("Accès à la prise de rendez-vous de l'assistant.");

        // Récupérer les prestations depuis la base de données
        $prestations = Prestation::all();

        return view('assistant.prendre_rdv', compact('prestations'));
    }

    public function agendaTech()
    {
        Log::info("Accès à l'agenda technique de l'assistant.");

        // Récupérer uniquement les techniciens
        $techniciens = User::whereHas('role', function ($query) {
            $query->where('role', 'technicien');
        })->get();

        return view('assistant.agenda_tech', compact('techniciens'));
    }

    public function searchTechnicians(Request $request)
    {
        $department = $request->query('department');
        $address = $request->query('address');
        $city = $request->query('city');

        if (!$department || !$address || !$city) {
            return response()->json(['error' => 'Les paramètres requis sont manquants.'], 400);
        }

        // Filtrer les techniciens par département
        $technicians = User::where('code_postal', 'LIKE', "{$department}%")
            ->whereHas('role', function ($query) {
                $query->where('role', 'technicien');
            })
            ->get();

        $primaryResults = [];
        $fallbackResults = [];

        foreach ($technicians as $technician) {
            // Adresse complète du technicien
            $technicianAddress = "{$technician->adresse}, {$technician->code_postal}, {$technician->ville}";

            // Calculer la distance et le temps entre les adresses
            $route = $this->mapboxService->calculateRouteBetweenAddresses($technicianAddress, "{$address}, {$city}");
            if ($route) {
                $allowedTrajectTime = $technician->default_traject_time + 30; // Temps de trajet autorisé avec une marge de 30 minutes

                $technicianData = [
                    'id' => $technician->id, // Inclure l'ID
                    'name' => "{$technician->prenom} {$technician->nom}",
                    'distance' => $route['distance_km'],
                    'duration' => $route['duration_minutes'],
                ];

                if ($route['duration_minutes'] <= $allowedTrajectTime) {
                    // Ajouter aux résultats principaux si le critère de temps de trajet est respecté
                    $primaryResults[] = $technicianData;
                } else {
                    // Ajouter aux résultats de fallback si le critère de temps de trajet n'est pas respecté
                    $fallbackResults[] = $technicianData;
                }
            }
        }

        if (empty($primaryResults) && !empty($fallbackResults)) {
            // Retourner les techniciens de fallback si aucun résultat principal
            return response()->json([
                'technicians' => $fallbackResults,
                'message' => 'Aucun technicien ne répond aux critères de temps de trajet. Voici des techniciens dans le même département.',
            ]);
        }

        if (empty($primaryResults) && empty($fallbackResults)) {
            // Si aucun technicien n'est trouvé
            return response()->json(['error' => 'Aucun technicien disponible.'], 404);
        }

        // Retourner les résultats principaux si disponibles
        return response()->json(['technicians' => $primaryResults]);
    }

    public function storeAppointment(Request $request)
    {
        Log::info('Requête reçue : ', $request->all());

        // Ajouter un log pour vérifier la validation
        try {
            $validatedData = $request->validate([
                'technician_id' => 'required|uuid|exists:users,id',
                'nom' => 'required|string|max:100',
                'prenom' => 'required|string|max:100',
                'adresse' => 'required|string|max:150',
                'code_postal' => 'required|string|max:10',
                'ville' => 'required|string|max:100',
                'tel' => 'nullable|string|max:20',
                'date' => 'required|date',
                'start_at' => 'required|date_format:H:i',
                'prestation' => 'required|string|max:255',
                'duree' => 'nullable|integer',
                'commentaire' => 'nullable|string',
            ]);
            Log::info('Données validées : ', $validatedData);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erreur de validation : ', $e->errors());
            return response()->json(['errors' => $e->errors()], 422);
        }

        $appointment = Rendezvous::create($validatedData);
        Log::info('Rendez-vous créé avec succès : ', $appointment->toArray());

        return response()->json([
            'message' => 'Rendez-vous ajouté avec succès.',
            'appointment' => $appointment,
        ], 201);
    }
}