<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen antialiased">
        <main class="mx-auto max-w-5xl px-6 py-10">
            <div class="gc-card p-8">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm" style="color: var(--gc-text-soft);">Connecté en tant que</p>
                        <h1 class="text-2xl font-semibold" style="color: var(--gc-text);">{{ auth()->user()->name }}</h1>
                    </div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="gc-btn-primary">Déconnexion</button>
                    </form>
                </div>
            </div>
        </main>
    </body>
</html>
