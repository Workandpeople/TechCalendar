<?php

namespace App\Http\Middleware;

use App\Models\MobileAccessToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMobileToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

        if (! is_string($plainToken) || trim($plainToken) === '') {
            return $this->unauthorized();
        }

        $accessToken = MobileAccessToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->where(function ($query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        $user = $accessToken?->user;

        if (! $accessToken || ! $user || (int) $user->role !== 2 || (bool) $user->admin) {
            return $this->unauthorized();
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('mobile_access_token', $accessToken);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'message' => 'Session mobile invalide ou expirée.',
        ], 401);
    }
}
