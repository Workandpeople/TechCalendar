<?php

namespace App\Services;

use Illuminate\Support\Collection;

class SimulatedCrmAppointmentService
{
    /**
     * @return Collection<int, array<string, string>>
     */
    public function pending(int $limit = 5): Collection
    {
        return collect($this->appointments())
            ->shuffle()
            ->take($limit)
            ->values();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function appointments(): array
    {
        return [
            ['source' => 'CRM Alpha', 'first_name' => 'Camille', 'last_name' => 'Martin', 'phone' => '0601020304', 'address' => '10 Rue de la Paix, 75002 Paris, France'],
            ['source' => 'CRM Alpha', 'first_name' => 'Thomas', 'last_name' => 'Bernard', 'phone' => '0602030405', 'address' => '20 Quai Victor Augagneur, 69003 Lyon, France'],
            ['source' => 'CRM Beta', 'first_name' => 'Lea', 'last_name' => 'Robert', 'phone' => '0603040506', 'address' => '5 Rue du Chapeau Rouge, 33000 Bordeaux, France'],
            ['source' => 'CRM Beta', 'first_name' => 'Julien', 'last_name' => 'Petit', 'phone' => '0604050607', 'address' => '3 Rue Leon Gambetta, 31000 Toulouse, France'],
            ['source' => 'CRM Gamma', 'first_name' => 'Sarah', 'last_name' => 'Durand', 'phone' => '0605060708', 'address' => '12 La Canebiere, 13001 Marseille, France'],
            ['source' => 'CRM Gamma', 'first_name' => 'Nicolas', 'last_name' => 'Moreau', 'phone' => '0606070809', 'address' => '8 Rue Kleber, 67000 Strasbourg, France'],
            ['source' => 'CRM Alpha', 'first_name' => 'Emma', 'last_name' => 'Simon', 'phone' => '0607080910', 'address' => '14 Rue Royale, 59000 Lille, France'],
            ['source' => 'CRM Beta', 'first_name' => 'Hugo', 'last_name' => 'Laurent', 'phone' => '0608091011', 'address' => '4 Rue Graslin, 44000 Nantes, France'],
            ['source' => 'CRM Gamma', 'first_name' => 'Chloe', 'last_name' => 'Lefevre', 'phone' => '0609101112', 'address' => '6 Rue Jean Jaures, 35000 Rennes, France'],
            ['source' => 'CRM Alpha', 'first_name' => 'Antoine', 'last_name' => 'Michel', 'phone' => '0610111213', 'address' => '18 Avenue Jean Medecin, 06000 Nice, France'],
            ['source' => 'CRM Beta', 'first_name' => 'Manon', 'last_name' => 'Garcia', 'phone' => '0611121314', 'address' => '7 Rue Victor Hugo, 38000 Grenoble, France'],
            ['source' => 'CRM Gamma', 'first_name' => 'Lucas', 'last_name' => 'Roux', 'phone' => '0612131415', 'address' => '11 Rue Stanislas, 54000 Nancy, France'],
        ];
    }
}
