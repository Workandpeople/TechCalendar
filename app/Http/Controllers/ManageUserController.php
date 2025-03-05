<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WAPetGCUser;

class ManageUserController extends Controller
{
    public function index(Request $request)
    {
        Log::info('Affichage de la liste des utilisateurs avec pagination et tri.');

        $query = WAPetGCUser::withTrashed()->with('tech');

        // Gestion du tri
        $sortBy = $request->get('sort', 'nom');
        $direction = $request->get('direction', 'asc');

        if ($sortBy === 'nom') {
            $query->orderBy('nom', $direction)->orderBy('prenom', $direction);
        } else {
            $query->orderBy($sortBy, $direction);
        }

        // Pagination à 10 utilisateurs par page
        $users = $query->paginate(10);

        Log::info('Nombre total d\'utilisateurs récupérés : ' . $users->total());
        return view('manageUsers', compact('users'));
    }

    public function search(Request $request)
    {
        $query = $request->get('query', '');
        $department = $request->get('department', '');

        $users = WAPetGCUser::withTrashed()
            ->with('tech')
            ->where(function ($q) use ($query) {
                $q->where('nom', 'LIKE', '%' . $query . '%')
                ->orWhere('prenom', 'LIKE', '%' . $query . '%');
            });

        // Filtrer par département si renseigné
        if (!empty($department)) {
            $users->whereHas('tech', function ($q) use ($department) {
                $q->where('zip_code', 'LIKE', $department . '%');
            });
        }

        $users = $users->paginate(10);

        // Ajout du département dans la réponse JSON
        $users->getCollection()->transform(function ($user) {
            $user->department = $user->tech ? substr($user->tech->zip_code, 0, 2) : null;
            return $user;
        });

        return response()->json($users);
    }

    public function store(Request $request)
    {
        Log::info('Tentative de création d\'un nouvel utilisateur.', $request->all());

        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'required|email|unique:WAPetGC_Users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|string|in:admin,assistante,tech',
            'phone' => 'nullable|string|max:20',
            'adresse' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:255',
            'default_start_at' => 'nullable|date_format:H:i',
            'default_end_at' => 'nullable|date_format:H:i',
            'default_rest_time' => 'nullable|integer|min:0',
        ]);

        Log::info('Validation réussie pour la création d\'utilisateur.');

        $user = WAPetGCUser::create([
            'nom' => $request->nom,
            'prenom' => $request->prenom,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => $request->role,
        ]);

        Log::info('Utilisateur créé avec succès.', ['id' => $user->id]);

        if ($request->role === 'tech') {
            $tech = $user->tech()->create([
                'phone' => $request->phone,
                'adresse' => $request->adresse,
                'zip_code' => $request->zip_code,
                'city' => $request->city,
                'default_start_at' => $request->default_start_at ?: '08:30',
                'default_end_at' => $request->default_end_at ?: '17:30',
                'default_rest_time' => $request->default_rest_time ?: 60,
            ]);

            Log::info('Technicien associé créé avec succès.', ['tech_id' => $tech->id]);
        }

        return redirect()->route('manage-users.index')->with('success', 'Utilisateur créé avec succès.');
    }

    public function updatePassword(Request $request, $id)
    {
        Log::info('Tentative de mise à jour du mot de passe pour l\'utilisateur ID : ' . $id);

        $user = WAPetGCUser::findOrFail($id);
        Log::info('Utilisateur trouvé pour la mise à jour du mot de passe.', ['email' => $user->email]);

        $validated = $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user->update([
            'password' => bcrypt($request->password),
        ]);

        Log::info('Mot de passe mis à jour avec succès pour l\'utilisateur.', ['id' => $id]);
        return redirect()->route('manage-users.index')->with('success', 'Mot de passe mis à jour avec succès.');
    }

    public function edit($id)
    {
        Log::info('Récupération des données utilisateur pour modification, ID : ' . $id);
        $user = WAPetGCUser::with('tech')->findOrFail($id); // Charger la relation tech
        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        Log::info('Mise à jour des données utilisateur, ID : ' . $id);

        $user = WAPetGCUser::findOrFail($id);

        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'required|email|unique:WAPetGC_Users,email,' . $id,
            'role' => 'required|string|in:admin,assistante,tech',
            'phone' => 'nullable|string|max:20',
            'adresse' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:255',
            'default_start_at' => 'nullable|date_format:H:i:s',
            'default_end_at'   => 'nullable|date_format:H:i:s',
            'default_rest_time' => 'nullable|integer|min:0',
        ]);

        $user->update($request->only('nom', 'prenom', 'email', 'role'));

        if ($request->role === 'tech') {
            $user->tech()->updateOrCreate([], $request->only(
                'phone',
                'adresse',
                'zip_code',
                'city',
                'default_start_at',
                'default_end_at',
                'default_rest_time'
            ));
            Log::info('Mise à jour des informations du technicien.', ['tech_id' => $user->tech->id]);
        }

        return redirect()->route('manage-users.index')->with('success', 'Utilisateur mis à jour avec succès.');
    }

    public function destroy($id)
    {
        Log::info('Tentative de suppression de l\'utilisateur, ID : ' . $id);

        $user = WAPetGCUser::findOrFail($id);

        if (!$user) {
            Log::error('Utilisateur non trouvé pour suppression.', ['id' => $id]);
            abort(404);
        }

        $user->delete();

        Log::info('Utilisateur supprimé avec succès.', ['id' => $id]);
        return redirect()->route('manage-users.index')->with('success', 'Utilisateur supprimé.');
    }

    public function restore($id)
    {
        Log::info('Tentative de restauration de l\'utilisateur, ID : ' . $id);

        $user = WAPetGCUser::withTrashed()->findOrFail($id);

        if (!$user->trashed()) {
            Log::warning('L\'utilisateur n\'est pas supprimé. Restauration annulée.', ['id' => $id]);
            return redirect()->route('manage-users.index')->withErrors('Cet utilisateur n\'est pas supprimé.');
        }

        $user->restore();

        Log::info('Utilisateur restauré avec succès.', ['id' => $id]);
        return redirect()->route('manage-users.index')->with('success', 'Utilisateur restauré.');
    }

    public function hardDelete($id)
    {
        Log::info('Tentative de suppression définitive de l\'utilisateur, ID : ' . $id);

        $user = WAPetGCUser::withTrashed()->findOrFail($id);

        if (!$user->trashed()) {
            Log::warning('L\'utilisateur n\'est pas supprimé. Suppression définitive annulée.', ['id' => $id]);
            return redirect()->route('manage-users.index')->withErrors('Cet utilisateur n\'est pas supprimé. Impossible de le supprimer définitivement.');
        }

        $user->forceDelete();

        Log::info('Utilisateur supprimé définitivement.', ['id' => $id]);
        return redirect()->route('manage-users.index')->with('success', 'Utilisateur supprimé définitivement.');
    }
}
