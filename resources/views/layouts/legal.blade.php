@php
    $theme = request()->cookie("theme");
    if (! in_array($theme, ["light", "dark"])) {
        $theme = "dark";
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace("_", "-", app()->getLocale()) }}" class="h-full {{ $theme }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? "Informasi Legal" }} - {{ \App\Support\Branding::appName() }}</title>
        @if ($brandLogoUrl = \App\Support\Branding::logoUrl())
            <link rel="icon" href="{{ $brandLogoUrl }}">
        @endif

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        <script src="{{ asset("js/theme-init.js") }}?v={{ filemtime(public_path("js/theme-init.js")) }}"></script>
        @vite(["resources/css/app.css", "resources/js/app.js"])
    </head>
    <body class="min-h-screen font-sans antialiased bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 selection:bg-emerald-500 selection:text-slate-950 flex flex-col">
        <!-- Top Navbar -->
        <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                <a href="{{ url("/") }}" class="flex items-center gap-3 group">
                    @if ($brandLogoUrl)
                        <img src="{{ $brandLogoUrl }}" alt="Logo" class="w-8 h-8 rounded-lg object-contain bg-slate-100 dark:bg-slate-800 p-1">
                    @else
                        <div class="w-8 h-8 rounded-lg bg-emerald-600 flex items-center justify-center text-white font-bold text-sm shadow-xs">
                            KT
                        </div>
                    @endif
                    <div class="flex flex-col">
                        <span class="text-sm font-bold tracking-tight text-slate-900 dark:text-white group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition">
                            {{ \App\Support\Branding::appName() }}
                        </span>
                        <span class="text-[10px] text-slate-500 dark:text-slate-400">Pusat Kebijakan &amp; Legal</span>
                    </div>
                </a>

                <div class="flex items-center gap-3">
                    <nav class="hidden sm:flex items-center gap-4 text-xs font-medium text-slate-600 dark:text-slate-300">
                        <a href="{{ route("legal.privacy") }}" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition {{ request()->routeIs("legal.privacy") ? "text-emerald-600 dark:text-emerald-400 font-semibold" : "" }}">Kebijakan Privasi</a>
                        <a href="{{ route("legal.terms") }}" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition {{ request()->routeIs("legal.terms") ? "text-emerald-600 dark:text-emerald-400 font-semibold" : "" }}">Ketentuan Layanan</a>
                        <a href="{{ route("legal.delete-account") }}" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition {{ request()->routeIs("legal.delete-account") ? "text-emerald-600 dark:text-emerald-400 font-semibold" : "" }}">Hapus Akun</a>
                    </nav>

                    <button type="button" 
                            x-data="{ isDark: document.documentElement.classList.contains("dark") }"
                            @click="window.toggleTheme(); isDark = !isDark" 
                            class="p-2 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-100 transition shadow-xs cursor-pointer"
                            title="Ganti Tema">
                        <template x-if="isDark">
                            <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                            </svg>
                        </template>
                        <template x-if="!isDark">
                            <svg class="w-4 h-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                            </svg>
                        </template>
                    </button>

                    <a href="{{ route("login") }}" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white shadow-xs transition">
                        Masuk POS
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 py-10 sm:py-14 px-4 sm:px-6 lg:px-8">
            <div class="max-w-4xl mx-auto">
                {{ $slot }}
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-slate-200 dark:border-slate-800 bg-white/50 dark:bg-slate-900/50 py-8 px-4 text-center text-xs text-slate-500 dark:text-slate-400">
            <div class="max-w-5xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    &copy; {{ date("Y") }} {{ \App\Support\Branding::companyName() }}. Hak Cipta Dilindungi Undang-Undang.
                </div>
                <div class="flex items-center gap-4">
                    <a href="{{ route("legal.privacy") }}" class="hover:underline">Kebijakan Privasi</a>
                    <span>&bull;</span>
                    <a href="{{ route("legal.terms") }}" class="hover:underline">Ketentuan Layanan</a>
                    <span>&bull;</span>
                    <a href="{{ route("legal.delete-account") }}" class="hover:underline">Penghapusan Akun</a>
                </div>
            </div>
        </footer>
    </body>
</html>
