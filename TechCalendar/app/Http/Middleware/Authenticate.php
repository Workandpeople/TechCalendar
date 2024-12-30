<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Authenticate
{
    /**
     * Gérer une requête entrante.
     */
    public function handle($request, Closure $next)
    {
        // Vérification si l'utilisateur est authentifié
        if (!Auth::check()) {
            Log::warning("Accès refusé : l'utilisateur n'est pas authentifié. Requête provenant de : " . $request->ip());
            return redirect()->route('login')->withErrors('Vous devez être connecté pour accéder à cette page.');
        }

        $user = Auth::user();
        Log::info("Accès autorisé pour l'utilisateur : " . $user->email . " (Role: " . $user->role . ")");
        return $next($request);
    }
}