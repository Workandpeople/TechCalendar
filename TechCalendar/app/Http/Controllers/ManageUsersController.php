<?php

namespace App\Http\Controllers;

use App\Models\WAPetGCUser;
use App\Models\WAPetGCTech;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ManageUsersController extends Controller
{
    public function manageUser(Request $request)
    {
        try {
            $search = $request->input('search');
            $users = WAPetGCUser::with(['tech'])
                ->withTrashed() // Inclure les utilisateurs supprimés
                ->when($search, function ($query, $search) {
                    return $query->where('prenom', 'LIKE', "%$search%")
                                 ->orWhere('nom', 'LIKE', "%$search%");
                })
                ->paginate(10);
    
            if ($request->ajax()) {
                return view('partials.user_table', compact('users'))->render();
            }
    
            return view('assistant.manage_user', compact('users', 'search'));
        } catch (\Exception $e) {
            Log::error('Error retrieving users', ['error' => $e->getMessage()]);
            return redirect()->back()->withErrors('Erreur lors de la récupération des utilisateurs.');
        }
    }    
    
    public function createUser(Request $request)
    {
        Log::info('Requête reçue pour la création d\'un utilisateur', [
            'data' => $request->all(),
        ]);
    
        try {
            $validated = $request->validate([
                'prenom' => 'required|string|max:255',
                'nom' => 'required|string|max:255',
                'email' => 'required|email|unique:WAPetGC_Users,email',
                'role' => 'required|string|in:admin,assistante,tech',
                'password' => 'required|confirmed|min:8',
            ]);
    
            $user = WAPetGCUser::create([
                'prenom' => $validated['prenom'],
                'nom' => $validated['nom'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'password' => bcrypt($validated['password']),
            ]);
    
            Log::info('Utilisateur créé avec succès', ['user_id' => $user->id]);
    
            if ($user->role === 'tech') {
                $tech = WAPetGCTech::create([
                    'user_id' => $user->id,
                    'phone' => $request->input('phone'),
                    'adresse' => $request->input('adresse'),
                    'zip_code' => $request->input('zip_code'),
                    'city' => $request->input('city'),
                    'default_start_at' => $request->input('default_start_at'),
                    'default_end_at' => $request->input('default_end_at'),
                    'default_rest_time' => $request->input('default_rest_time'),
                ]);
    
                Log::info('Détails du technicien créés avec succès', ['tech_id' => $tech->id]);
            }
    
            return response()->json(['success' => 'Utilisateur créé avec succès.'], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erreur de validation lors de la création de l\'utilisateur', [
                'errors' => $e->errors(),
            ]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de l\'utilisateur', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erreur lors de la création de l\'utilisateur.'], 500);
        }
    }

    public function updateUser(Request $request, $id)
    {
        Log::info('Requête reçue pour la mise à jour d\'un utilisateur', [
            'user_id' => $id,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'data' => $request->all(),
        ]);
    
        try {
            $user = WAPetGCUser::findOrFail($id);
    
            $validated = $request->validate([
                'prenom' => 'required|string|max:255',
                'nom' => 'required|string|max:255',
                'email' => 'required|email|unique:WAPetGC_Users,email,' . $user->id,
                'role' => 'required|string|in:admin,assistante,tech',
            ]);
    
            $user->update($validated);
    
            if ($request->input('role') === 'tech') {
                WAPetGCTech::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'phone' => $request->input('phone'),
                        'adresse' => $request->input('adresse'),
                        'zip_code' => $request->input('zip_code'),
                        'city' => $request->input('city'),
                        'default_start_at' => $request->input('default_start_at'),
                        'default_end_at' => $request->input('default_end_at'),
                        'default_rest_time' => $request->input('default_rest_time'),
                    ]
                );
            }
    
            Log::info('Utilisateur mis à jour avec succès', ['user_id' => $user->id]);
    
            return response()->json(['success' => 'Utilisateur mis à jour avec succès.']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erreur de validation lors de la mise à jour de l\'utilisateur', [
                'user_id' => $id,
                'errors' => $e->errors(),
            ]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de l\'utilisateur', [
                'user_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erreur lors de la mise à jour.'], 500);
        }
    }

    public function deleteUser($id)
    {
        try {
            Log::info('Requête de suppression reçue pour l\'utilisateur', ['user_id' => $id]);

            $user = WAPetGCUser::findOrFail($id);
            $user->delete(); // Softban l'utilisateur

            Log::info('Utilisateur softbanné avec succès', ['user_id' => $id]);

            // Softban également dans la table WAPetGCTech si l'utilisateur est un technicien
            $tech = WAPetGCTech::where('user_id', $id)->first();

            if ($tech) {
                $tech->delete(); // Softban le technicien
                Log::info('Technicien softbanné avec succès', ['tech_id' => $tech->id]);
            }

            // Retourner une réponse JSON
            return response()->json(['success' => 'Utilisateur et technicien associés supprimés avec succès.']);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de l\'utilisateur ou du technicien', [
                'user_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erreur lors de la suppression.'], 500);
        }
    }

    public function restoreUser($id)
    {
        try {
            $user = WAPetGCUser::withTrashed()->findOrFail($id);
            $user->restore();

            Log::info('Utilisateur restauré avec succès', ['user_id' => $id]);

            $tech = WAPetGCTech::withTrashed()->where('user_id', $id)->first();

            if ($tech) {
                $tech->restore();
                Log::info('Technicien restauré avec succès', ['tech_id' => $tech->id]);
            }

            return response()->json(['success' => 'Utilisateur et technicien associés restaurés avec succès.']);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la restauration de l\'utilisateur ou du technicien', [
                'user_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Erreur lors de la restauration.'], 500);
        }
    }
}