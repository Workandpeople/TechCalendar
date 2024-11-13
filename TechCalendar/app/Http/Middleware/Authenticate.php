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
        if (!Auth::check()) {
            Log::warning("Accès refusé : l'utilisateur n'est pas authentifié.");
            return redirect()->route('login')->withErrors('Vous devez être connecté pour accéder à cette page.');
        }

        Log::info("Accès autorisé pour l'utilisateur authentifié : " . Auth::user()->email);
        return $next($request);
    }
}