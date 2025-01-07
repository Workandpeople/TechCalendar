<?php

namespace App\Http\Controllers;

use App\Models\WAPetGCService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ManageServicesController extends Controller
{
    public function manageService(Request $request)
    {
        try {
            $search = $request->input('search');
            $services = WAPetGCService::query()
                ->when($search, function ($query, $search) {
                    return $query->where('name', 'LIKE', "%$search%")
                                 ->orWhere('type', 'LIKE', "%$search%");
                })
                ->paginate(10);
    
            Log::info('Services retrieved successfully', ['search' => $search]);
    
            if ($request->ajax()) {
                return view('partials.service_table', compact('services'))->render();
            }
    
            return view('assistant.manage_service', compact('services', 'search'));
        } catch (\Exception $e) {
            Log::error('Error retrieving services', ['error' => $e->getMessage()]);
            return redirect()->back()->withErrors('Erreur lors de la récupération des prestations.');
        }
    }
    
    public function createService(Request $request)
    {
        try {
            $validated = $request->validate([
                'type' => 'required|string|max:255',
                'name' => 'required|string|max:255|unique:WAPetGC_Services,name',
                'default_time' => 'required|integer|min:0',
            ]);

            $service = WAPetGCService::create($validated);

            Log::info('Service created successfully', ['service_id' => $service->id]);

            return response()->json(['success' => 'Prestation créée avec succès.'], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error during service creation', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error creating service', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erreur lors de la création de la prestation.'], 500);
        }
    }

    public function updateService(Request $request, $id)
    {
        try {
            $service = WAPetGCService::findOrFail($id);

            $validated = $request->validate([
                'type' => 'required|string|in:MAR,AUDIT,COFRAC',
                'name' => 'required|string|max:255|unique:WAPetGC_Services,name,' . $service->id,
                'default_time' => 'required|integer|min:0',
            ]);

            $service->update($validated);

            Log::info('Service updated successfully', ['service_id' => $service->id]);

            return response()->json(['success' => 'Prestation mise à jour avec succès.']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error updating service', ['service_id' => $id, 'errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error updating service', ['service_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Erreur lors de la mise à jour de la prestation.'], 500);
        }
    }

    public function deleteService($id)
    {
        try {
            $service = WAPetGCService::findOrFail($id);
            $service->delete();

            Log::info('Service deleted successfully', ['service_id' => $id]);

            return response()->json(['success' => 'Prestation supprimée avec succès.']);
        } catch (\Exception $e) {
            Log::error('Error deleting service', ['service_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Erreur lors de la suppression de la prestation.'], 500);
        }
    }
}