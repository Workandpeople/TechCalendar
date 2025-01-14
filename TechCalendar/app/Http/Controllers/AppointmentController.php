<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCService;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCAppointment;
use App\Providers\MapboxService;
use Illuminate\Support\Facades\Log;


class AppointmentController extends Controller
{
    protected $mapbox;

    public function __construct(MapboxService $mapbox)
    {
        $this->mapbox = $mapbox;
    }

    public function takeAppointement()
    {
        // Récupération des prestations et techniciens
        $services = WAPetGCService::all();
        $technicians = WAPetGCTech::with('user')->get();

        return view('assistant.take_appointements', compact('services', 'technicians'));
    }

    public function submitAppointment(Request $request)
    {
        try {
            // 1) Validation de base
            $validated = $request->validate([
                'clientAddressStreet' => 'required|string|max:255',
                'clientAddressPostalCode' => 'required|string|size:5',
                'clientAddressCity' => 'required|string|max:255',
                'serviceId' => 'required|exists:WAPetGC_Services,id',
                'duration' => 'required|integer|min:1',
            ]);
    
            $clientAddress = "{$validated['clientAddressStreet']}, {$validated['clientAddressPostalCode']} {$validated['clientAddressCity']}";
            $department = substr($validated['clientAddressPostalCode'], 0, 2); // ex: '59' pour code postal 59000
    
            // 2) Récupérer d'abord les techniciens du département
            $deptTechs = WAPetGCTech::where('zip_code', 'LIKE', "$department%")->with('user')->get();
    
            // 3) Calculer distance/temps pour ces techs
            foreach ($deptTechs as $tech) {
                $techAddress = "{$tech->adresse}, {$tech->zip_code} {$tech->city}";
                $route = $this->mapbox->calculateRouteBetweenAddresses($clientAddress, $techAddress);
                $tech->distance_km = $route['distance_km'] ?? 99999;    // Valeur par défaut très grande si null
                $tech->duration_minutes = $route['duration_minutes'] ?? 99999;
            }
    
            // 4) Vérifier combien on a de techs
            if ($deptTechs->count() === 0) {
                // A) Aucun tech dans le département -> on prend tous les techs
                $allTechs = WAPetGCTech::with('user')->get();
    
                // Calculer distance/temps pour TOUS
                foreach ($allTechs as $tech) {
                    $techAddress = "{$tech->adresse}, {$tech->zip_code} {$tech->city}";
                    $route = $this->mapbox->calculateRouteBetweenAddresses($clientAddress, $techAddress);
                    $tech->distance_km = $route['distance_km'] ?? 99999;
                    $tech->duration_minutes = $route['duration_minutes'] ?? 99999;
                }
    
                // Trier par distance croissante
                $sortedAllTechs = $allTechs->sortBy('distance_km')->values();
                // Garder les 3 premiers
                $finalTechs = $sortedAllTechs->take(3);
    
            } elseif ($deptTechs->count() < 3) {
                // B) Moins de 3 techs dans le département
                // => On complète avec d'autres techniciens (hors département)
                $otherTechs = WAPetGCTech::where('zip_code', 'NOT LIKE', "$department%")->with('user')->get();
    
                // Calculer distances pour ceux hors département
                foreach ($otherTechs as $tech) {
                    $techAddress = "{$tech->adresse}, {$tech->zip_code} {$tech->city}";
                    $route = $this->mapbox->calculateRouteBetweenAddresses($clientAddress, $techAddress);
                    $tech->distance_km = $route['distance_km'] ?? 99999;
                    $tech->duration_minutes = $route['duration_minutes'] ?? 99999;
                }
    
                // Fusionner les deux listes
                $merged = $deptTechs->merge($otherTechs);
    
                // Trier par distance croissante
                $sortedMerged = $merged->sortBy('distance_km')->values();
    
                // Garder les 3 premiers
                $finalTechs = $sortedMerged->take(3);
    
            } else {
                // C) On a déjà >= 3 techs dans le département
                // => On ne prend que ceux-là, triés par distance
                $sortedDeptTechs = $deptTechs->sortBy('distance_km')->values();
                // Prendre les 3 premiers
                $finalTechs = $sortedDeptTechs->take(3);
            }
    
            // 5) Extraction des IDs
            $availableTechIds = $finalTechs->pluck('id');
    
            return response()->json([
                'technicians' => $finalTechs->values(), // On renvoie la collection finale de techs
                'availableTechIds' => $availableTechIds,
            ], 200);
    
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la soumission du rendez-vous.', [
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return response()->json(['error' => 'Une erreur est survenue lors du traitement.'], 500);
        }
    }
                
