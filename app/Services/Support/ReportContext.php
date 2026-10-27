<?php

namespace App\Services\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ReportContext
{
    public function page(string $path): array
    {
        // Resolve a route pattern, never retain query strings or reset tokens in URLs.
        try {
            $route = app('router')->getRoutes()->match(Request::create(parse_url($path, PHP_URL_PATH) ?: '/', 'GET'));

            return ['route' => $route->getName(), 'page' => '/'.$route->uri(), 'identifiers' => $this->identifiers($route)];
        } catch (Throwable) {
            return ['route' => null, 'page' => 'Route inconnue'];
        }
    }

    public function error(Request $request, Throwable $exception, int $status): array
    {
        $origin = $exception instanceof HttpExceptionInterface && $exception->getPrevious() ? $exception->getPrevious() : $exception;
        $frames = [['file' => $origin->getFile(), 'line' => $origin->getLine()], ...array_slice($origin->getTrace(), 0, 10)];
        $trace = [];
        foreach ($frames as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if (str_starts_with($file, base_path().DIRECTORY_SEPARATOR)) {
                $trace[] = ['file' => substr($file, strlen(base_path()) + 1), 'line' => (int) ($frame['line'] ?? 0)];
            }
        }
        $actorId = null;
        try {
            // Never query a failing database while rendering an error page.
            if (Auth::guard('web')->hasUser()) {
                $actorId = Auth::guard('web')->user()->getAuthIdentifier();
            }
        } catch (Throwable) {
        }

        return [
            'id' => (string) Str::uuid(), 'kind' => 'error', 'occurred_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(30)->timestamp, 'status' => $status,
            'method' => $request->method(), 'actor_id' => $actorId,
            'route' => $request->route()?->getName(), 'page' => $request->route() ? '/'.$request->route()->uri() : 'Route inconnue',
            'identifiers' => $request->route() ? $this->identifiers($request->route()) : [],
            'exception_class' => Str::before($origin::class, "\0"), 'trace' => $trace,
            'environment' => app()->environment(), 'php' => PHP_VERSION,
        ];
    }

    public function seal(array $context): string
    {
        return Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function identifiers(Route $route): array
    {
        $identifiers = [];
        foreach (['dossier', 'operation', 'appointment', 'document', 'lot', 'user'] as $name) {
            $value = $route->parameter($name);
            $value = $value instanceof Model ? $value->getKey() : $value;
            if ((is_string($value) || is_int($value)) && ctype_digit((string) $value)) {
                $identifiers[$name] = (string) $value;
            }
        }

        return $identifiers;
    }

    public function open(string $token): array
    {
        try {
            $context = json_decode(Crypt::decryptString($token), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(($context['kind'] ?? null) === 'error' && Str::isUuid($context['id'] ?? '')
                && ($context['expires_at'] ?? 0) >= now()->timestamp, 403);

        } catch (Throwable) {
            abort(403, 'Ce diagnostic a expiré. Rechargez la page qui a rencontré une erreur.');
        }
        abort_if((int) ($context['status'] ?? 0) === 404, 403, 'Les pages introuvables ne sont pas transmises au développeur.');

        return $context;
    }
}
