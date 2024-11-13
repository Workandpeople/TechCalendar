<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class AdminController extends Controller
{
    public function manageUser(Request $request)
    {
        $search = $request->input('search');

        $users = User::when($search, function ($query, $search) {
            return $query->where('nom', 'like', "{$search}%")
                         ->orWhere('prenom', 'like', "{$search}%");
        })->paginate(8);

        Log::info("Accès à la gestion des utilisateurs par un administrateur.");
        return view('admin.manage_user', compact('users', 'search'));
    }

    public function managePresta()
    {
        Log::info("Accès à la gestion des prestations par un administrateur.");
        return view('admin.manage_presta');
    }
}