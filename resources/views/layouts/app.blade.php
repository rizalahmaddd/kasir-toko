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

        <!-- Theme Initialization Script: run before DOM render to prevent FOUC / flicker -->
        <script>
            (function() {
                try {
                    var theme = localStorage.getItem('theme');
                    if (theme === 'light') {
                        document.documentElement.classList.add('light');
                        document.documentElement.classList.remove('dark');
                        document.cookie = 'theme=light; path=/; max-age=31536000; SameSite=Lax';
                    } else if (theme === 'dark') {
                        document.documentElement.classList.add('dark');
                        document.documentElement.classList.remove('light');
                        document.cookie = 'theme=dark; path=/; max-age=31536000; SameSite=Lax';
                    }
                } catch (e) {}
            })();

            // The sidebar is re-rendered on every wire:navigate; setting its width class here (not in Alpine init)
            // keeps it from animating from the default width and shifting the page on each menu change.
            (function() {
                function applySidebarState() {
                    var collapsed = window.innerWidth < 1280;
                    try {
                        var pref = localStorage.getItem('sidebar-collapsed');
                        if (pref !== null) collapsed = pref === 'true';
                    } catch (e) {}
                    document.documentElement.classList.toggle('sidebar-collapsed', collapsed);
                }

                applySidebarState();

                // wire:navigate copies the server-rendered <html> attributes, which drops this class.
                if (!window.__sidebarStateHooked) {
                    window.__sidebarStateHooked = true;
                    document.addEventListener('livewire:navigating', function(e) {
                        e.detail.onSwap(applySidebarState);
                    });
                }
            })();
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans antialiased bg-slate-950 text-slate-100 md:overflow-hidden">
        <div class="h-full md:flex">
            <livewire:layout.navigation />

            <div class="flex-1 flex flex-col min-h-0 min-w-0">
                <!-- Page Heading: satu-satunya bar atas di semua ukuran layar. Di mobile tombol menu ada di
                     sini (membuka drawer sidebar lewat event open-mobile-nav) supaya tidak ada dua bar bertumpuk.
                     $header: rich slot used by plain Blade pages via the app-layout component. $heading: plain
                     string used by full-page Livewire module components via the Layout attribute's params array.
                     Kept separate from Livewire's own Title attribute, which sets the title tag above. -->
                <header x-data class="sticky top-0 z-30 shrink-0 border-b border-slate-800/80 bg-slate-900/80 backdrop-blur-md pt-[env(safe-area-inset-top)]">
                    <div class="flex items-center justify-between gap-2 sm:gap-4 h-14 ps-1.5 pe-1.5 sm:px-4 md:px-6">
                        {{-- Kiri: Tombol Menu Mobile & Judul Halaman --}}
                        <div class="flex items-center gap-1.5 sm:gap-3 min-w-0 flex-1 sm:basis-0">
                            <button type="button" @click="$dispatch('open-mobile-nav')" aria-label="{{ __('Buka menu') }}"
                                class="md:hidden inline-flex items-center justify-center w-10 h-10 rounded-lg text-slate-300 hover:text-slate-100 hover:bg-slate-800/60 shrink-0">
                                <i data-lucide="menu" class="w-5 h-5"></i>
                            </button>

                            <div class="min-w-0">
                                @if (isset($header))
                                    {{ $header }}
                                @else
                                    <h2 class="font-bold text-base text-slate-100 leading-tight truncate">{{ $heading ?? \App\Support\Branding::appName() }}</h2>
                                @endif
                            </div>
                        </div>

                        {{-- Tengah: Global Search (Terpusat di desktop/tablet, merapat ke kanan di mobile) --}}
                        <div class="flex items-center justify-end sm:justify-center shrink-0 sm:flex-1 sm:max-w-md sm:mx-auto">
                            <livewire:layout.global-search />
                        </div>

                        {{-- Kanan: Theme Toggle & Notifikasi --}}
                        <div class="flex items-center justify-end gap-1 shrink-0 sm:flex-1 sm:basis-0">
                            <x-theme-toggle />
                            <livewire:layout.notification-bell />
                        </div>
                    </div>
                </header>

                <!-- Page Content: only this area scrolls on desktop, so the header keeps its width. The gutter is
                     reserved even on short pages, otherwise the content shifts when a scrollbar comes and goes. -->
                <div class="flex-1 min-h-0 md:overflow-y-auto md:[scrollbar-gutter:stable] custom-scrollbar">
                    <!-- Lebar konten mengikuti layar: full width di mobile & tablet, lalu ~80% lebar layar di
                         desktop lewat clamp min(80vw,100%) supaya tidak melebar berlebihan di monitor lebar.
                         Padding bawah mobile menyisakan ruang untuk bottom nav + safe area iOS. -->
                    <main class="flex-1 w-full mx-auto px-4 pt-4 pb-[calc(5rem+env(safe-area-inset-bottom))] sm:px-5 sm:pt-5 md:p-6 lg:p-8 xl:w-[min(80vw,100%)]">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>

        <x-bottom-nav />
        <x-toast-container />

        @foreach (['list', 'detail', 'dashboard', 'report', 'form'] as $skeletonType)
            <template data-skeleton-preset="{{ $skeletonType }}">
                <x-page-skeleton :type="$skeletonType" />
            </template>
        @endforeach
    </body>
</html>
