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
        <div class="mx-auto flex min-h-screen w-full max-w-md items-center px-6 py-12">
            <div class="gc-card w-full p-8">
                <div class="mb-6 flex flex-col items-center">
                    <img src="{{ asset('images/logo.png') }}" alt="Genius Controle" class="mb-4 h-28 w-auto" />
                    <h1 class="text-xl font-semibold tracking-[0.08em]" style="color: var(--gc-text);">{{ config('app.name') }}</h1>
                </div>

                @if (session('status'))
                    <div class="gc-alert">
                        {{ session('status') }}
                    </div>
                @endif

                {{ $slot }}
            </div>
        </div>
    </body>
</html>
