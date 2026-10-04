@php
    $theme = request()->cookie('theme');
    if (! in_array($theme, ['light', 'dark'])) {
        $theme = 'dark';
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full {{ $theme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ \App\Support\Branding::pageTitle($title ?? null) }}</title>
        @if ($brandLogoUrl = \App\Support\Branding::logoUrl())
            <link rel="icon" href="{{ $brandLogoUrl }}">
        @endif

        <!-- Fonts: Inter, see DESIGN.md "Tipografi" -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        <script src="{{ asset('js/theme-init.js') }}?v={{ filemtime(public_path('js/theme-init.js')) }}"></script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans antialiased bg-slate-950 text-slate-100 overflow-hidden">
        {{ $slot }}

        <x-toast-container position="top-center" />
    </body>
</html>
