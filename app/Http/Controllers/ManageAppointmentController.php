<?php

namespace App\Http\Controllers;

use App\Models\WAPetGCAppointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WAPetGCTech;
use App\Models\WAPetGCService;

class ManageAppointmentController extends Controller
{
    public function index(Request $request)
    {
        Log::info('Affichage des rendez-vous.');

        $sortableColumns = ['tech', 'service', 'client', 'start_at'];
        $sortColumn = $request->get('sort', 'start_at');
        $direction = $request->get('direction', 'asc');

        if (!in_array($sortColumn, $sortableColumns)) {
            $sortColumn = 'start_at';
        }

        $appointmentsQuery = WAPetGCAppointment::with(['tech.user', 'service'])->withTrashed();

        // Recherche par nom/prénom du client
        if ($request->filled('search')) {
            $search = $request->input('search');
            $appointmentsQuery->where(function ($query) use ($search) {
                $query->where('client_fname', 'LIKE', "%{$search}%")
                      ->orWhere('client_lname', 'LIKE', "%{$search}%");
            });
        }

        // Recherche par nom/prénom du technicien
        if ($request->filled('tech_search')) {
            $techSearch = $request->input('tech_search');
            $appointmentsQuery->whereHas('tech.user', function ($techQuery) use ($techSearch) {
                $techQuery->where('nom', 'LIKE', "%{$techSearch}%")
                          ->orWhere('prenom', 'LIKE', "%{$techSearch}%");
            });
        }

        // Filtrage par département de l'adresse du RDV
        if ($request->filled('department')) {
            $department = $request->input('department');
            $appointmentsQuery->where('client_zip_code', 'LIKE', $department . '%');
        }

        // Gestion du tri
        switch ($sortColumn) {
            case 'tech':
                $appointmentsQuery->join('WAPetGC_Tech', 'WAPetGC_Appointments.tech_id', '=', 'WAPetGC_Tech.id')
                    ->join('WAPetGC_Users', 'WAPetGC_Tech.user_id', '=', 'WAPetGC_Users.id')
                    ->orderBy('WAPetGC_Users.nom', $direction);
                break;
            case 'service':
                $appointmentsQuery->join('WAPetGC_Services', 'WAPetGC_Appointments.service_id', '=', 'WAPetGC_Services.id')
                    ->orderBy('WAPetGC_Services.name', $direction);
                break;
            case 'client':
                $appointmentsQuery->orderBy('client_lname', $direction)
                    ->orderBy('client_fname', $direction);
                break;
            case 'start_at':
            default:
                $appointmentsQuery->orderBy('start_at', $direction);
                break;
        }

        $appointments = $appointmentsQuery->paginate(10)->appends($request->query());

        // ✅ Correction : Ajout de la variable `$technicians`
        $technicians = WAPetGCTech::with('user')->get();

        // ✅ Correction : Ajout de la variable `$services`
        $services = WAPetGCService::all();

        return view('manageAppointments', compact('appointments', 'technicians', 'services'));
    }

    public function search(Request $request)
    {
        // Récupération des 3 filtres
        $query       = $request->get('query', '');        // Par client
        $techSearch  = $request->get('tech_search', '');  // Par technicien
        $department  = $request->get('department', '');   // Par département

        // Récupération du tri
        $sortableColumns = ['tech', 'service', 'client', 'start_at'];
        $sortColumn = $request->get('sort', 'start_at');
        $direction  = $request->get('direction', 'asc');
        if (!in_array($sortColumn, $sortableColumns)) {
            $sortColumn = 'start_at';
        }

        $appointmentsQuery = WAPetGCAppointment::with(['tech.user', 'service']);

        // 1) Filtre client => "client_fname/lname LIKE '$query%'"
        if (!empty($query)) {
            $appointmentsQuery->where(function ($q) use ($query) {
                $q->where('client_fname', 'LIKE', $query.'%')
                  ->orWhere('client_lname', 'LIKE', $query.'%');
            });
        }

        // 2) Filtre technicien => "tech.user.nom/prenom LIKE '$techSearch%'"
        if (!empty($techSearch)) {
            $appointmentsQuery->whereHas('tech.user', function ($techQ) use ($techSearch) {
                $techQ->where('nom', 'LIKE', $techSearch.'%')
                      ->orWhere('prenom', 'LIKE', $techSearch.'%');
            });
        }

        // 3) Filtre département => "client_zip_code LIKE '$department%'"
        if (!empty($department)) {
            $appointmentsQuery->where('client_zip_code', 'LIKE', $department.'%');
        }

        // 4) Gestion du tri
        switch ($sortColumn) {
            case 'tech':
                // Jointure sur WAPetGC_Tech + WAPetGC_Users
                $appointmentsQuery
                    ->join('WAPetGC_Tech', 'WAPetGC_Appointments.tech_id', '=', 'WAPetGC_Tech.id')
                    ->join('WAPetGC_Users', 'WAPetGC_Tech.user_id', '=', 'WAPetGC_Users.id')
                    ->orderBy('WAPetGC_Users.nom', $direction);
                break;
            case 'service':
                $appointmentsQuery
                    ->join('WAPetGC_Services', 'WAPetGC_Appointments.service_id', '=', 'WAPetGC_Services.id')
                    ->orderBy('WAPetGC_Services.name', $direction);
                break;
            case 'client':
                $appointmentsQuery
                    ->orderBy('client_lname', $direction)
                    ->orderBy('client_fname', $direction);
                break;
            case 'start_at':
            default:
                $appointmentsQuery->orderBy('start_at', $direction);
                break;
        }

        // 5) Pagination
        // On utilise ->appends($request->all()) pour conserver tous les filtres + tri
        $appointments = $appointmentsQuery->paginate(10)->appends($request->all());

        // 6) Transformer pour renvoyer les dates formatées
        $appointments->getCollection()->transform(function ($appoint) {
            $appoint->start_at_formatted = [
                'date' => \Carbon\Carbon::parse($appoint->start_at)->format('d-m-Y'),
                'time' => \Carbon\Carbon::parse($appoint->start_at)->format('H:i'),
            ];
            $appoint->end_at_formatted = [
                'time' => \Carbon\Carbon::parse($appoint->end_at)->format('H:i'),
            ];
            return $appoint;
        });

        return response()->json([
            'appointments' => $appointments->items(),
            'pagination'   => $appointments->links('pagination::bootstrap-4')->render(),
        ]);
    }

