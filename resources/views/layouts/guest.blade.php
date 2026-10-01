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

        <title>{{ \App\Support\Branding::pageTitle() }}</title>
        @if ($brandLogoUrl = \App\Support\Branding::logoUrl())
            <link rel="icon" href="{{ $brandLogoUrl }}">
        @endif

        <!-- Fonts: Inter, see DESIGN.md "Tipografi" -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        <!-- Theme Initialization Script -->
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
        </script>
        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full min-h-screen font-sans antialiased bg-slate-950 text-slate-100 selection:bg-emerald-500 selection:text-slate-950 overflow-x-hidden">
        <div class="min-h-screen w-full flex flex-col lg:flex-row relative">

            <!-- LEFT PANEL: Hero Photography & Operational Showcase (Full-height on desktop) -->
            <div class="relative hidden lg:flex lg:w-[50%] xl:w-[54%] flex-col justify-between p-10 xl:p-14 overflow-hidden bg-[#020617] text-white select-none no-dark-invert auth-hero-panel">
                <!-- Decorative background photo; replace the URL with your own brand imagery -->
                <img src="https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=2000&q=80"
                     alt="" 
                     class="absolute inset-0 w-full h-full object-cover object-center transform hover:scale-105 transition-transform duration-1000 ease-out"
                     loading="eager"
                     fetchpriority="high">

                <!-- Subtle Overlay for Clear Photo Visibility & Optimal Text Readability (True dark tones) -->
                <div class="absolute inset-0 bg-gradient-to-t from-[#020617]/90 via-[#020617]/50 to-[#020617]/30"></div>
                <div class="absolute inset-0 bg-gradient-to-r from-[#020617]/75 via-[#020617]/35 to-transparent"></div>

                <!-- Top Brand Header on Left Panel -->
                <div class="relative z-10 flex items-center justify-between">
                    <a href="/" wire:navigate class="group inline-flex items-center gap-3 transition-transform duration-150 hover:scale-[1.01]">
                        <x-brand-mark size="w-8 h-8" padding="p-2" radius="rounded-xl" class="shadow-lg shadow-emerald-500/20 ring-1 ring-emerald-500/30" />
                        <div>
                            <div class="font-bold text-white text-lg leading-tight tracking-tight">{{ \App\Support\Branding::appName() }}</div>
                            @if ($brandTagline = \App\Support\Branding::tagline())
                                <div class="text-xs text-emerald-400 font-medium tracking-wide">{{ $brandTagline }}</div>
                            @endif
                        </div>
                    </a>
                </div>

                <!-- Center Content: Clean & Minimal -->
                <div class="relative z-10 my-auto py-10 max-w-lg">
                    <h2 class="text-2xl xl:text-3xl font-bold text-white tracking-tight leading-snug drop-shadow-md">
                        {{ \App\Support\Branding::appName() }}
                    </h2>
                    @if ($brandTagline = \App\Support\Branding::tagline())
                        <p class="text-sm text-zinc-200 mt-1.5 leading-relaxed drop-shadow">
                            {{ $brandTagline }}
                        </p>
                    @endif
                </div>

                <!-- Bottom Footer on Left Panel -->
                <div class="relative z-10 flex items-center justify-between text-xs text-zinc-400 border-t border-white/10 pt-4">
                    <span>{{ \App\Support\Branding::companyName() }}</span>
                </div>
            </div>

            <!-- RIGHT PANEL: Auth Content -->
            <div class="flex-1 min-h-screen flex flex-col justify-between p-6 sm:p-10 lg:p-12 xl:p-14 relative bg-slate-50/80 dark:bg-slate-950 lg:border-l lg:border-slate-200/90 dark:lg:border-slate-800/80 overflow-y-auto">
                <!-- Subtle ambient background glow -->
                <div class="absolute inset-0 pointer-events-none overflow-hidden" aria-hidden="true">
                    <div class="absolute top-0 right-0 w-96 h-96 bg-emerald-500/[0.05] dark:bg-emerald-500/5 rounded-full blur-3xl"></div>
                    <div class="absolute bottom-0 left-0 w-80 h-80 bg-slate-200/40 dark:bg-slate-800/20 rounded-full blur-3xl"></div>
                </div>

                <!-- Top Utility Bar (Mobile Branding + Theme Switcher) -->
                <div class="relative z-10 flex items-center justify-between w-full max-w-sm sm:max-w-md mx-auto mb-4">
                    <!-- Mobile Brand Identity (Hidden on Desktop) -->
                    <div class="lg:hidden flex items-center gap-2.5">
                        <x-brand-mark size="w-7 h-7" padding="p-2" radius="rounded-xl" class="shadow-md shadow-emerald-500/10 ring-1 ring-emerald-500/20" />
                        <div>
                            <div class="font-bold text-slate-900 dark:text-slate-100 text-sm leading-tight">{{ \App\Support\Branding::appName() }}</div>
                            @if ($brandTagline = \App\Support\Branding::tagline())
                                <div class="text-[10px] text-slate-500 dark:text-slate-400 font-medium">{{ $brandTagline }}</div>
                            @endif
                        </div>
                    </div>

                    <!-- Desktop placeholder for flex justification -->
                    <div class="hidden lg:block"></div>

                    <!-- Theme Toggle Button -->
                    <button type="button" 
                            x-data="{ isDark: !document.documentElement.classList.contains('light') }"
                            @theme-changed.window="isDark = ($event.detail.theme !== 'light')"
                            @click="window.toggleTheme(); isDark = !isDark" 
                            class="p-2.5 rounded-xl border border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100 transition shadow-xs cursor-pointer ml-auto flex items-center justify-center"
                            title="Ganti Tema (Gelap / Terang)"
                            aria-label="Ganti Tema">
                        <template x-if="isDark">
                            <svg class="w-4 h-4 text-amber-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                            </svg>
                        </template>
                        <template x-if="!isDark">
                            <svg class="w-4 h-4 text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                            </svg>
                        </template>
                    </button>
                </div>

                <!-- Mobile Photo Banner -->
                <div class="lg:hidden relative w-full max-w-sm sm:max-w-md mx-auto h-36 rounded-2xl overflow-hidden mb-6 border border-slate-200 dark:border-slate-800 shadow-md shrink-0 no-dark-invert auth-hero-panel">
                    <img src="https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=1000&q=80" alt="" class="w-full h-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-t from-[#020617] via-[#020617]/60 to-transparent"></div>
                    <div class="absolute bottom-3 left-4 right-4">
                        <span class="text-xs font-semibold text-white drop-shadow">{{ \App\Support\Branding::companyName() }}</span>
                    </div>
                </div>

                <!-- Main Auth Form (Card styling on light mode, seamless on dark mode) -->
                <div class="relative z-10 w-full max-w-sm sm:max-w-md mx-auto my-auto py-2">
                    <div class="bg-white dark:bg-transparent p-6 sm:p-8 rounded-2xl border border-slate-200/90 dark:border-transparent shadow-[0_4px_25px_-5px_rgba(15,23,42,0.06)] dark:shadow-none">
                        {{ $slot }}
                    </div>
                </div>

                <!-- Footer Note on Right Panel -->
                <div class="relative z-10 w-full max-w-sm sm:max-w-md mx-auto mt-6 text-center text-xs text-slate-500 dark:text-slate-500 flex items-center justify-center gap-1.5 select-none">
                    <span>&copy; {{ date('Y') }} {{ \App\Support\Branding::appName() }}</span>
                    <span>&bull;</span>
                    <span>Sistem Kontrol Operasional</span>
                </div>
            </div>

        </div>
    </body>
</html>
