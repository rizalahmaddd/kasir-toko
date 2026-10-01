<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Features;
use App\Support\Navigation;
use App\Support\NumberFormatter;
use Livewire\Volt\Component;

new class extends Component
{
    public string $query = '';
    public bool $open = false;
    public array $results = [];
    public array $flatResults = [];
    public int $selectedIndex = -1;

    public function updatedQuery(): void
    {
        $this->selectedIndex = -1;
        if (strlen(trim($this->query)) < 2) {
            $this->results = [];
            $this->flatResults = [];
            return;
        }
        $this->search();
    }

    public function search(): void
    {
        $q = trim($this->query);
        $groups = [];
        $flat = [];

        $user = auth()->user();

        /**
         * Tiap bagian = satu model yang dicari. Modul baru cukup menambah entri di sini:
         * fields (kolom yang dicari), url (tujuan), sub (teks kecil di bawah label), flags (badge).
         */
        $sections = [];

        if ($user->can('pos.sell')) {
            $sections[] = [
                'model'  => Sale::class,
                'fields' => ['number'],
                'group'  => 'Transaksi',
                'icon'   => 'receipt',
                'color'  => 'emerald',
                'label'  => fn($r) => $r->number,
                'url'    => fn($r) => route('sales.show', $r->id),
                'sub'    => fn($r) => $r->sold_at->translatedFormat('d M Y H:i').' · '.NumberFormatter::currency($r->total).' · '.($r->customer?->name ?? 'Umum'),
                'scope'  => fn($qb) => $qb->with('customer')->when(! $user->can('sales.view'), fn ($qb) => $qb->where('user_id', $user->id))->latest('sold_at'),
                'flags'  => fn($r) => $r->isVoided() ? [['label' => 'Dibatalkan', 'color' => 'rose']] : ($r->due_amount > 0 ? [['label' => 'Kasbon', 'color' => 'amber']] : []),
            ];
        }

        if ($user->can('view-master-data')) {
            $sections[] = [
                'model'  => Product::class,
                'fields' => ['name', 'sku', 'barcode'],
                'group'  => 'Produk',
                'icon'   => 'package',
                'color'  => 'emerald',
                'url'    => fn($r) => route('master-data.products', ['search' => $r->sku]),
                'sub'    => fn($r) => collect([$r->sku, NumberFormatter::currency($r->price), $r->track_stock ? 'stok '.NumberFormatter::quantity($r->stock).' '.$r->unit : null])->filter()->implode(' · '),
                'scope'  => fn($qb) => $qb->orderBy('name'),
                'flags'  => fn($r) => $r->is_active ? ($r->isLowStock() ? [['label' => 'Stok menipis', 'color' => 'amber']] : []) : [['label' => 'Nonaktif', 'color' => 'slate']],
            ];
        }

        if ($user->can('view-master-data')) {
            $sections[] = [
                'model'  => Customer::class,
                'fields' => ['name', 'code', 'phone', 'email'],
                'group'  => 'Pelanggan',
                'icon'   => 'users',
                'color'  => 'sky',
                'url'    => fn($r) => route('master-data.customers.show', $r->id),
                'sub'    => fn($r) => collect([$r->code, $r->contact_person, $r->phone])->filter()->implode(' · '),
                'scope'  => fn($qb) => $qb->orderBy('name'),
                'flags'  => fn($r) => $r->is_active ? [] : [['label' => 'Nonaktif', 'color' => 'slate']],
            ];
        }

        if ($user->can('manageUserRoles', \Spatie\Permission\Models\Role::class)) {
            $sections[] = [
                'model'  => User::class,
                'fields' => ['name', 'username', 'email', 'phone'],
                'group'  => 'Pengguna',
                'icon'   => 'user-cog',
                'color'  => 'violet',
                'url'    => fn($r) => route('settings.roles-and-permissions', ['tab' => 'users', 'cari' => $r->username ?? $r->email]),
                'sub'    => fn($r) => collect([$r->username ? '@'.$r->username : null, $r->email])->filter()->implode(' · '),
                'scope'  => fn($qb) => $qb->with('roles:id,name')->orderBy('name'),
                'flags'  => fn($r) => $r->roles->map(fn ($role) => ['label' => \Illuminate\Support\Str::title($role->name), 'color' => 'slate'])->all(),
            ];
        }

        $menuResults = $this->searchMenus($q);
        if ($menuResults !== []) {
            $groups[] = ['group' => 'Menu', 'icon' => 'layout-grid', 'color' => 'slate', 'items' => $menuResults];
            array_push($flat, ...$menuResults);
        }

        foreach ($sections as $sec) {
            $modelClass = $sec['model'];
            $table = (new $modelClass)->getTable();
            $qb = $modelClass::query()->select("{$table}.*");
            if (isset($sec['queryFilter']) && is_callable($sec['queryFilter'])) {
                ($sec['queryFilter'])($qb, $q);
            } else {
                $fields = $sec['fields'];
                $qb->where(function ($query) use ($fields, $q, $table) {
                    foreach ($fields as $i => $field) {
                        $method = $i === 0 ? 'where' : 'orWhere';
                        $query->$method("{$table}.{$field}", 'like', "%{$q}%");
                    }
                });
            }
            ($sec['scope'])($qb);
            $rows = $qb->limit(4)->get();

            if ($rows->isEmpty()) continue;

            $group = [
                'group' => $sec['group'],
                'icon'  => $sec['icon'],
                'color' => $sec['color'],
                'items' => [],
            ];
            foreach ($rows as $row) {
                $label = isset($sec['label']) && is_callable($sec['label'])
                    ? ($sec['label'])($row)
                    : $row->name;
                $url = ($sec['url'])($row);
                if (! Features::allowsUrl($url)) {
                    continue;
                }
                $entry = [
                    'label' => $label,
                    'sub'   => ($sec['sub'])($row),
                    'url'   => $url,
                    'icon'  => $sec['icon'],
                    'color' => $sec['color'],
                    'flags' => ($sec['flags'])($row),
                ];
                $group['items'][] = $entry;
                $flat[] = $entry;
            }
            if ($group['items'] !== []) {
                $groups[] = $group;
            }
        }

        $this->results = $groups;
        $this->flatResults = $flat;
    }

    /**
     * Escape teks lalu tebalkan bagian yang cocok dengan kata kunci (case-insensitive).
     */
    public function highlight(?string $text): string
    {
        $text = (string) $text;
        $keyword = trim($this->query);

        if ($keyword === '' || $text === '') {
            return e($text);
        }

        $parts = preg_split('/('.preg_quote($keyword, '/').')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        return collect($parts)
            ->map(fn (string $part, int $i) => $i % 2 === 1
                ? '<mark class="bg-transparent font-semibold text-slate-50">'.e($part).'</mark>'
                : e($part))
            ->implode('');
    }

    /**
     * Daftar menu yang bisa dicari: tautan sidebar yang lolos izin (App\Support\Navigation).
     *
     * @return list<array{label: string, section: string, icon: string, route: string, keywords: string}>
     */
    protected function menus(): array
    {
        $menus = collect(Navigation::linksForUser(auth()->user()))
            ->map(fn (array $link) => [
                'label' => $link['label'],
                'section' => $link['section'],
                'icon' => $link['icon'],
                'route' => $link['route'],
                'keywords' => $link['keywords'] ?? '',
            ])
            ->all();

        $menus[] = ['label' => 'Profil Saya', 'section' => 'Akun', 'icon' => 'user-round', 'route' => 'profile', 'keywords' => 'akun password kata sandi email'];

        return $menus;
    }

    /**
     * Menu cocok bila setiap kata di kata kunci muncul di label, grup, atau kata kunci menu,
     * jadi "lap penj" tetap menemukan "Laporan Penjualan".
     *
     * @return list<array{label: string, sub: string, url: string, icon: string, color: string, flags: array}>
     */
    protected function searchMenus(string $q): array
    {
        $words = preg_split('/\s+/', mb_strtolower($q));

        return collect($this->menus())
            ->filter(function (array $menu) use ($words) {
                if (! Features::allowsRoute($menu['route'])) {
                    return false;
                }

                $haystack = mb_strtolower("{$menu['label']} {$menu['section']} {$menu['keywords']}");

                return collect($words)->every(fn (string $word) => str_contains($haystack, $word));
            })
            ->take(5)
            ->map(fn (array $menu) => [
                'label' => $menu['label'],
                'sub'   => $menu['section'],
                'url'   => route($menu['route']),
                'icon'  => $menu['icon'],
                'color' => 'slate',
                'flags' => [],
            ])
            ->values()
            ->all();
    }

    public function openSearch(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->query = '';
        $this->results = [];
        $this->flatResults = [];
        $this->selectedIndex = -1;
    }
}; ?>

<div @keydown.window.cmd.k.prevent="$wire.openSearch()" @keydown.window.ctrl.k.prevent="$wire.openSearch()" class="contents">
    {{-- Trigger button desktop --}}
    <button
        wire:click="openSearch"
        type="button"
        class="hidden sm:inline-flex items-center gap-2 h-8 pl-2.5 pr-3 bg-slate-800/60 hover:bg-slate-800 border border-slate-700/60 hover:border-slate-600 rounded-lg text-xs text-slate-400 hover:text-slate-300 transition group w-full max-w-sm lg:max-w-md"
        title="Cari data (⌘K)"
    >
        <i data-lucide="search" class="w-3.5 h-3.5 shrink-0 text-slate-500 group-hover:text-slate-400 transition"></i>
        <span class="flex-1 text-left">Cari data…</span>
        <kbd class="hidden lg:inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-slate-700/60 border border-slate-600/60 rounded text-[9px] font-mono text-slate-500">⌘K</kbd>
    </button>

    {{-- Trigger button mobile --}}
    <x-icon-button icon="search" label="Buka pencarian" wire:click="openSearch" class="sm:hidden" />

    {{-- Command Palette Modal (teleported to body) --}}
    @if ($open)
    @teleport('body')
<div
    x-data="{
        selectedIndex: -1,
        get flatCount() { return document.querySelectorAll('[data-result-item]').length; },
        moveDown() { const n = this.flatCount; if(!n) return; this.selectedIndex = (this.selectedIndex+1)%n; },
        moveUp()   { const n = this.flatCount; if(!n) return; this.selectedIndex = this.selectedIndex<=0 ? n-1 : this.selectedIndex-1; },
        go()       { const el = document.querySelectorAll('[data-result-item]')[this.selectedIndex]; if(el) el.click(); },
    }"
    x-init="$nextTick(() => $refs.searchModal?.focus())"
    @keydown.escape.window="$wire.close()"
    @keydown.arrow-down.window.prevent="moveDown()"
    @keydown.arrow-up.window.prevent="moveUp()"
    @keydown.enter.window.prevent="go()"
    class="fixed inset-0 z-[60] flex items-start justify-center pt-[calc(0.75rem+env(safe-area-inset-top))] px-3 sm:pt-[10vh] sm:px-4"
    wire:key="global-search-modal"
