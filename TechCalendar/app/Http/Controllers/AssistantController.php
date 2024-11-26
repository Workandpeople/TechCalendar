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
        return view('assistant.dashboard');
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
        $address = $request->query('address');
        $city = $request->query('city');

        Log::info("Recherche de techniciens pour disponibilité", compact('address', 'city'));

        if (!$address || !$city) {
            Log::warning("Adresse ou ville manquante.");
            return response()->json([
                'message' => 'Adresse et ville sont requis pour rechercher des techniciens.',
            ], 400);
        }

        $technicians = User::whereHas('role', function ($query) {
            $query->where('role', 'technicien');
        })->get();

        Log::info("Techniciens récupérés", ['count' => $technicians->count()]);

        $results = [];

        foreach ($technicians as $technician) {
            $technicianAddress = "{$technician->adresse}, {$technician->code_postal}, {$technician->ville}";

            // Récupérer tous les rendez-vous à venir
            $appointments = Rendezvous::where('technician_id', $technician->id)
                ->whereDate('date', '>=', now()->format('Y-m-d'))
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
                        // Pas de rendez-vous dans la journée, calculer depuis le domicile
                        $route = $this->mapboxService->getRoute(
                            $this->mapboxService->geocodeAddress($technicianAddress),
                            $this->mapboxService->geocodeAddress("{$address}, {$city}")
                        );
                    } else {
                        // Dernier rendez-vous de la journée
                        $lastAppointment = $dayAppointments->last();
                        $route = $this->mapboxService->getRoute(
                            $this->mapboxService->geocodeAddress("{$lastAppointment->adresse}, {$lastAppointment->ville}"),
                            $this->mapboxService->geocodeAddress("{$address}, {$city}")
                        );
                    }

                    break;
                }
            }

            $travelDistance = $route['distance_km'] ?? null;
            $travelDurationMinutes = $route['duration_minutes'] ?? $technician->default_traject_time;

            // Arrondir les valeurs comme demandé
            $travelDistance = $travelDistance !== null ? ceil($travelDistance) : null; // Arrondi au km supérieur
            $travelDurationMinutes = ceil($travelDurationMinutes / 10) * 10; // Arrondi à la dizaine de minutes supérieure

            $results[] = [
                'id' => $technician->id,
                'name' => "{$technician->prenom} {$technician->nom}",
                'next_availability_date' => $firstAvailableDate,
                'number_of_appointments' => $numberOfAppointments,
                'travel' => $travelDistance !== null
                    ? sprintf("%dkm et %d:%02d de trajet", $travelDistance, intdiv($travelDurationMinutes, 60), $travelDurationMinutes % 60)
                    : "N/A",
            ];
        }

        Log::info("Techniciens triés par disponibilité", ['count' => count($results)]);

        return response()->json(['technicians' => $results]);
    }

    private function timeStringToMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);
        return (int)$hours * 60 + (int)$minutes;
    }

    private function minutesToTimeString(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $remainingMinutes);
    }
    
    private function addAvailability(User $technician, int $startMinutes, int $endMinutes)
    {
        return [
            'id' => $technician->id,
            'name' => "{$technician->prenom} {$technician->nom}",
            'next_availability' => sprintf('%02d:%02d', intdiv($startMinutes, 60), $startMinutes % 60),
            'available_until' => sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60),
        ];
    }

    private function calculateDistance($coords1, $coords2)
    {
        [$lon1, $lat1] = $coords1;
        [$lon2, $lat2] = $coords2;

        $earthRadius = 6371; // Rayon de la Terre en km
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2 +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
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