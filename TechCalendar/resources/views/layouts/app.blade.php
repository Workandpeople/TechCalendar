<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'TechCalendar')</title>

    <!-- Core CSS Files -->
    <link href="{{ asset('css/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/fontAwesome/css/all.min.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ asset('css/sb-admin-2.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">

    <!-- Additional CSS pushed by views -->
    @yield('head-css')
</head>

<body id="page-top">
    <!-- Wrapper for the entire page -->
    <div id="wrapper">
        <!-- Sidebar (external partial) -->
        @include('partials.sidebar')

        <!-- Main Content -->
        @yield('content')

        <!-- Footer (external partial) -->
        @include('partials.footer')

        <!-- Loading Overlay -->
        <div id="loadingOverlay" class="loading-overlay d-none">
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin"></i>
            </div>
        </div>
    </div>

    <!-- Core JS Files -->
    <script src="{{ asset('js/jquery/jquery.min.js') }}"></script>
    <script src="{{ asset('js/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/jquery-easing/jquery.easing.min.js') }}"></script>
    <script src="{{ asset('js/chart.js/chart.js') }}"></script>
    <script src="{{ asset('js/sb-admin-2.min.js') }}"></script>
    <script src="{{ asset('js/app.js') }}"></script>

    <!-- Additional JS pushed by views -->
    @yield('head-js')
</body>

</html>