>
    {{-- Backdrop --}}
    <div
        class="absolute inset-0 bg-slate-950/50 backdrop-blur-[2px]"
        wire:click="close"
    ></div>

    {{-- Dialog --}}
    <div class="relative w-full max-w-lg bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl shadow-slate-950/90 overflow-hidden z-10">
        {{-- Search Input --}}
        <div class="flex items-center gap-3 px-4 border-b border-slate-800/80">
            <i data-lucide="search" class="w-4 h-4 text-slate-500 shrink-0"></i>
            <input
                x-ref="searchModal"
                wire:model.live.debounce.250ms="query"
                type="text"
                placeholder="Cari menu, pelanggan, pengguna…"
                autocomplete="off"
                spellcheck="false"
                class="input-bare flex-1 h-12 p-0 bg-transparent border-0 text-sm text-slate-100 placeholder-slate-500 outline-none focus:outline-none focus:ring-0"
            >
            <div wire:loading wire:target="query" class="shrink-0">
                <x-spinner class="text-slate-500" />
            </div>
            <kbd class="hidden sm:inline-flex items-center px-1.5 py-0.5 bg-slate-800 border border-slate-700 rounded text-[10px] font-mono text-slate-500 shrink-0">Esc</kbd>
            <button type="button" wire:click="close" class="sm:hidden -me-2 px-2 h-11 text-xs font-medium text-slate-400 hover:text-slate-200 shrink-0">Tutup</button>
        </div>

        {{-- Results --}}
        <div class="max-h-[min(420px,60dvh)] overflow-y-auto overscroll-contain custom-scrollbar">
            @if (strlen(trim($query)) < 2)
                {{-- Hint state --}}
                <div class="px-4 py-8 text-center">
                    <i data-lucide="search" class="w-8 h-8 text-slate-700 mx-auto mb-3"></i>
                    <p class="text-xs text-slate-500">Ketik minimal 2 karakter untuk mulai mencari</p>
                    <p class="text-[11px] text-slate-600 mt-1">Menu · Pelanggan · Pengguna</p>
                </div>
            @elseif (empty($results))
                <div class="px-4 py-8 text-center">
                    <i data-lucide="search-x" class="w-8 h-8 text-slate-700 mx-auto mb-3"></i>
                    <p class="text-xs text-slate-400">Tidak ada hasil untuk <span class="text-slate-300 font-medium">"{{ $query }}"</span></p>
                </div>
            @else
                @php
                    $colorMap = [
                        'emerald' => ['text' => 'text-emerald-400', 'bg' => 'bg-emerald-500/10', 'ring' => 'ring-emerald-500/20'],
                        'sky'     => ['text' => 'text-sky-400',     'bg' => 'bg-sky-500/10',     'ring' => 'ring-sky-500/20'],
                        'amber'   => ['text' => 'text-amber-400',   'bg' => 'bg-amber-500/10',   'ring' => 'ring-amber-500/20'],
                        'violet'  => ['text' => 'text-violet-400',  'bg' => 'bg-violet-500/10',  'ring' => 'ring-violet-500/20'],
                        'orange'  => ['text' => 'text-orange-400',  'bg' => 'bg-orange-500/10',  'ring' => 'ring-orange-500/20'],
                        'teal'    => ['text' => 'text-teal-400',    'bg' => 'bg-teal-500/10',    'ring' => 'ring-teal-500/20'],
                        'rose'    => ['text' => 'text-rose-400',    'bg' => 'bg-rose-500/10',    'ring' => 'ring-rose-500/20'],
                        'indigo'  => ['text' => 'text-indigo-400',  'bg' => 'bg-indigo-500/10',  'ring' => 'ring-indigo-500/20'],
                        'slate'   => ['text' => 'text-slate-400',   'bg' => 'bg-slate-800',      'ring' => 'ring-slate-700'],
                    ];
                    $flatIdx = 0;
                @endphp
                <div class="py-2">
                    @foreach ($results as $group)
                        <div class="px-3 pt-3 pb-1">
                            <span class="text-[10px] font-semibold uppercase tracking-widest {{ $colorMap[$group['color']]['text'] ?? 'text-slate-500' }}">
                                {{ $group['group'] }}
                            </span>
                        </div>
                        @foreach ($group['items'] as $item)
                            @php $currentIdx = $flatIdx++; $c = $colorMap[$item['color']] ?? []; @endphp
                            <a
                                href="{{ $item['url'] }}"
                                wire:navigate
                                data-result-item
                                data-idx="{{ $currentIdx }}"
                                wire:click="close"
                                :class="selectedIndex === {{ $currentIdx }} ? 'bg-slate-800' : ''"
                                class="flex items-center gap-3 px-3 py-2.5 mx-2 rounded-lg hover:bg-slate-800 transition-colors group cursor-pointer"
                                x-on:mouseenter="selectedIndex = {{ $currentIdx }}"
                            >
                                <div class="w-8 h-8 rounded-lg {{ $c['bg'] ?? 'bg-slate-800' }} ring-1 {{ $c['ring'] ?? 'ring-slate-700' }} flex items-center justify-center shrink-0">
                                    <i data-lucide="{{ $item['icon'] }}" class="w-3.5 h-3.5 {{ $c['text'] ?? 'text-slate-400' }}"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-slate-300 truncate">{!! $this->highlight($item['label']) !!}</p>
                                    @if ($item['sub'])
                                        <p class="text-xs text-slate-500 truncate">{!! $this->highlight($item['sub']) !!}</p>
                                    @endif
                                    @if (! empty($item['flags']))
                                        <div class="flex flex-wrap gap-1 mt-1.5">
                                            @foreach ($item['flags'] as $flag)
                                                @php $fc = $colorMap[$flag['color']] ?? $colorMap['slate']; @endphp
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ring-1 {{ $fc['bg'] }} {{ $fc['ring'] }} {{ $fc['text'] }}">{{ $flag['label'] }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <i data-lucide="arrow-right" class="w-3.5 h-3.5 text-slate-600 group-hover:text-slate-400 transition shrink-0"></i>
                            </a>
                        @endforeach
                        @if (!$loop->last)
                            <div class="border-t border-slate-800/40 mx-4 my-1"></div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="border-t border-slate-800/60 px-4 py-2.5 flex items-center gap-4 text-[10px] text-slate-600">
            {{-- Petunjuk keyboard hanya relevan di perangkat berkeyboard --}}
            <span class="hidden sm:flex items-center gap-1.5"><kbd class="px-1 py-0.5 bg-slate-800 border border-slate-700 rounded text-[9px] font-mono">↑↓</kbd> navigasi</span>
            <span class="hidden sm:flex items-center gap-1.5"><kbd class="px-1 py-0.5 bg-slate-800 border border-slate-700 rounded text-[9px] font-mono">↵</kbd> buka</span>
            <span class="hidden sm:flex items-center gap-1.5"><kbd class="px-1 py-0.5 bg-slate-800 border border-slate-700 rounded text-[9px] font-mono">Esc</kbd> tutup</span>
            <span class="ml-auto">{{ collect($results)->sum(fn($g) => count($g['items'])) }} hasil</span>
        </div>
    </div>
    </div>
    @endteleport
    @endif
</div>
