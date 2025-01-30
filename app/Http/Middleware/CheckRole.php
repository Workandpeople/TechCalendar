<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Redirections en fonction du rôle
     */
    protected $redirectRoutes = [
        'admin' => '/home',
        'assistante' => '/appointment',
        'tech' => '/tech-dashboard',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = Auth::user();

        if (!$user) {
            abort(403, 'Accès interdit.');
        }

        // Vérifie si l'utilisateur a le droit d'accéder à la page
        if (!in_array($user->role, $roles)) {
            return redirect($this->redirectRoutes[$user->role] ?? '/');
        }

        return $next($request);
    }
}
