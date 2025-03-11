<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class MapboxService
{
    protected $mapboxToken;

    public function __construct()
    {
        $this->mapboxToken = "pk.eyJ1IjoiZGlubmljaGVydGwiLCJhIjoiY20zaGZ4dmc5MGJjdzJrcXpvcTU2ajg5ZiJ9.gfuUn87ezzfPm-hxtEDotw";
    }

    // Fonction pour géocoder une adresse en coordonnées (latitude, longitude)
    public function geocodeAddress($address)
    {
        $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/" . urlencode($address) . ".json";

        try {
            Log::info("📍 Envoi de la requête Mapbox Geocoding pour : $address");

            $response = Http::withoutVerifying()
                ->timeout(30)
                ->get($url, [
                    'access_token' => $this->mapboxToken,
                    'limit' => 1
                ]);

            Log::info("🔄 Réponse reçue de Mapbox : HTTP " . $response->status());

            if ($response->successful()) {
                $data = $response->json();
                Log::info("✅ Réponse JSON : " . json_encode($data));

                if (isset($data['features'][0]['center'])) {
                    return $data['features'][0]['center']; // [longitude, latitude]
                } else {
                    Log::warning("⚠️ Aucune coordonnée trouvée pour l'adresse : $address");
                }
            } else {
                Log::error("❌ Erreur Mapbox Geocoding API : " . $response->body());
            }
        } catch (Exception $e) {
            Log::error("💥 Exception dans geocodeAddress : " . $e->getMessage());
        }

        return null;
    }

    // Fonction pour calculer l'itinéraire entre deux coordonnées
    public function getRoute($startCoordinates, $endCoordinates)
    {
        $url = "https://api.mapbox.com/directions/v5/mapbox/driving/{$startCoordinates[0]},{$startCoordinates[1]};{$endCoordinates[0]},{$endCoordinates[1]}";

        try {
            Log::info("🚗 Calcul de l'itinéraire entre " . json_encode($startCoordinates) . " et " . json_encode($endCoordinates));

            $response = Http::withoutVerifying()
                ->timeout(30)
                ->get($url, [
                    'access_token' => $this->mapboxToken,
                    'geometries' => 'geojson'
                ]);

            Log::info("🔄 Réponse reçue de Mapbox Directions : HTTP " . $response->status());

            if ($response->successful()) {
                $data = $response->json();
                Log::info("✅ Réponse JSON : " . json_encode($data));

                if (!empty($data['routes'])) {
                    $route = $data['routes'][0];
                    return [
                        'distance_km' => round($route['distance'] / 1000, 2), // Distance en kilomètres
                        'duration_minutes' => round($route['duration'] / 60, 2) // Durée en minutes
                    ];
                } else {
                    Log::warning("⚠️ Aucune route trouvée pour les coordonnées données.");
                }
            } else {
                Log::error("❌ Erreur Mapbox Directions API : " . $response->body());
            }
        } catch (Exception $e) {
            Log::error("💥 Exception dans getRoute : " . $e->getMessage());
        }

        return null;
    }

    // Fonction pour obtenir la distance et le temps de trajet entre deux adresses
    public function calculateRouteBetweenAddresses($startAddress, $endAddress)
    {
        Log::info("📌 Début du calcul d'itinéraire entre : $startAddress et $endAddress");

        $startCoordinates = $this->geocodeAddress($startAddress);
        $endCoordinates = $this->geocodeAddress($endAddress);

        if ($startCoordinates && $endCoordinates) {
            Log::info("✅ Coordonnées obtenues : Départ -> " . json_encode($startCoordinates) . " | Arrivée -> " . json_encode($endCoordinates));
            return $this->getRoute($startCoordinates, $endCoordinates);
        }

        Log::error("❌ Impossible de calculer l'itinéraire : coordonnées manquantes.");
        return null;
    }
}
