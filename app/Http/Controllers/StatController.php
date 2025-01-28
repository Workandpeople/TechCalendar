<?php

namespace App\Http\Controllers;

use App\Models\WAPetGCAppointment;
use App\Models\WAPetGCService;
use App\Models\WAPetGCUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StatController extends Controller
{
    public function index(Request $request)
    {
        $startDate     = $request->input('start_date');
        $endDate       = $request->input('end_date');
        $searchTechId  = $request->input('search_tech_id');  // L’id du WAPetGCTech
        $searchTechStr = $request->input('search_tech');     // Le nom/prénom affiché

        Log::info("StatController@index => reçus", [
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'searchTechId'  => $searchTechId,
            'searchTechStr' => $searchTechStr
        ]);

        // 1) Si pas de tech_id => pas de stats
        if (!$searchTechId) {
            Log::info("Aucun tech_id => on renvoie des collections vides.");
            return view('stats', [
                'appointmentsPie'         => collect([]),
                'appointmentsServices'    => collect([]),
                'appointmentsKmCost'      => collect([]),
                'appointmentsMonthlyLine' => collect([]),
                'services'                => collect([]),
                'startDate'               => $startDate,
                'endDate'                 => $endDate,
                'searchTech'              => $searchTechStr,
                'search_tech_id'          => null,
            ]);
        }

        // 2) Sinon, on filtre par ce tech_id
        $appointmentsQuery = WAPetGCAppointment::with(['tech.user','service'])->withTrashed()
            ->where('tech_id', $searchTechId);

        // 3) Filtrage "Dates"
        if (!$startDate && !$endDate) {
            // => [mois précédent -> mois suivant]
            $startOfPrevMonth = now()->startOfMonth()->subMonth();
            $endOfNextMonth   = now()->endOfMonth()->addMonth();
            Log::info("Aucune date => on prend [mois précédent -> mois suivant]", [
                $startOfPrevMonth,
                $endOfNextMonth
            ]);

            $appointmentsQuery->whereBetween('start_at', [$startOfPrevMonth, $endOfNextMonth]);
        } else {
            if ($startDate) {
                Log::info("Filtre start_at >= $startDate");
                $appointmentsQuery->where('start_at', '>=', $startDate);
            }
            if ($endDate) {
                Log::info("Filtre end_at <= $endDate");
                $appointmentsQuery->where('end_at', '<=', $endDate);
            }
        }

        $appointmentsRange = $appointmentsQuery->get();
        Log::info("RDV récupérés => total : " . $appointmentsRange->count());

        // 4) Graph Mensuel => On prend la plage [mois précédent -> mois suivant] OU la plage saisie
        $appointmentsMonthlyLine = $appointmentsRange;

        // 5) Pour Pie/Services/Km
        //    Par défaut (pas de dates saisies) => on montre *seulement* le mois en cours
        if (!$startDate && !$endDate) {
            $startOfCurrent = now()->startOfMonth();
            $endOfCurrent   = now()->endOfMonth();
            Log::info("Pas de dates => on limite Pie/Services/Km au mois en cours : $startOfCurrent -> $endOfCurrent");

            $appointmentsFiltered = $appointmentsRange->filter(function($a) use ($startOfCurrent, $endOfCurrent) {
                return $a->start_at >= $startOfCurrent && $a->start_at <= $endOfCurrent;
            });
        } else {
            // Si l'utilisateur a saisi des dates, on affiche la plage *complète* pour tous les graphiques
            $appointmentsFiltered = $appointmentsRange;
        }

        $appointmentsPie         = $appointmentsFiltered->values();
        $appointmentsServices    = $appointmentsFiltered->values();
        $appointmentsKmCost      = $appointmentsFiltered->values();
        $appointmentsMonthlyLine = $appointmentsMonthlyLine->values();

        $services = WAPetGCService::all();

        Log::info("On renvoie la vue stats =>", [
            'pie_count'      => $appointmentsPie->count(),
            'services_count' => $appointmentsServices->count(),
            'km_count'       => $appointmentsKmCost->count(),
            'monthly_count'  => $appointmentsMonthlyLine->count(),
        ]);

        return view('stats', [
            'appointmentsPie'         => $appointmentsPie,
            'appointmentsServices'    => $appointmentsServices,
            'appointmentsKmCost'      => $appointmentsKmCost,
            'appointmentsMonthlyLine' => $appointmentsMonthlyLine,
            'services'                => $services,
            'startDate'               => $startDate,
            'endDate'                 => $endDate,
            'searchTech'              => $searchTechStr,
            'search_tech_id'          => $searchTechId,
        ]);
    }

    // ====================================
    // SUGGESTIONS DE TECHNICIENS (AJAX)
    // ====================================
    public function search(Request $request)
    {
        $q = $request->input('q');
        Log::info("search => q = $q");

        $results = WAPetGCUser::whereHas('tech')
            ->where(function($query) use ($q) {
                $query->where('nom', 'LIKE', "%$q%")
                      ->orWhere('prenom', 'LIKE', "%$q%");
            })
            ->take(10)
            ->get();

        Log::info("Nombre de résultats => " . $results->count());

        // On renvoie l'ID du TECH (WAPetGCTech::id), pas l'ID du user
        $formatted = $results->map(function($user) {
            return [
                'id'       => $user->tech->id, // pour le where('tech_id', $searchTechId)
                'fullname' => $user->prenom . ' ' . $user->nom
            ];
        });

        return response()->json($formatted);
    }
}
