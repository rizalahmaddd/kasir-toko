@props(['title', 'logo' => null, 'captcha' => false])

@php
    $theme = request()->cookie('theme');
    if (! in_array($theme, ['light', 'dark'])) {
        $theme = 'light';
    }
@endphp
<!DOCTYPE html>
<html lang="id" class="{{ $theme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="robots" content="noindex, nofollow">
        <meta name="theme-color" content="#ffffff">
        <title>{{ $title }}</title>
        @if ($logo)
            <link rel="icon" href="{{ $logo }}">
        @endif
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/self-order.js'])
    </head>
    <body class="font-sans antialiased bg-slate-950 text-slate-100 min-h-dvh selection:bg-emerald-600 selection:text-white">
        {{ $slot }}

        @livewireScripts
        @if ($captcha)
            <script src="{{ \App\Support\Turnstile::SCRIPT_URL }}" async defer></script>
        @endif
    </body>
</html>
