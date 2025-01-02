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
            Log::info('Validation des champs pour la soumission du rendez-vous.', $request->all());
    
            // Validation des champs
            $validated = $request->validate([
                'clientAddressStreet' => 'required|string|max:255',
                'clientAddressPostalCode' => 'required|string|size:5',
                'clientAddressCity' => 'required|string|max:255',
                'serviceId' => 'required|exists:WAPetGC_Services,id',
                'duration' => 'required|integer|min:1',
            ]);
    
            Log::info('Validation réussie.', ['validated_data' => $validated]);
    
            // Adresse du client
            $clientAddress = "{$validated['clientAddressStreet']}, {$validated['clientAddressPostalCode']} {$validated['clientAddressCity']}";
            Log::info('Adresse complète du client générée.', ['client_address' => $clientAddress]);
    
            // Extraction du département
            $department = substr($validated['clientAddressPostalCode'], 0, 2);
            Log::info('Département extrait du code postal.', ['department' => $department]);
    
            // Récupération des techniciens dans le même département
            $technicians = WAPetGCTech::where('zip_code', 'LIKE', "$department%")->with('user')->get();
            Log::info('Techniciens récupérés.', ['count' => $technicians->count()]);
    
            // Calcul des distances et temps avec Mapbox
            foreach ($technicians as $tech) {
                $techAddress = "{$tech->adresse}, {$tech->zip_code} {$tech->city}";
                Log::info('Adresse du technicien.', ['tech_address' => $techAddress]);
    
                $route = $this->mapbox->calculateRouteBetweenAddresses($clientAddress, $techAddress);
    
                $tech->distance_km = $route['distance_km'] ?? 'N/A';
                $tech->duration_minutes = $route['duration_minutes'] ?? 'N/A';
    
                Log::info('Route calculée via Mapbox.', [
                    'tech_id' => $tech->id,
                    'distance_km' => $tech->distance_km,
                    'duration_minutes' => $tech->duration_minutes,
                ]);
            }
    
            return response()->json(['technicians' => $technicians], 200);
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
}