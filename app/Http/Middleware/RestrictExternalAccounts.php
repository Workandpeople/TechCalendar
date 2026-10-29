<?php

namespace App\Http\Middleware;

use App\Services\Crm\ClientAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RestrictExternalAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->isExternal()) {
            return $next($request);
        }
        if (! app(ClientAccess::class)->active($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Cet accès client est désactivé.');
        }
        // Let guest middleware redirect existing sessions instead of rejecting stale authentication forms.
        $accountRoute = $request->routeIs('dashboard', 'profile', 'logout', 'account.first-password.update',
            'login', 'login.store', 'password.request', 'password.email', 'password.reset', 'password.store', 'support.feedback');
        abort_unless($accountRoute || $request->routeIs('client.*', 'profile.*'), 403);
        if ($user->must_change_password && ! $accountRoute) {
            return redirect()->route('profile');
        }

        return $next($request);
    }
}
