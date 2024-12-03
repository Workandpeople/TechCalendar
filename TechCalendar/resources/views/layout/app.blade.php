<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>@yield('title', 'TechCalendar')</title>

        <!-- Fichiers CSS globaux -->
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">

        <!-- Section pour des fichiers CSS spécifiques -->
        @yield('head')
    </head>

    <body id="page-top">
        
        <!-- Page Wrapper -->
        <div id="wrapper">
            @include('partials.sidebar')
            
            <!-- Contenu principal -->
            @yield('content')

            <!-- Footer -->
            @include('partials.footer')

            <!-- Écran de chargement -->
            @include('partials.loading_screen')
        </div>

        <!-- Fichiers JS globaux -->
        <script src="{{ asset('js/app.js') }}"></script>

        <!-- Section pour des scripts JS spécifiques -->
        @yield('scripts')
    </body>
</html>