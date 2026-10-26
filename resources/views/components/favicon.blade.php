@php
    $logoUrl = asset('images/logo.png').'?v='.substr(hash_file('sha256', public_path('images/logo.png')), 0, 12);
@endphp
<link rel="icon" type="image/png" href="{{ $logoUrl }}">
<link rel="apple-touch-icon" href="{{ $logoUrl }}">
