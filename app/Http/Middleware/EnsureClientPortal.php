<?php

namespace App\Http\Middleware;

use App\Services\Crm\ClientAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureClientPortal
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user() && app(ClientAccess::class)->active($request->user())
            && ! $request->user()->must_change_password, 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