    public function manualAppointment(Request $request)
    {
        // Validation des champs
        $validated = $request->validate([
            'clientFirstName' => 'required|string|max:255',
            'clientLastName' => 'required|string|max:255',
            'clientPhone' => 'required|string|max:15',
            'clientAddressStreet' => 'required|string|max:255',
            'clientAddressPostalCode' => 'required|string|size:5',
            'clientAddressCity' => 'required|string|max:255',
            'techId' => 'required|exists:WAPetGC_Tech,id',
            'serviceId' => 'required|exists:WAPetGC_Services,id',
            'duration' => 'required|integer|min:1',
            'appointmentDate' => 'required|date',
            'startTime' => 'required|date_format:H:i',
            'comments' => 'nullable|string',
        ]);

        // Adresse du lieu de rendez-vous
        $clientAddress = "{$validated['clientAddressStreet']}, {$validated['clientAddressPostalCode']} {$validated['clientAddressCity']}";

        // Récupération du technicien
        $technician = WAPetGCTech::findOrFail($validated['techId']);
        $techAddress = "{$technician->adresse}, {$technician->zip_code} {$technician->city}";

        // Calcul des distances et temps avec Mapbox
        $route = $this->mapbox->calculateRouteBetweenAddresses($clientAddress, $techAddress);

        // Conversion de l'heure de début et calcul de l'heure de fin
        $startTime = "{$validated['appointmentDate']} {$validated['startTime']}";
        $endTime = date('Y-m-d H:i', strtotime($startTime . " +{$validated['duration']} minutes"));

        // Création du rendez-vous
        WAPetGCAppointment::create([
            'tech_id' => $validated['techId'],
            'service_id' => $validated['serviceId'], // Enregistrement du service
            'client_fname' => $validated['clientFirstName'],
            'client_lname' => $validated['clientLastName'],
            'client_adresse' => $validated['clientAddressStreet'],
            'client_zip_code' => $validated['clientAddressPostalCode'],
            'client_city' => $validated['clientAddressCity'],
            'client_phone' => $validated['clientPhone'],
            'start_at' => $startTime,
            'duration' => $validated['duration'],
            'end_at' => $endTime,
            'comment' => $validated['comments'],
            'trajet_time' => $route['duration_minutes'] ?? null,
            'trajet_distance' => $route['distance_km'] ?? null,
        ]);

        return redirect()->route('assistant.take_appointements')->with('success', 'Rendez-vous placé avec succès !');
    }

    public function deleteAppointment($id)
    {
        try {
            $appointment = WAPetGCAppointment::findOrFail($id);
            $appointment->delete();

            Log::info('[AppointmentController] Rendez-vous supprimé : '.$id);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('[AppointmentController] Erreur deleteAppointment : '.$e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function editAppointment(Request $request, $id)
    {
        try {
            Log::info('editAppointment: raw input = ', $request->all());

            $validated = $request->validate([
                'client_fname'    => 'required|string|max:255',
                'client_lname'    => 'required|string|max:255',
                'client_phone'    => 'nullable|string|max:15',
                'client_adresse'  => 'required|string|max:255',
                'client_zip_code' => 'required|string|max:5',
                'client_city'     => 'required|string|max:255',
                'comment'         => 'nullable|string',

                'techId'          => 'required|exists:WAPetGC_Tech,id',
                'serviceId'       => 'required|exists:WAPetGC_Services,id',
                'appointmentDate' => 'required|date',
                'startTime'       => 'required|date_format:H:i',
                'endTime'         => 'nullable|date_format:H:i', 
                'duration'        => 'required|integer|min:1',
            ]);

            $appointment = WAPetGCAppointment::findOrFail($id);

            // Reconstruire start_at
            $start_at = "{$validated['appointmentDate']} {$validated['startTime']}";

            // endTime : soit fourni, soit on recalcule
            if (!empty($validated['endTime'])) {
                $end_at = "{$validated['appointmentDate']} {$validated['endTime']}";
            } else {
                $end_at = date('Y-m-d H:i', strtotime("$start_at +{$validated['duration']} minutes"));
            }

            $appointment->update([
                'client_fname'   => $validated['client_fname'],
                'client_lname'   => $validated['client_lname'],
                'client_phone'   => $validated['client_phone'],
                'client_adresse' => $validated['client_adresse'],
                'client_zip_code'=> $validated['client_zip_code'],
                'client_city'    => $validated['client_city'],
                'comment'        => $validated['comment'],

                'tech_id'        => $validated['techId'],
                'service_id'     => $validated['serviceId'],
                'start_at'       => $start_at,
                'end_at'         => $end_at,
                'duration'       => $validated['duration'],
            ]);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('Erreur editAppointment : '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}