    public function edit($id)
    {
        try {
            $appointment = WAPetGCAppointment::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $appointment,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des informations du rendez-vous.', [
                'appointment_id' => $id,
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Impossible de récupérer les détails du rendez-vous.',
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $appointment = WAPetGCAppointment::findOrFail($id);

        $validated = $request->validate([
            'service_id' => 'required|exists:WAPetGC_Services,id',
            'client_fname' => 'required|string|max:255',
            'client_lname' => 'required|string|max:255',
            'client_adresse' => 'required|string|max:255',
            'client_zip_code' => 'required|string|max:10',
            'client_city' => 'required|string|max:255',
            'start_at' => 'required|date',
            'duration' => 'required|integer|min:1',
            'end_at' => 'required|date|after:start_at',
            'comment' => 'nullable|string',
        ]);

        $appointment->update($validated);

        return redirect()->route('manage-appointments.index')->with('success', 'Rendez-vous mis à jour.');
    }

    public function destroy($id)
    {
        try {
            $appointment = WAPetGCAppointment::findOrFail($id);
            $appointment->delete();

            return redirect()
                ->route('manage-appointments.index')
                ->with('success', 'Rendez-vous supprimé.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du rendez-vous.', [
                'appointment_id' => $id,
                'error_message' => $e->getMessage(),
            ]);

            return redirect()
                ->route('manage-appointments.index')
                ->with('error', 'Une erreur s\'est produite lors de la suppression du rendez-vous.');
        }
    }

    public function restore($id)
    {
        try {
            $appointment = WAPetGCAppointment::withTrashed()->findOrFail($id);
            $appointment->restore();

            Log::info('Rendez-vous restauré avec succès.', ['appointment_id' => $id]);

            return redirect()->route('manage-appointments.index')->with('success', 'Rendez-vous restauré.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la restauration du rendez-vous.', [
                'appointment_id' => $id,
                'error_message' => $e->getMessage(),
            ]);

            return redirect()->route('manage-appointments.index')->with('error', 'Une erreur s\'est produite lors de la restauration du rendez-vous.');
        }
    }

    public function hardDelete($id)
    {
        try {
            $appointment = WAPetGCAppointment::withTrashed()->findOrFail($id);
            $appointment->forceDelete();

            Log::info('Rendez-vous supprimé définitivement.', ['appointment_id' => $id]);

            return redirect()->route('manage-appointments.index')->with('success', 'Rendez-vous supprimé définitivement.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression définitive du rendez-vous.', [
                'appointment_id' => $id,
                'error_message' => $e->getMessage(),
            ]);

            return redirect()->route('manage-appointments.index')->with('error', 'Une erreur s\'est produite lors de la suppression définitive.');
        }
    }

    public function reassignTech(Request $request, $id)
    {
        Log::info('Début de la réattribution du technicien.', [
            'appointment_id' => $id,
            'request_data' => $request->all(),
        ]);

        $request->validate([
            'tech_id' => 'required|exists:WAPetGC_Tech,id',
        ]);

        try {
            $appointment = WAPetGCAppointment::findOrFail($id);
            $oldTech = $appointment->tech;

            Log::info('Rendez-vous trouvé.', [
                'appointment_id' => $appointment->id,
                'current_tech_id' => $oldTech ? $oldTech->id : null,
                'current_tech_name' => $oldTech ? $oldTech->user->nom . ' ' . $oldTech->user->prenom : 'Non attribué',
            ]);

            $appointment->update([
                'tech_id' => $request->input('tech_id'),
            ]);

            $newTech = WAPetGCTech::find($request->input('tech_id'));
            Log::info('Réattribution réussie.', [
                'appointment_id' => $appointment->id,
                'old_tech_id' => $oldTech ? $oldTech->id : null,
                'old_tech_name' => $oldTech ? $oldTech->user->nom . ' ' . $oldTech->user->prenom : 'Non attribué',
                'new_tech_id' => $newTech->id,
                'new_tech_name' => $newTech->user->nom . ' ' . $newTech->user->prenom,
            ]);

            return redirect()
                ->route('manage-appointments.index')
                ->with('success', 'Le technicien a été réattribué avec succès.');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la réattribution du technicien.', [
                'appointment_id' => $id,
                'error_message' => $e->getMessage(),
            ]);

            return redirect()
                ->route('manage-appointments.index')
                ->with('error', 'Une erreur est survenue lors de la réattribution.');
        }
    }

    public function viewClient($id)
    {
        try {
            $appointment = WAPetGCAppointment::findOrFail($id);

            $clientDetails = [
                'fname' => $appointment->client_fname,
                'lname' => $appointment->client_lname,
                'adresse' => $appointment->client_adresse,
                'zip_code' => $appointment->client_zip_code,
                'city' => $appointment->client_city,
                'phone' => $appointment->client_phone,
            ];

            return response()->json([
                'success' => true,
                'data' => $clientDetails,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des informations client.', [
                'appointment_id' => $id,
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des informations client.',
            ], 500);
        }
    }
}
