<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WAPetGCService;

class ManageProviderController extends Controller
{
    public function index(Request $request)
    {
        Log::info('Affichage de la liste des services.');

        $sort = $request->get('sort', 'name'); // Colonne par défaut
        $direction = $request->get('direction', 'asc'); // Direction par défaut

        // Vérifier si la colonne à trier est valide
        $validSortColumns = ['type', 'name', 'default_time'];
        if (!in_array($sort, $validSortColumns)) {
            $sort = 'name';
        }

        $services = WAPetGCService::withTrashed()
            ->orderBy($sort, $direction)
            ->paginate(10); // Inclure soft-deleted avec pagination

        Log::info('Nombre total de services récupérés : ' . $services->total());
        return view('manageProviders', compact('services'));
    }

    public function store(Request $request)
    {
        Log::info('Tentative de création d\'un nouveau service.', $request->all());

        $validated = $request->validate([
            'type' => 'required|string|max:255',
            'name' => 'required|string|max:255|unique:WAPetGC_Services,name',
            'default_time' => 'required|integer|min:1',
        ]);

        $service = WAPetGCService::create($validated);
        Log::info('Service créé avec succès.', ['id' => $service->id]);

        return redirect()->route('manage-providers.index')->with('success', 'Service créé avec succès.');
    }

    public function edit($id)
    {
        Log::info('Récupération des données pour modification du service, ID : ' . $id);
        $service = WAPetGCService::withTrashed()->findOrFail($id);
        return response()->json($service);
    }

    public function update(Request $request, $id)
    {
        Log::info('Mise à jour des données du service, ID : ' . $id);

        $service = WAPetGCService::findOrFail($id);

        $validated = $request->validate([
            'type' => 'required|string|max:255',
            'name' => 'required|string|max:255|unique:WAPetGC_Services,name,' . $id,
            'default_time' => 'required|integer|min:1',
        ]);

        $service->update($validated);
        Log::info('Service mis à jour avec succès.', ['id' => $id]);

        return redirect()->route('manage-providers.index')->with('success', 'Service mis à jour avec succès.');
    }

    public function destroy($id)
    {
        Log::info('Tentative de suppression du service, ID : ' . $id);

        $service = WAPetGCService::findOrFail($id);
        $service->delete();

        Log::info('Service supprimé avec succès.', ['id' => $id]);
        return redirect()->route('manage-providers.index')->with('success', 'Service supprimé.');
    }

    public function restore($id)
    {
        Log::info('Tentative de restauration du service, ID : ' . $id);

        $service = WAPetGCService::withTrashed()->findOrFail($id);
        $service->restore();

        Log::info('Service restauré avec succès.', ['id' => $id]);
        return redirect()->route('manage-providers.index')->with('success', 'Service restauré.');
    }

    public function hardDelete($id)
    {
        Log::info('Tentative de suppression définitive du service, ID : ' . $id);

        $service = WAPetGCService::withTrashed()->findOrFail($id);
        $service->forceDelete();

        Log::info('Service supprimé définitivement.', ['id' => $id]);
        return redirect()->route('manage-providers.index')->with('success', 'Service supprimé définitivement.');
    }

    public function search(Request $request)
    {
        $query = $request->get('query', '');
        $services = WAPetGCService::withTrashed()
            ->where('name', 'LIKE', '%' . $query . '%')
            ->orWhere('type', 'LIKE', '%' . $query . '%')
            ->paginate(10);

        return response()->json($services);
    }
}
