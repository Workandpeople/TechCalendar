<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WAPetGCUser;
use App\Models\WAPetGCTech;
use Illuminate\Support\Facades\Log;

class GraphController extends Controller
{
    /**
     * Affiche la vue des graphiques des techniciens.
     */
    public function graphUser()
    {
        try {
            // Log avant la récupération des techniciens
            Log::info('Fetching users with the role "tech" and their associated technician details.');

            // Récupération des utilisateurs avec le rôle "tech"
            $technicians = WAPetGCUser::where('role', 'tech')->with('tech')->get();

            // Log après la récupération
            Log::info('Technicians retrieved successfully.', [
                'count' => $technicians->count(),
                'technicians' => $technicians->toArray(),
            ]);

            return view('admin.graph_user', compact('technicians'));
        } catch (\Exception $e) {
            // Log en cas d'erreur
            Log::error('Error fetching technicians.', [
                'error_message' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            // Retourne une vue avec un message d'erreur ou une redirection
            return redirect()->back()->withErrors('Erreur lors de la récupération des techniciens.');
        }
    }

    /**
     * API pour récupérer les techniciens avec une recherche dynamique.
     */
    public function getTechnicians(Request $request)
    {
        try {
            $search = $request->input('search', '');
            Log::info('Fetching technicians with search query.', ['search' => $search]);

            // Récupérer les utilisateurs avec le rôle "tech" et filtrer par recherche
            $technicians = WAPetGCUser::where('role', 'tech')
                ->when($search, function ($query) use ($search) {
                    $query->where('prenom', 'LIKE', "%$search%")
                        ->orWhere('nom', 'LIKE', "%$search%");
                })
                ->with('tech') // Charger la relation 'tech'
                ->get();

            Log::info('Technicians fetched successfully.', [
                'count' => $technicians->count(),
                'technicians' => $technicians->toArray(),
            ]);

            return response()->json(['technicians' => $technicians], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching technicians.', [
                'error_message' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Erreur lors de la récupération des techniciens.'], 500);
        }
    }

    /**
     * API pour récupérer les statistiques des techniciens.
     */
    public function getTechnicianStats(Request $request)
    {
        try {
            // Récupération des paramètres de la requête
            $techIds = $request->input('techIds', []);
            $from = $request->input('from', '1970-01-01');
            $to = $request->input('to', now()->toDateString());

            Log::info('Fetching technician stats.', [
                'techIds' => $techIds,
                'date_range' => compact('from', 'to'),
            ]);

            // Récupération des techniciens et leurs rendez-vous dans la plage de dates
            $technicians = WAPetGCTech::with(['appointments' => function ($query) use ($from, $to) {
                $query->whereBetween('start_at', [$from, $to]);
            }])
            ->whereIn('id', $techIds)
            ->get();

            // Initialisation des statistiques
            $stats = [
                'appointments' => [
                    'labels' => [],
                    'values' => [],
                ],
                'distances' => [
                    'labels' => [],
                    'values' => [],
                ],
                'timeSpent' => [
                    'labels' => [],
                    'values' => [],
                ],
                'costs' => [
                    'labels' => [],
                    'values' => [],
                ],
            ];

            // Construction des statistiques par technicien
            foreach ($technicians as $tech) {
                $name = "{$tech->user->prenom} {$tech->user->nom}";

                $stats['appointments']['labels'][] = $name;
                $stats['appointments']['values'][] = $tech->appointments->count();

                $stats['distances']['labels'][] = $name;
                $stats['distances']['values'][] = $tech->appointments->sum('trajet_distance');

                $stats['timeSpent']['labels'][] = $name;
                $stats['timeSpent']['values'][] = $tech->appointments->sum('trajet_time');

                $stats['costs']['labels'][] = $name;
                $stats['costs']['values'][] = $tech->appointments->sum('trajet_distance') * 0.5; // Exemple de calcul
            }

            Log::info('Technician stats retrieved successfully.', ['stats' => $stats]);

            return response()->json($stats, 200);
        } catch (\Exception $e) {
            Log::error('Error fetching technician stats.', ['error_message' => $e->getMessage()]);

            return response()->json(['error' => 'Erreur lors de la récupération des statistiques.'], 500);
        }
    }
}