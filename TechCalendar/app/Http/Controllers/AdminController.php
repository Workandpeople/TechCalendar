<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Prestation;

class AdminController extends Controller
{

    // Fonction pour afficher la page de statistiques des utilisateurs
    public function graphUser(Request $request)
    {
        $search = $request->get('search'); // Récupérer le terme de recherche
        $usersQuery = User::with([
            'role', // Inclure les rôles
            'horaires', // Inclure les horaires
            'horaires.rendezvous' => function ($query) {
                $query->with('technician'); // Inclure les techniciens des rendez-vous
            },
        ]);

        if ($search) {
            // Filtrer par nom ou prénom
            $usersQuery->where('nom', 'like', "%$search%")
                ->orWhere('prenom', 'like', "%$search%");
        }

        $users = $usersQuery->get();

        return view('admin.graph_user', compact('users', 'search'));
    }

    // Fonction pour afficher la page de gestion des utilisateurs
    public function manageUser(Request $request)
    {
        $search = $request->input('search');

        $users = User::when($search, function ($query, $search) {
            return $query->where('nom', 'like', "{$search}%")
                         ->orWhere('prenom', 'like', "{$search}%");
        })->paginate(8);

        return view('admin.manage_user', compact('users', 'search'));
    }

    // Fonction pour valider la modification d'un utilisateur
    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Mise à jour des champs de l'utilisateur
        $user->update($request->only([
            'nom', 'prenom', 'email', 'telephone', 'adresse', 'code_postal', 'ville',
            'default_start_at', 'default_end_at', 'default_traject_time', 'default_rest_time'
        ]));

        // Mise à jour du rôle si un rôle est envoyé dans la requête
        if ($request->has('role')) {
            $user->role()->updateOrCreate(
                ['user_id' => $user->id], // Clé de recherche pour créer ou mettre à jour
                ['role' => $request->input('role')]
            );
        }

        Log::info("Utilisateur modifié : {$user->id}");
        
        return response()->json(['success' => true, 'message' => 'Utilisateur modifié avec succès']);
    }

    // Fonction pour supprimer un utilisateur
    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        Log::info("Utilisateur supprimé : {$user->id}");
        
        return response()->json(['success' => true, 'message' => 'Utilisateur supprimé avec succès']);
    }

    public function managePresta(Request $request)
    {
        $search = $request->input('search');

        $prestations = Prestation::when($search, function ($query, $search) {
            return $query->where('name', 'like', "{$search}%")
                         ->orWhere('type', 'like', "{$search}%");
        })->paginate(8);

        return view('admin.manage_presta', compact('prestations', 'search'));
    }

    // Fonction pour mettre à jour une prestation
    public function updatePresta(Request $request, $id)
    {
        $request->validate([
            'type' => 'required|in:MAR,AUDIT,COFRAC',
            'name' => 'required|string|max:255',
            'default_time' => 'required|integer|min:1',
        ]);

        $prestation = Prestation::findOrFail($id);
        $prestation->update($request->only(['type', 'name', 'default_time']));
        Log::info("Prestation modifiée : {$prestation->id}");
        
        return response()->json(['success' => true, 'message' => 'Prestation modifiée avec succès']);
    }

    // Fonction pour supprimer une prestation
    public function deletePresta($id)
    {
        $prestation = Prestation::findOrFail($id);
        $prestation->delete();
        Log::info("Prestation supprimée : {$prestation->id}");
        
        return response()->json(['success' => true, 'message' => 'Prestation supprimée avec succès']);
    }

    public function createUser(Request $request)
    {
        // Log the role value for debugging

        $request->validate([
            'prenom' => 'required|string|max:255',
            'nom' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|confirmed',
            'role' => 'required|string',
        ]);

        // Validation supplémentaire pour le rôle technicien
        if ($request->input('role') === 'technicien') {
            $request->validate([
                'telephone' => 'nullable|string|max:20',
                'adresse' => 'nullable|string|max:255',
                'code_postal' => 'nullable|string|max:10',
                'ville' => 'nullable|string|max:255',
                'default_start_at' => 'nullable|date_format:H:i',
                'default_end_at' => 'nullable|date_format:H:i',
                'default_traject_time' => 'nullable|integer',
                'default_rest_time' => 'nullable|integer',
            ]);
        }

        // Créer l'utilisateur avec les champs requis
        $user = User::create([
            'prenom' => $request->input('prenom'),
            'nom' => $request->input('nom'),
            'email' => $request->input('email'),
            'password' => bcrypt($request->input('password')),
            'telephone' => $request->input('role') === 'technicien' ? $request->input('telephone') : null,
            'adresse' => $request->input('role') === 'technicien' ? $request->input('adresse') : null,
            'code_postal' => $request->input('role') === 'technicien' ? $request->input('code_postal') : null,
            'ville' => $request->input('role') === 'technicien' ? $request->input('ville') : null,
            'default_start_at' => $request->input('role') === 'technicien' ? $request->input('default_start_at') : null,
            'default_end_at' => $request->input('role') === 'technicien' ? $request->input('default_end_at') : null,
            'default_traject_time' => $request->input('role') === 'technicien' ? $request->input('default_traject_time') : null,
            'default_rest_time' => $request->input('role') === 'technicien' ? $request->input('default_rest_time') : null,
        ]);

        // Créer une entrée dans la table `role` pour cet utilisateur
        $user->role()->create([
            'role' => $request->input('role')
        ]);

        Log::info("Utilisateur créé : {$user->id} avec rôle : {$request->input('role')}");

        return response()->json(['success' => true, 'message' => 'Utilisateur créé avec succès']);
    }

    public function createPresta(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'name' => 'required|string|max:255',
            'default_time' => 'required|integer',
        ]);

        $prestation = Prestation::create($request->only(['type', 'name', 'default_time']));
        Log::info("Prestation créée : {$prestation->id}");

        return response()->json(['success' => true, 'message' => 'Prestation créée avec succès']);
    }
}