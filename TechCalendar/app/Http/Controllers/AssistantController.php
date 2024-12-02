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

    public function prendreRdv()
    {

        // Récupérer les prestations depuis la base de données
        $prestations = Prestation::all();

        return view('assistant.prendre_rdv', compact('prestations'));
    }

    public function agendaTech()
    {

        // Récupérer uniquement les techniciens
        $techniciens = User::whereHas('role', function ($query) {
            $query->where('role', 'technicien');
        })->get();

        return view('assistant.agenda_tech', compact('techniciens'));
    }

    public function searchTechnicians(Request $request)
    {

        Log::info('Paramètres reçus :', $request->all());

        $address = $request->query('address');
        $city = $request->query('city');
        $postalCode = $request->query('postal_code'); // Ajout du code postal
    
        if (!$address || !$city || !$postalCode) {
            Log::warning("Adresse, ville ou code postal manquant.");
            return response()->json([
                'message' => 'Adresse, ville et code postal sont requis pour rechercher des techniciens.',
            ], 400);
        }
    
        $technicians = User::whereHas('role', function ($query) {
            $query->where('role', 'technicien');
        })->get();
    
        $results = [];
    
        foreach ($technicians as $technician) {
            $technicianAddress = "{$technician->adresse}, {$technician->code_postal}, {$technician->ville}";
    
            // Récupérer les rendez-vous
            $appointments = Rendezvous::where('technician_id', $technician->id)
                ->whereBetween('date', [
                    now()->subMonth()->format('Y-m-d'),
                    now()->addMonth()->format('Y-m-d'),
                ])
                ->orderBy('date')
                ->orderBy('start_at')
                ->get();
    
            Log::info("Rendez-vous récupérés pour le technicien", [
                'technician_id' => $technician->id,
                'appointments' => $appointments->toArray(),
            ]);
    
            $firstAvailableDate = null;
            $route = null;
            $numberOfAppointments = 0;
    
            foreach (range(0, 365) as $dayOffset) {
                $currentDate = now()->addDays($dayOffset)->format('Y-m-d');
                $dayAppointments = $appointments->where('date', $currentDate);
    
                $numberOfAppointments = $dayAppointments->count();
    
                if ($numberOfAppointments < 2) {
                    $firstAvailableDate = $currentDate;
    
                    // Calcul du trajet
                    if ($dayAppointments->isEmpty()) {
                        $startCoordinates = $this->mapboxService->geocodeAddress("{$technician->code_postal}, {$technician->ville}, France");
                        $endCoordinates = $this->mapboxService->geocodeAddress("{$postalCode}, {$city}, France");

                        Log::info("Calcul de trajet : Départ (technicien) : {$technician->code_postal}, {$technician->ville}, France");
                        Log::info("Calcul de trajet : Destination : {$postalCode}, {$city}, France");

                        if ($startCoordinates && $endCoordinates) {
                            $route = $this->mapboxService->getRoute($startCoordinates, $endCoordinates);
                            Log::info("Résultat du calcul de trajet (sans rendez-vous) : ", $route);
                        } else {
                            Log::warning("Échec de la géolocalisation pour le technicien ou la destination.");
                            $route = null;
                        }
                    } else {
                        $lastAppointment = $dayAppointments->last();
                        $startCoordinates = $this->mapboxService->geocodeAddress("{$lastAppointment->adresse}, {$lastAppointment->ville}, France");
                        $endCoordinates = $this->mapboxService->geocodeAddress("{$postalCode}, {$city}, France");

                        Log::info("Calcul de trajet : Départ (dernier RDV) : {$lastAppointment->adresse}, {$lastAppointment->ville}, France");
                        Log::info("Calcul de trajet : Destination : {$postalCode}, {$city}, France");

                        if ($startCoordinates && $endCoordinates) {
                            $route = $this->mapboxService->getRoute($startCoordinates, $endCoordinates);
                            Log::info("Résultat du calcul de trajet (avec dernier RDV) : ", $route);
                        } else {
                            Log::warning("Échec de la géolocalisation pour le dernier rendez-vous ou la destination.");
                            $route = null;
                        }
                    }
    
                    break;
                }
            }
    
            $travelDistance = $route['distance_km'] ?? null;
            $travelDurationMinutes = $route['duration_minutes'] ?? $technician->default_traject_time;
    
            // Appliquer les filtres
            if (
                $travelDurationMinutes > ($technician->default_traject_time + 20) || 
                ($travelDistance !== null && $travelDistance > 150)
            ) {
                Log::info("Technicien filtré pour dépassement des critères", [
                    'technician_id' => $technician->id,
                    'travel_distance' => $travelDistance,
                    'travel_duration_minutes' => $travelDurationMinutes,
                    'default_traject_time' => $technician->default_traject_time,
                ]);
                continue;
            }
    
            // Arrondir les valeurs
            $travelDistance = $travelDistance !== null ? ceil($travelDistance) : null;
            $travelDurationMinutes = ceil($travelDurationMinutes / 10) * 10;
    
            $results[] = [
                'id' => $technician->id,
                'name' => "{$technician->prenom} {$technician->nom}",
                'next_availability_date' => $firstAvailableDate,
                'number_of_appointments' => $numberOfAppointments,
                'travel' => $travelDistance !== null
                    ? sprintf("%dkm et %d:%02d de trajet", $travelDistance, intdiv($travelDurationMinutes, 60), $travelDurationMinutes % 60)
                    : "N/A",
                'appointments' => $appointments->toArray(),
                'travel_duration_minutes' => $travelDurationMinutes, // Assurer l'existence de cette clé
            ];
        }
    
        // Si aucun technicien ne passe les filtres, recalculer les distances avec code postal, ville et "France"
        if (empty($results)) {
            Log::info("Aucun technicien trouvé, recalcul avec code postal et ville.");
    
            foreach ($technicians as $technician) {
                $technicianAddress = "{$technician->adresse}, {$technician->code_postal}, {$technician->ville}";
    
                $route = $this->mapboxService->getRoute(
                    $this->mapboxService->geocodeAddress($technicianAddress),
                    $this->mapboxService->geocodeAddress("{$postalCode}, {$city}, France")
                );
    
                $travelDistance = $route['distance_km'] ?? null;
                $travelDurationMinutes = $route['duration_minutes'] ?? $technician->default_traject_time;
    
                $results[] = [
                    'id' => $technician->id,
                    'name' => "{$technician->prenom} {$technician->nom}",
                    'travel' => $travelDistance !== null
                        ? sprintf("%dkm et %d:%02d de trajet", ceil($travelDistance), intdiv($travelDurationMinutes, 60), $travelDurationMinutes % 60)
                        : "N/A",
                    'travel_duration_minutes' => $travelDurationMinutes,
                ];
            }
    
            // Trier les résultats finaux par durée de trajet
            usort($results, function ($a, $b) {
                return ($a['travel_duration_minutes'] ?? PHP_INT_MAX) <=> ($b['travel_duration_minutes'] ?? PHP_INT_MAX);
            });
    
            return response()->json([
                'message' => 'Aucun technicien disponible avec les filtres initiaux. Résultats basés sur code postal et ville.',
                'technicians' => $results,
            ]);
        }
    
        // Trier les résultats finaux
        usort($results, function ($a, $b) {
            // Trier par date de disponibilité
            if ($a['next_availability_date'] !== $b['next_availability_date']) {
                return strcmp($a['next_availability_date'], $b['next_availability_date']);
            }
            // Ensuite par nombre de rendez-vous dans la journée
            if ($a['number_of_appointments'] !== $b['number_of_appointments']) {
                return $a['number_of_appointments'] - $b['number_of_appointments'];
            }
            // Enfin par durée du trajet
            return ($a['travel_duration_minutes'] ?? 0) <=> ($b['travel_duration_minutes'] ?? 0);
        });
    
        return response()->json(['technicians' => $results]);
    }
    
    public function getTechnicianAppointments(Request $request)
    {
        $technicianIds = $request->input('technician_ids', []);

        $appointments = Rendezvous::with('technician')
            ->whereIn('technician_id', $technicianIds)
            ->get();

        return response()->json([
            'appointments' => $appointments,
        ]);
    }

    public function show($id)
    {
        $rendezvous = Rendezvous::with('technician')->find($id);

        if (!$rendezvous) {
            return response()->json(['error' => 'Rendez-vous non trouvé.'], 404);
        }

        return response()->json([
            'id' => $rendezvous->id,
            'nom' => $rendezvous->nom,
            'prenom' => $rendezvous->prenom,
            'adresse' => $rendezvous->adresse,
            'code_postal' => $rendezvous->code_postal,
            'ville' => $rendezvous->ville,
            'tel' => $rendezvous->tel,
            'date' => $rendezvous->date,
            'start_at' => $rendezvous->start_at,
            'prestation' => $rendezvous->prestation,
            'duree' => $rendezvous->duree,
            'commentaire' => $rendezvous->commentaire,
            'technician' => [
                'id' => $rendezvous->technician->id,
                'name' => $rendezvous->technician->name,
            ],
        ]);
    }

    // private function timeStringToMinutes(string $time): int
    // {
    //     [$hours, $minutes] = explode(':', $time);
    //     return (int)$hours * 60 + (int)$minutes;
    // }

    // private function minutesToTimeString(int $minutes): string
    // {
    //     $hours = intdiv($minutes, 60);
    //     $remainingMinutes = $minutes % 60;
    //     return sprintf('%02d:%02d', $hours, $remainingMinutes);
    // }
    
    // private function addAvailability(User $technician, int $startMinutes, int $endMinutes)
    // {
    //     return [
    //         'id' => $technician->id,
    //         'name' => "{$technician->prenom} {$technician->nom}",
    //         'next_availability' => sprintf('%02d:%02d', intdiv($startMinutes, 60), $startMinutes % 60),
    //         'available_until' => sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60),
    //     ];
    // }

    // private function calculateDistance($coords1, $coords2)
    // {
    //     [$lon1, $lat1] = $coords1;
    //     [$lon2, $lat2] = $coords2;

    //     $earthRadius = 6371; // Rayon de la Terre en km
    //     $latDelta = deg2rad($lat2 - $lat1);
    //     $lonDelta = deg2rad($lon2 - $lon1);

    //     $a = sin($latDelta / 2) ** 2 +
    //         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
    //         sin($lonDelta / 2) ** 2;

    //     $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    //     return $earthRadius * $c;
    // }

    public function storeAppointment(Request $request)
    {
        // Log des données reçues
        Log::info('Données reçues pour le rendez-vous : ', $request->all());

        // Validation des données
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
                'duree' => 'nullable|integer|min:0',
                'commentaire' => 'nullable|string',
                'traject_time' => 'nullable|integer|min:0',
                'traject_distance' => 'nullable|numeric|min:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erreur de validation des données : ', $e->errors());
            return response()->json(['errors' => $e->errors()], 422);
        }

        // Vérification des conflits de rendez-vous
        $conflictingAppointments = Rendezvous::where('technician_id', $validatedData['technician_id'])
            ->where('date', $validatedData['date'])
            ->where('start_at', '<=', $validatedData['start_at'])
            ->whereRaw("(TIME_TO_SEC(start_at) + (duree * 60)) > TIME_TO_SEC(?)", [$validatedData['start_at']])
            ->exists();

        if ($conflictingAppointments) {
            Log::warning('Conflit détecté pour le technicien avec un autre rendez-vous.', $validatedData);
            return response()->json([
                'message' => 'Un autre rendez-vous est déjà planifié pour ce créneau.',
            ], 409);
        }

        // Création du rendez-vous
        try {
            $appointment = Rendezvous::create($validatedData);
            Log::info('Rendez-vous créé avec succès : ', $appointment->toArray());

            return response()->json([
                'message' => 'Rendez-vous ajouté avec succès.',
                'appointment' => $appointment,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du rendez-vous : ', ['exception' => $e->getMessage()]);
            return response()->json([
                'message' => 'Une erreur est survenue lors de la création du rendez-vous.',
            ], 500);
        }
    }
}