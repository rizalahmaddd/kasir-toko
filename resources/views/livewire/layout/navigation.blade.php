<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

@php
    $navSections = \App\Support\Navigation::forUser(auth()->user());
    $initialGroup = collect($navSections)->pluck('items')->flatten(1)
        ->first(fn (array $item) => \App\Support\Navigation::isGroupActive($item))['key'] ?? '';
@endphp

<div x-data="{
    mobileOpen: false,
    isDesktop: window.matchMedia('(min-width: 768px)').matches,
    {{-- Tanpa preferensi tersimpan, sidebar mulai ciut di tablet/laptop kecil supaya konten dapat ruang. --}}
    collapsedPref: localStorage.getItem('sidebar-collapsed') !== null ?
        localStorage.getItem('sidebar-collapsed') === 'true' :
        window.innerWidth < 1280,
    {{-- Mode ciut hanya berlaku di sidebar desktop; drawer mobile selalu tampil lengkap dengan label. --}}
    get collapsed() { return this.isDesktop && this.collapsedPref; },
    toggleCollapse() {
        this.collapsedPref = !this.collapsedPref;
        localStorage.setItem('sidebar-collapsed', this.collapsedPref);
        document.documentElement.classList.toggle('sidebar-collapsed', this.collapsedPref);
        this.flyoutGroup = null;
    },
    activeGroup: '{{ $initialGroup }}',
    flyoutGroup: null,
    flyoutTop: 0,
    toggleGroup(name, trigger) {
        if (this.collapsed) {
            if (this.flyoutGroup === name) {
                this.flyoutGroup = null;
            } else {
                const rect = trigger.getBoundingClientRect();
                const estimatedHeight = 280;
                const maxTop = Math.max(8, window.innerHeight - estimatedHeight - 16);
                this.flyoutTop = Math.max(8, Math.min(rect.top, maxTop));
                this.flyoutGroup = name;
            }
            return;
        }
        this.flyoutGroup = null;
        this.activeGroup = this.activeGroup === name ? '' : name;
        if (this.activeGroup === name) {
            setTimeout(() => this.revealGroup(trigger.parentElement), 220);
        }
    },
    revealGroup(group) {
        const nav = this.$refs.navScroll;
        if (!nav || !group) return;
        const navBox = nav.getBoundingClientRect();
        const groupBox = group.getBoundingClientRect();
        const overflow = groupBox.bottom - navBox.bottom + 8;
        if (overflow <= 0) return;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        nav.scrollBy({ top: Math.min(overflow, groupBox.top - navBox.top), behavior: reduceMotion ? 'auto' : 'smooth' });
    },
    saveScroll() {
        try {
            if (this.$refs.navScroll) sessionStorage.setItem('sidebar-scroll', this.$refs.navScroll.scrollTop);
        } catch (e) {}
    },
    restoreScroll() {
        try {
            if (this.$refs.navScroll) this.$refs.navScroll.scrollTop = Number(sessionStorage.getItem('sidebar-scroll')) || 0;
        } catch (e) {}
    },
    init() {
        window.matchMedia('(min-width: 768px)').addEventListener('change', e => {
            this.isDesktop = e.matches;
            if (e.matches) this.mobileOpen = false;
        });
        this.restoreScroll();
    }
}" @keydown.escape.window="mobileOpen = false; flyoutGroup = null" @open-mobile-nav.window="mobileOpen = true"
    class="md:h-full md:shrink-0">
    <!-- Mobile backdrop -->
    <div x-show="mobileOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="mobileOpen = false"
        class="md:hidden fixed inset-0 bg-slate-950/50 backdrop-blur-[2px] z-40" style="display: none;">
    </div>

    <aside
        :class="mobileOpen ? '!translate-x-0' : ''"
        class="fixed md:static h-full inset-y-0 left-0 z-50 -translate-x-full w-[min(18rem,85vw)] md:w-60 md:[.sidebar-collapsed_&]:w-[60px] pb-[env(safe-area-inset-bottom)] transform md:translate-x-0 md:transform-none transition-all duration-200 bg-slate-900 border-r border-slate-800/80 flex flex-col min-h-0 overflow-hidden">
        <!-- Logo header — fixed h-14 matches content header -->
        <div class="sidebar-brand h-14 border-b border-slate-800/80 flex items-center shrink-0 px-3 gap-2">
            <a href="{{ route('dashboard') }}" wire:navigate
                class="flex items-center gap-2.5 flex-1 min-w-0 overflow-hidden" :title="collapsed ? @js(\App\Support\Branding::appName()) : ''">
                <x-brand-mark size="w-5 h-5" padding="p-1.5" radius="rounded-lg" class="shrink-0" />
                <div x-show="!collapsed" data-rail-hidden x-transition:enter="transition duration-150"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="min-w-0">
                    <h1 class="font-bold text-slate-100 text-sm leading-tight truncate">{{ \App\Support\Branding::appName() }}</h1>
                    @if ($brandTagline = \App\Support\Branding::tagline())
                        <p class="text-[10px] text-slate-400 font-medium tracking-wide truncate">{{ $brandTagline }}</p>
                    @endif
                </div>
            </a>

            {{-- Mobile close --}}
            <x-icon-button icon="x" :label="__('Tutup')" @click="mobileOpen = false" class="md:hidden -me-1.5 shrink-0" />

            {{-- Desktop collapse toggle --}}
            <button @click="toggleCollapse()" :title="collapsed ? 'Perluas sidebar' : 'Ciutkan sidebar'"
                class="hidden md:inline-flex items-center justify-center w-7 h-7 rounded-lg text-slate-500 hover:text-slate-300 hover:bg-slate-800/60 transition shrink-0">
                <i :data-lucide="collapsed ? 'panel-left-open' : 'panel-left-close'" class="w-4 h-4"></i>
            </button>
        </div>

        <!-- Navigation -->
        <nav x-ref="navScroll" x-on:livewire:navigate.window="saveScroll()" @scroll.passive="flyoutGroup = null" class="sidebar-nav flex-1 min-h-0 overflow-y-auto overscroll-contain [overflow-anchor:none] py-3 custom-scrollbar">
            @foreach ($navSections as $section)
                <div @class(['px-2', 'mt-4' => ! $loop->first])>
                    @if ($section['label'])
                        <p x-show="!collapsed" data-rail-hidden
                            class="text-[10px] font-semibold text-slate-500 uppercase tracking-widest px-2 mb-1.5">
                            {{ $section['label'] }}</p>
                        <div x-show="collapsed" data-rail-only class="border-t border-slate-800/60 my-2 mx-1"></div>
                    @endif
                    <div class="space-y-0.5">
                        @foreach ($section['items'] as $item)
                            @if (isset($item['children']))
                                <div>
                                    <button type="button" @click.stop="toggleGroup('{{ $item['key'] }}', $el)"
                                        :title="collapsed ? @js($item['label']) : ''"
                                        class="w-full flex items-center justify-between gap-2 min-h-[44px] md:min-h-[36px] px-2.5 py-2 rounded-lg text-sm font-medium text-slate-400 hover:bg-slate-800/60 hover:text-slate-200 focus:outline-none transition"
                                        :class="activeGroup === '{{ $item['key'] }}' || (collapsed && flyoutGroup === '{{ $item['key'] }}') ? 'text-slate-200 bg-slate-800/40' : ''">
                                        <span class="flex items-center gap-2.5">
                                            <i data-lucide="{{ $item['icon'] }}" class="w-4 h-4 shrink-0"></i>
                                            <span x-show="!collapsed" data-rail-hidden class="truncate">{{ $item['label'] }}</span>
                                        </span>
                                        <i x-show="!collapsed" data-rail-hidden data-lucide="chevron-right"
                                            class="w-3.5 h-3.5 text-slate-500 transition-transform duration-200 shrink-0"
                                            :class="activeGroup === '{{ $item['key'] }}' ? 'rotate-90' : ''"></i>
                                    </button>
                                    <div x-show="!collapsed && activeGroup === '{{ $item['key'] }}'" data-rail-hidden @style(['display: none' => $initialGroup !== $item['key']]) x-collapse.duration.200ms>
                                        <div class="ml-3 pl-3 border-l border-slate-800 mt-0.5 space-y-0.5">
                                            @foreach ($item['children'] as $link)
                                                <x-nav-link sub :href="route($link['route'])" :active="\App\Support\Navigation::isActive($link)" wire:navigate class="justify-between">
                                                    <div class="flex items-center gap-2 min-w-0">
                                                        <i data-lucide="{{ $link['icon'] }}" class="w-3.5 h-3.5 shrink-0"></i>
                                                        <span class="truncate">{{ $link['label'] }}</span>
                                                    </div>
                                                    @if ($link['pro'] ?? false)
                                                        <span class="px-1.5 py-0.2 text-[9px] font-bold tracking-wider rounded bg-gradient-to-r from-amber-500/20 to-orange-500/20 text-amber-300 border border-amber-500/30 shrink-0">PRO</span>
                                                    @endif
                                                </x-nav-link>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @else
                                @php $isActive = \App\Support\Navigation::isActive($item); @endphp
                                <a href="{{ route($item['route']) }}" wire:navigate @class([
                                    'flex items-center justify-between gap-2.5 min-h-[44px] md:min-h-[36px] px-2.5 py-2 rounded-lg text-sm font-medium transition focus:outline-none',
                                    'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' => $isActive,
                                    'text-slate-400 border border-transparent hover:bg-slate-800/60 hover:text-slate-200' => ! $isActive,
                                ])
                                    :title="collapsed ? @js($item['label']) : ''">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <i data-lucide="{{ $item['icon'] }}" class="w-4 h-4 shrink-0"></i>
                                        <span x-show="!collapsed" data-rail-hidden class="truncate">{{ $item['label'] }}</span>
                                    </div>
                                    @if ($item['pro'] ?? false)
                                        <span x-show="!collapsed" data-rail-hidden class="px-1.5 py-0.2 text-[9px] font-bold tracking-wider rounded bg-gradient-to-r from-amber-500/20 to-orange-500/20 text-amber-300 border border-amber-500/30 shrink-0">PRO</span>
                                    @endif
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        @php
            $currentTenant = app(\App\Support\CurrentTenant::class)->get();
        @endphp

        @if ($currentTenant && auth()->user()->isSuperAdmin())
            <!-- Subscription Status Widget -->
            <div class="px-2.5 py-2 border-t border-slate-800/80 bg-slate-900/40 shrink-0">
                <a href="{{ route('settings.subscription') }}" wire:navigate
                    class="block rounded-xl border {{ $currentTenant->isPro() ? 'border-amber-500/30 bg-gradient-to-br from-amber-500/10 via-amber-500/5 to-transparent hover:border-amber-500/50' : 'border-emerald-500/30 bg-gradient-to-br from-emerald-500/10 via-emerald-500/5 to-transparent hover:border-emerald-500/50' }} p-2.5 transition group shadow-xs">
                    <div x-show="!collapsed" data-rail-hidden>
                        <div class="flex items-center justify-between gap-1.5 mb-1">
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-extrabold uppercase tracking-wider {{ $currentTenant->isPro() ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' }}">
                                <i data-lucide="{{ $currentTenant->isPro() ? 'sparkles' : 'shield-check' }}" class="w-3 h-3"></i>
                                {{ $currentTenant->isOnTrial() ? 'Trial' : ($currentTenant->isPro() ? 'Paket Pro' : 'Paket Gratis') }}
                            </span>
                            <span class="text-[10px] font-bold text-emerald-400 group-hover:translate-x-0.5 transition-transform flex items-center">
                                {{ $currentTenant->isPro() ? 'Perpanjang' : 'Upgrade' }} &rarr;
                            </span>
                        </div>
                        <p class="text-[11px] text-slate-300 truncate font-medium">
                            @if ($currentTenant->isOnTrial())
                                {{ max(0, (int) ceil(now()->diffInHours($currentTenant->accessEndsAt(), false) / 24)) }} hari lagi tersisa
                            @elseif ($currentTenant->isPro())
                                Aktif s/d {{ $currentTenant->accessEndsAt()?->format('d M Y') }}
                            @else
                                Fitur kasir esensial aktif
                            @endif
                        </p>
                    </div>
                    <div x-show="collapsed" class="flex justify-center" :title="'Langganan: {{ $currentTenant->planLabel() }}'">
                        <i data-lucide="{{ $currentTenant->isPro() ? 'sparkles' : 'credit-card' }}"
                           class="w-4 h-4 {{ $currentTenant->isPro() ? 'text-amber-400' : 'text-emerald-400' }}"></i>
                    </div>
                </a>
            </div>
        @endif

        <!-- User footer -->
        <div class="sidebar-user shrink-0 border-t border-slate-800/80" x-data="{
            open: false,
            menuStyle: '',
            toggleMenu() {
                this.open = !this.open;
                {{-- Collapsed, the 60px rail is too narrow for the menu, so it opens beside the avatar instead.
                     Only set on open: resetting it on close would snap the menu back into the rail mid fade-out. --}}
                if (!this.open) return;
                const rect = this.$refs.userButton.getBoundingClientRect();
                this.menuStyle = collapsed
                    ? `position: fixed; left: ${rect.right + 8}px; right: auto; bottom: ${window.innerHeight - rect.bottom}px; width: 12rem; margin-bottom: 0;`
                    : '';
            }
        }" @click.outside="open = false"
            @keydown.escape="open = false">
            <div class="relative">
                <button x-ref="userButton" @click="toggleMenu()" :title="collapsed ? '{{ auth()->user()->name }}' : ''"
                    class="w-full flex items-center gap-3 px-3 py-3 text-left hover:bg-slate-800/50 transition group">
                    <div
                        class="w-8 h-8 rounded-full bg-gradient-to-br from-emerald-500/30 to-emerald-600/20 border border-emerald-500/30 flex items-center justify-center font-bold text-xs text-emerald-300 shrink-0">
                        {{ Str::of(auth()->user()->name)->explode(' ')->map(fn($p) => Str::substr($p, 0, 1))->take(2)->implode('') }}
                    </div>
                    <div x-show="!collapsed" data-rail-hidden class="truncate flex-1 min-w-0">
                        <div class="font-semibold text-xs text-slate-200 truncate">{{ auth()->user()->name }}</div>
                        <div class="text-[10px] text-slate-500 truncate">
                            {{ auth()->user()->getRoleNames()->map(fn($r) => Str::title((string) $r))->join(', ') ?: __('Belum ada peran') }}
                        </div>
                    </div>
                    <i x-show="!collapsed" data-rail-hidden data-lucide="chevrons-up-down"
                        class="w-3.5 h-3.5 text-slate-600 group-hover:text-slate-400 transition shrink-0"></i>
                </button>

                <div x-show="open" x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-1"
                    class="absolute bottom-full left-2 right-2 mb-1.5 rounded-xl shadow-xl bg-slate-900 border border-slate-800 overflow-hidden z-[60]"
                    :style="menuStyle" style="display: none;" @click="open = false">
                    <x-dropdown-link :href="route('profile')" wire:navigate>{{ __('Profil Saya') }}</x-dropdown-link>
                    @if (auth()->user()->isSuperAdmin())
                        <x-dropdown-link :href="route('settings.subscription')" wire:navigate class="text-amber-400 hover:text-amber-300 flex items-center justify-between">
                            <span>{{ __('Paket & Langganan') }}</span>
                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-400/20 text-amber-300 border border-amber-400/30">PRO</span>
                        </x-dropdown-link>
                    @endif
                    <div class="border-t border-slate-800/80">
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link
                                class="text-rose-400 hover:text-rose-300">{{ __('Keluar') }}</x-dropdown-link>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    {{-- Floating Flyout khusus saat sidebar ciut (ditaruh di luar <aside> agar tidak terpotong overflow-hidden atau transform) --}}
    <div
        x-show="collapsed && flyoutGroup !== null"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-x-1"
        x-transition:enter-end="opacity-100 translate-x-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click.outside="flyoutGroup = null"
        @keydown.escape.window="flyoutGroup = null"
        :style="`top: ${flyoutTop}px; left: 68px;`"
        class="fixed z-[60] w-56 rounded-xl bg-slate-900 border border-slate-800 shadow-2xl p-2 max-h-[calc(100vh-2rem)] overflow-y-auto custom-scrollbar"
        style="display: none;"
    >
        @foreach ($navSections as $section)
            @foreach ($section['items'] as $item)
                @if (isset($item['children']))
                    <div x-show="flyoutGroup === '{{ $item['key'] }}'">
                        <div class="px-2.5 py-1.5 mb-1.5 border-b border-slate-800/80 text-xs font-semibold text-slate-200 flex items-center gap-2">
                            <i data-lucide="{{ $item['icon'] }}" class="w-3.5 h-3.5 text-emerald-400 shrink-0"></i>
                            <span class="truncate">{{ $item['label'] }}</span>
                        </div>
                        <div class="space-y-0.5" @click="flyoutGroup = null">
                            @foreach ($item['children'] as $link)
                                <x-nav-link sub :href="route($link['route'])" :active="\App\Support\Navigation::isActive($link)" wire:navigate class="justify-between">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <i data-lucide="{{ $link['icon'] }}" class="w-3.5 h-3.5 shrink-0"></i>
                                        <span class="truncate">{{ $link['label'] }}</span>
                                    </div>
                                    @if ($link['pro'] ?? false)
                                        <span class="px-1.5 py-0.2 text-[9px] font-bold tracking-wider rounded bg-gradient-to-r from-amber-500/20 to-orange-500/20 text-amber-300 border border-amber-500/30 shrink-0">PRO</span>
                                    @endif
                                </x-nav-link>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        @endforeach
    </div>
</div>
