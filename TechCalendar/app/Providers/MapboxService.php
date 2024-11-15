<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MapboxService
{
    protected $mapboxToken;

    public function __construct()
    {
        $this->mapboxToken = "pk.eyJ1IjoiZGlubmljaGVydGwiLCJhIjoiY20zaGZ4dmc5MGJjdzJrcXpvcTU2ajg5ZiJ9.gfuUn87ezzfPm-hxtEDotw";
        Log::info("Mapbox Token depuis .env : {$this->mapboxToken}");
    }

    // Fonction pour géocoder une adresse en coordonnées (latitude, longitude)
    public function geocodeAddress($address)
    {
        $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/" . urlencode($address) . ".json";

        // Log de l'URL de requête pour Mapbox Geocoding API
        Log::info("Requête URL pour Mapbox Geocoding API : {$url}");

        $response = Http::get($url, [
            'access_token' => $this->mapboxToken,
            'limit' => 1
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['features'][0]['center'])) { // Utilisation de 'center' pour obtenir directement les coordonnées
                return $data['features'][0]['center']; // [longitude, latitude]
            }
        } else {
            Log::error("Mapbox geocoding API error: " . $response->body());
        }

        return null; // Retourne null si la géolocalisation échoue
    }

    // Fonction pour calculer l'itinéraire entre deux coordonnées
    public function getRoute($startCoordinates, $endCoordinates)
    {
        $url = "https://api.mapbox.com/directions/v5/mapbox/driving/{$startCoordinates[0]},{$startCoordinates[1]};{$endCoordinates[0]},{$endCoordinates[1]}";

        // Log de l'URL pour Mapbox Directions API
        Log::info("Requête URL pour Mapbox Directions API : {$url}");

        $response = Http::get($url, [
            'access_token' => $this->mapboxToken,
            'geometries' => 'geojson'
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (!empty($data['routes'])) {
                $route = $data['routes'][0];
                return [
                    'distance_km' => round($route['distance'] / 1000, 2), // Distance en kilomètres, arrondie à 2 décimales
                    'duration_minutes' => round($route['duration'] / 60, 2) // Durée en minutes, arrondie à 2 décimales
                ];
            }
        } else {
            Log::error("Mapbox directions API error: " . $response->body());
        }

        return null; // Retourne null si le calcul de l'itinéraire échoue
    }

    // Fonction pour obtenir la distance et le temps de trajet entre deux adresses
    public function calculateRouteBetweenAddresses($startAddress, $endAddress)
    {
        $startCoordinates = $this->geocodeAddress($startAddress);
        $endCoordinates = $this->geocodeAddress($endAddress);

        if ($startCoordinates && $endCoordinates) {
            return $this->getRoute($startCoordinates, $endCoordinates);
        }

        return null; // Retourne null si les coordonnées de départ ou d'arrivée ne sont pas disponibles
    }
}