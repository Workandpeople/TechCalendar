<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Role;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        return view('login');
    }

    public function login(Request $request)
    {
        // Validation des champs
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:6'
        ]);

        $credentials = $request->only('email', 'password');
        Log::info("Tentative de connexion avec l'email : {$request->email}");

        if (Auth::attempt($credentials)) {
            Log::info("Connexion réussie pour l'utilisateur : {$request->email}");
            
            $request->session()->regenerate();
            $user = Auth::user();
            $role = optional($user->role)->role;

            if (!$role) {
                Log::error("Utilisateur {$user->email} sans rôle attribué.");
                Auth::logout();
                return redirect()->route('login')->withErrors('Rôle non attribué.');
            }

            Log::info("Utilisateur connecté : {$user->email}, Rôle : $role");

            // Redirection selon le rôle
            switch ($role) {
                case 'administrateur':
                    return redirect()->route('assistant.dashboard');
                case 'assistante':
                    return redirect()->route('assistant.dashboard');
                case 'technicien':
                    return redirect()->route('tech.dashboard');
                default:
                    Log::error("Rôle inconnu pour l'utilisateur {$user->email} : $role.");
                    Auth::logout();
                    return redirect()->route('login')->withErrors('Rôle inconnu.');
            }
        }

        Log::warning("Échec de connexion pour l'email : {$request->email}");
        return redirect()->back()->withErrors('Identifiants incorrects.');
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        Log::info("Déconnexion de l'utilisateur : {$user->email}");
        
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}