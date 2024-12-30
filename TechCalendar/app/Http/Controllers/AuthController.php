<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Models\WAPetGCUser;

class AuthController extends Controller
{
    public function loginView()
    {
        return view('login');
    }

    /**
     * Handle login request.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        Log::info("Tentative de connexion pour l'email : " . $request->email);

        $user = WAPetGCUser::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            Log::warning("Échec de connexion pour l'email : " . $request->email);
            return redirect()->route('login')->withErrors(['message' => 'Invalid email or password']);
        }

        // Authentification et session
        Auth::login($user);
        Log::info("Connexion réussie pour l'utilisateur : " . $user->email . " (Role: " . $user->role . ")");

        // Rediriger en fonction du rôle
        switch ($user->role) {
            case 'tech':
                Log::info("Redirection vers le tableau de bord technicien pour : " . $user->email);
                return redirect()->route('tech.dashboard', [], 303);

            case 'assistante':
                Log::info("Redirection vers la gestion des rendez-vous pour : " . $user->email);
                return redirect()->route('assistant.take_appointements', [], 303);

            case 'admin':
                Log::info("Redirection vers les graphiques utilisateurs pour : " . $user->email);
                return redirect()->route('admin.graph_user', [], 303);

            default:
                Log::error("Rôle inconnu pour l'utilisateur : " . $user->email);
                return redirect()->route('login')->withErrors(['message' => 'Unknown role']);
        }
    }

    /**
     * Handle logout request.
     */
    public function logout(Request $request)
    {
        Auth::logout();

        return response()->json(['message' => 'Logout successful'], 200);
    }
}