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
        Log::info("Affichage de la page de connexion.");
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
            $role = Role::where('user_id', $user->id)->first()->role;

            Log::info("Utilisateur connecté : {$user->email}, Rôle : $role");

            // Rediriger selon le rôle de l'utilisateur
            switch ($role) {
                case 'administrateur':
                    Log::info("Redirection vers le panneau administrateur.");
                    return redirect()->route('panel.admin');
                case 'assistante':
                    Log::info("Redirection vers le panneau assistant.");
                    return redirect()->route('panel.assistant');
                case 'technicien':
                    Log::info("Redirection vers le panneau technicien.");
                    return redirect()->route('panel.technician');
                default:
                    Log::error("Utilisateur {$user->email} avec un rôle inconnu : $role.");
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