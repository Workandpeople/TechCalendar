<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCService;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCUser;
use Illuminate\Support\Str;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate   = $request->input('end_date');

        // Requête de base pour récupérer les RDV (avecTrashed si besoin)
        $appointmentsQuery = WAPetGCAppointment::with(['tech.user','service'])->withTrashed();

        // 1) S'il n'y a pas de date saisie, on prend la plage [mois précédent -> mois suivant]
        if (!$startDate && !$endDate) {
            $startOfPrevMonth = now()->startOfMonth()->subMonth();
            $endOfNextMonth   = now()->endOfMonth()->addMonth();
            $appointmentsQuery->whereBetween('start_at', [$startOfPrevMonth, $endOfNextMonth]);
        }
        // 2) Sinon on filtre dynamiquement
        else {
            if ($startDate) {
                $appointmentsQuery->where('start_at', '>=', $startDate);
            }
            if ($endDate) {
                $appointmentsQuery->where('end_at', '<=', $endDate);
            }
        }

        // Récupération des rendez-vous
        $appointmentsRange = $appointmentsQuery->get();

        // On prépare 4 collections
        // a) "brute" : l'ensemble pour le line chart mensuel
        $appointmentsMonthlyLine = $appointmentsRange;

        // b) Pour le pie chart, la répartition services et les coûts km
        //    -> par défaut (pas de dates fournies) : on ne garde que le mois en cours.
        //    -> si l'utilisateur a fourni un range, on prend tout.
        if (!$startDate && !$endDate) {
            $startOfCurrent = now()->startOfMonth();
            $endOfCurrent   = now()->endOfMonth();

            $appointmentsFiltered = $appointmentsRange->filter(function ($a) use ($startOfCurrent, $endOfCurrent) {
                return $a->start_at >= $startOfCurrent && $a->start_at <= $endOfCurrent;
            });
        } else {
            $appointmentsFiltered = $appointmentsRange;
        }

        // Convertir en array indexé pour éviter les soucis JS (forEach/filter)
        $appointmentsPie      = $appointmentsFiltered->values();
        $appointmentsServices = $appointmentsFiltered->values();
        $appointmentsKmCost   = $appointmentsFiltered->values();
        // Le line chart garde la plage "3 mois" ou la plage filtrée
        $appointmentsMonthlyLine = $appointmentsMonthlyLine->values();

        // Récupération des services
        $services = WAPetGCService::all();

        $users = [
            ['nom' => 'AMRANE', 'prenom' => 'ANIS', 'zip' => '92600', 'ville' => 'Asnières-sur-Seine', 'adresse' => '1 Place de l’Hôtel de Ville, 92600 Asnières-sur-Seine'],
            ['nom' => 'ELGYCMI', 'prenom' => 'SAID', 'zip' => '95000', 'ville' => 'Cergy', 'adresse' => '3 Place de l’Olympe-de-Gouges, 95801 Cergy-Pontoise Cedex'],
            ['nom' => 'DAOUD', 'prenom' => 'YACHOUROUTUA', 'zip' => '54000', 'ville' => 'Nancy', 'adresse' => '1 Place Stanislas, 54000 Nancy'],
            ['nom' => 'SULEYMAN', 'prenom' => 'ILHAN', 'zip' => '57645', 'ville' => 'Retonfey', 'adresse' => '21 Place du Gué, 57645 Retonfey'],
            ['nom' => 'BALLEKENS', 'prenom' => 'BERTRAND', 'zip' => '59553', 'ville' => 'Cuincy', 'adresse' => '15 Rue François-Anicot, 59553 Cuincy'],
            ['nom' => 'RUVEN', 'prenom' => 'BENJAMIN', 'zip' => '27000', 'ville' => 'Évreux', 'adresse' => 'Place du Général de Gaulle, 27000 Évreux'],
            ['nom' => 'ILLIEN', 'prenom' => 'ANTOINE', 'zip' => '35720', 'ville' => 'Châteaubourg', 'adresse' => 'Place de la Mairie, 35720 Châteaubourg'],
            ['nom' => 'RAYNAUD', 'prenom' => 'BENJAMIN', 'zip' => '63000', 'ville' => 'Clermont-Ferrand', 'adresse' => '10 Rue Philippe Marcombes, 63000 Clermont-Ferrand'],
            ['nom' => 'CHRISNACH', 'prenom' => 'EMMANUEL', 'zip' => '22140', 'ville' => 'Bégard', 'adresse' => 'Place de la République, 22140 Bégard'],
            ['nom' => 'JEBARI', 'prenom' => 'RAMZY', 'zip' => '69000', 'ville' => 'Lyon', 'adresse' => '1 Place Louis Pradel, 69001 Lyon'],
            ['nom' => 'DUPUIS', 'prenom' => 'FABRICE', 'zip' => '33980', 'ville' => 'Audenge', 'adresse' => 'Place du Général de Gaulle, 33980 Audenge'],
            ['nom' => 'FEREC', 'prenom' => 'SAMUEL', 'zip' => '13000', 'ville' => 'Marseille', 'adresse' => 'Place Daviel, 13002 Marseille'],
            ['nom' => 'SERIGN', 'prenom' => 'MBOW', 'zip' => '66000', 'ville' => 'Perpignan', 'adresse' => 'Place de la Loge, 66000 Perpignan'],
            ['nom' => 'VIVIER', 'prenom' => 'RODOLPHE', 'zip' => '22450', 'ville' => 'La Roche-Derrien', 'adresse' => 'Place du Martray, 22450 La Roche-Derrien'],
            ['nom' => 'AKIR', 'prenom' => 'NASSIM', 'zip' => '77270', 'ville' => 'Villeparisis', 'adresse' => 'Place du Marché, 77270 Villeparisis'],
            ['nom' => 'COHEN', 'prenom' => 'ETHAN', 'zip' => '92600', 'ville' => 'Asnières-sur-Seine', 'adresse' => '1 Place de l’Hôtel de Ville, 92600 Asnières-sur-Seine'],
            ['nom' => 'DELALLEAU', 'prenom' => 'NATHALIE', 'zip' => '16330', 'ville' => 'Saint-Amant-de-Boixe', 'adresse' => 'Place de la Mairie, 16330 Saint-Amant-de-Boixe'],
            ['nom' => 'SAUVETRE', 'prenom' => 'CELINE', 'zip' => '33660', 'ville' => 'Salles', 'adresse' => 'Place de la Mairie, 33660 Salles'],
            ['nom' => 'BOUZALIM', 'prenom' => 'YOUSSEF', 'zip' => '45570', 'ville' => 'Dampierre-en-Burly', 'adresse' => 'Place de l’Église, 45570 Dampierre-en-Burly'],
            ['nom' => 'FATMI', 'prenom' => 'YASSINE', 'zip' => '02200', 'ville' => 'Soissons', 'adresse' => 'Place de l’Hôtel de Ville, 02200 Soissons'],
        ];

        foreach ($users as $data) {
            $user = WAPetGCUser::create([
                'id' => Str::uuid(),
                'nom' => $data['nom'],
                'prenom' => $data['prenom'],
                'email' => strtolower($data['prenom'] . '.' . $data['nom'] . '@geniuscontrole.fr'),
                'password' => bcrypt('password'),
                'role' => 'tech',
            ]);

            WAPetGCTech::create([
                'id' => Str::uuid(),
                'user_id' => $user->id,
                'phone' => '0102030405',
                'adresse' => $data['adresse'],
                'zip_code' => $data['zip'],
                'city' => $data['ville'],
                'default_start_at' => '08:00:00',
                'default_end_at' => '18:00:00',
                'default_rest_time' => 60,
            ]);
        }

        // Retour à la vue
        return view('home', [
            'appointmentsPie'         => $appointmentsPie,
            'appointmentsServices'    => $appointmentsServices,
            'appointmentsKmCost'      => $appointmentsKmCost,
            'appointmentsMonthlyLine' => $appointmentsMonthlyLine,
            'services'                => $services,
            // Pour éventuellement ré-afficher les dates saisies
            'startDate'               => $startDate,
            'endDate'                 => $endDate,
        ]);
    }
}
