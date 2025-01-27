<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use App\Models\WAPetGCUser;

class AuthController extends Controller
{
    // Affiche le formulaire de connexion
    public function login()
    {
        return view('login');
    }

    // Gère la tentative de connexion
    public function loginSubmit(Request $request)
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
                return redirect()->route('tech-dashboard.index', [], 303);

            case 'assistante':
                Log::info("Redirection vers la gestion des rendez-vous pour : " . $user->email);
                return redirect()->route('appointment.index', [], 303);

            case 'admin':
                Log::info("Redirection vers les graphiques utilisateurs pour : " . $user->email);
                return redirect()->route('home.index', [], 303);

            default:
                Log::error("Rôle inconnu pour l'utilisateur : " . $user->email);
                return redirect()->route('login')->withErrors(['message' => 'Unknown role']);
        }
    }

    // Gère la déconnexion
    public function logout()
    {
        Auth::logout();
        return redirect()->route('login')->with('success', 'Vous êtes déconnecté.');
    }

    // Affiche la page de mot de passe oublié
    public function forgotPassword()
    {
        return view('auth.forgot-password');
    }
}
