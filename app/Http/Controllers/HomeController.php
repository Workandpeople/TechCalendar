<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCService;

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
