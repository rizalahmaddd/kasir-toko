@php
    $tabs = [
        'roles' => ['label' => 'Peran & Hak Akses', 'icon' => 'shield', 'hint' => 'Kelola izin per peran'],
        'matrix' => ['label' => 'Matriks Perizinan', 'icon' => 'table', 'hint' => 'Tabel komparasi seluruh peran'],
        'users' => ['label' => 'Penugasan Pengguna', 'icon' => 'users', 'hint' => 'Atur peran untuk setiap akun'],
    ];
    $tabs = array_intersect_key($tabs, array_flip($availableTabs));

    $badgeColors = [
        'superadmin' => 'bg-purple-500/20 text-purple-300 border-purple-500/30',
        'admin' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
        'staff' => 'bg-slate-500/20 text-slate-300 border-slate-500/30',
    ];
@endphp

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-lg sm:text-xl font-bold text-slate-100 flex items-center gap-2">
                <i data-lucide="shield-check" class="w-6 h-6 text-emerald-400"></i>
                {{ __('Pengaturan Peran & Perizinan Sistem') }}
            </h1>
            <p class="text-xs sm:text-sm text-slate-400 mt-1 max-w-3xl">
                Kelola hak akses berbasis peran (RBAC) dengan dukungan peran <strong>Superadmin</strong> (akses tak terbatas), konfigurasi modular per fitur, dan matriks audit keamanan.
            </p>
        </div>
        @can('create', \Spatie\Permission\Models\Role::class)
            <x-primary-button size="sm" type="button" wire:click="openCreateRoleModal" class="justify-center shrink-0">
                <i data-lucide="plus" class="w-4 h-4"></i>
                {{ __('Tambah Peran Baru') }}
            </x-primary-button>
        @endcan
    </div>

    @if (count($tabs) > 1)
    <div role="tablist" aria-label="{{ __('Bagian pengaturan peran') }}"
        class="flex gap-1 p-1 rounded-xl bg-slate-900 border border-slate-800 overflow-x-auto">
        @foreach ($tabs as $key => $item)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')"
                aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                @class([
                    'flex-1 min-w-[9.5rem] flex items-center gap-2.5 px-3 py-2 rounded-lg text-left min-h-[44px] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500',
                    'bg-slate-800 text-slate-100' => $tab === $key,
                    'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' => $tab !== $key,
                ])>
                <i data-lucide="{{ $item['icon'] }}" @class(['w-4 h-4 shrink-0', 'text-emerald-400' => $tab === $key])></i>
                <span class="min-w-0">
                    <span class="block text-xs font-semibold">{{ $item['label'] }}</span>
                    <span class="hidden sm:block text-[11px] text-slate-400 truncate">{{ $item['hint'] }}</span>
                </span>
            </button>
        @endforeach
    </div>
    @endif

    {{-- TAB 1: PERAN & HAK AKSES --}}
    @if ($tab === 'roles')
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start" wire:key="tab-roles">
            {{-- Kolom Kiri: Daftar Peran --}}
            <div class="lg:col-span-4 space-y-3">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 sm:p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xs font-bold text-slate-300 uppercase tracking-wider">
                            {{ __('Daftar Peran') }} ({{ $allRoles->count() }})
                        </h2>
                    </div>

                    <x-search-input variant="form" wire:model.live.debounce.150ms="roleSearch" placeholder="Cari peran..." />

                    <div class="space-y-1.5 max-h-[calc(100vh-22rem)] overflow-y-auto custom-scrollbar pr-1">
                        @foreach ($allRoles as $role)
                            @php
                                $isSelected = $selectedRoleName === $role->name;
                                $isSuper = $role->name === 'superadmin';
                                $isProtected = $this->isProtectedRole($role->name);
                                $colorClass = $badgeColors[$role->name] ?? 'bg-slate-800 text-slate-300 border-slate-700';
                            @endphp
                            <div wire:click="selectRole('{{ $role->name }}')"
                                @class([
                                    'w-full text-left p-3 rounded-lg border transition-all cursor-pointer flex flex-col gap-1.5 group',
                                    'bg-emerald-500/10 border-emerald-500/40 text-emerald-300 shadow-sm' => $isSelected,
                                    'bg-slate-950/60 border-slate-800/80 hover:bg-slate-800/60 hover:border-slate-700 text-slate-300' => !$isSelected,
                                ])>
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold uppercase tracking-wider border {{ $colorClass }}">
                                            {{ $role->name }}
                                        </span>
                                        @if ($isSuper)
                                            <x-badge color="sky"><i data-lucide="zap" class="w-2.5 h-2.5"></i> Bypass</x-badge>
                                        @endif
                                    </div>
                                    <span class="text-[10px] text-slate-500 group-hover:text-slate-400">
                                        {{ $role->users_count }} user
                                    </span>
                                </div>

                                <div class="flex items-center justify-between text-[11px] text-slate-400 pt-0.5">
                                    <span>
                                        @if ($isSuper)
                                            <span class="text-purple-400 font-medium">Akses tak terbatas (Semua izin)</span>
                                        @else
                                            {{ $role->permissions_count }} / {{ $totalPermissionsCount }} izin
                                        @endif
                                    </span>
                                    <span class="text-[10px] {{ $isProtected ? 'text-slate-500' : 'text-emerald-400' }}">
                                        {{ $isProtected ? 'Bawaan Sistem' : 'Kustom' }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Kolom Kanan: Rincian & Konfigurasi Izin Peran Terpilih --}}
            <div class="lg:col-span-8 space-y-4">
                @if ($selectedRole)
                    @php
                        $isSuperSelected = $selectedRole->name === 'superadmin';
                        $isProtectedSelected = $this->isProtectedRole($selectedRole->name);
                    @endphp
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-6 space-y-5">
                        {{-- Header Peran Terpilih --}}
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-slate-800">
                            <div>
                                <div class="flex items-center gap-2.5">
                                    <h2 class="text-base sm:text-lg font-bold text-slate-100 uppercase tracking-wide">
                                        {{ $selectedRole->name }}
                                    </h2>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider {{ $badgeColors[$selectedRole->name] ?? 'bg-slate-800 text-slate-300 border-slate-700' }} border">
                                        {{ $isProtectedSelected ? 'Peran Sistem' : 'Peran Kustom' }}
                                    </span>
                                    <span class="text-xs text-slate-400">
                                        ({{ $selectedRole->users->count() }} akun pengguna)
                                    </span>
                                </div>
                                <p class="text-xs text-slate-400 mt-1">
                                    Pilih dan atur izin akses modul yang diberikan untuk peran ini.
                                </p>
                            </div>

                            <div class="flex items-center gap-2">
                                @if (! $isProtectedSelected)
                                    <x-secondary-button size="xs" type="button" wire:click="openEditRoleModal({{ $selectedRole->id }})">
                                        <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                                        {{ __('Ubah Nama') }}
                                    </x-secondary-button>
                                    <x-secondary-button size="xs" tone="danger" type="button" wire:click="confirmDeleteRole({{ $selectedRole->id }})">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        {{ __('Hapus') }}
                                    </x-secondary-button>
                                @elseif (isset(\Database\Seeders\PermissionSeeder::DEFAULT_ROLE_PERMISSIONS[$selectedRole->name]))
                                    <x-secondary-button size="xs" type="button" wire:click="resetRoleToDefault" title="Kembalikan izin peran ini ke konfigurasi bawaan">
                                        <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                                        {{ __('Reset ke Bawaan') }}
                                    </x-secondary-button>
                                @endif
                            </div>
                        </div>

                        {{-- Banner Khusus Superadmin --}}
                        @if ($isSuperSelected)
                            <div class="p-4 rounded-xl bg-purple-950/40 border border-purple-800/50 flex items-start gap-3.5 text-purple-200">
                                <div class="w-8 h-8 rounded-lg bg-purple-500/20 border border-purple-500/30 flex items-center justify-center shrink-0 text-purple-300">
                                    <i data-lucide="shield-alert" class="w-5 h-5"></i>
                                </div>
                                <div class="text-xs leading-relaxed space-y-1">
                                    <p class="font-bold text-sm text-purple-100 flex items-center gap-1.5">
                                        <span>Bypass Akses Penuh Superadmin Aktif</span>
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] bg-purple-400 text-slate-950 font-extrabold uppercase">Otomatis</span>
                                    </p>
                                    <p class="text-purple-300/90">
                                        Akun dengan peran <strong>superadmin</strong> memiliki hak bypass penuh di level framework (Laravel <code>Gate::before</code>). Seluruh izin di bawah ini otomatis aktif dan valid, serta semua menu operasional, laporan keuangan, gerbang satpam, dan pengaturan dapat diakses tanpa batasan.
                                    </p>
                                </div>
                            </div>
                        @endif

                        {{-- Toolbar Pencarian Izin & Aksi Cepat --}}
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-1">
                            <x-search-input class="flex-1 max-w-sm" variant="form" wire:model.live.debounce.150ms="permissionSearch" placeholder="Cari hak akses / izin..." />

                            <div class="flex items-center gap-2">
                                <x-secondary-button size="xs" type="button" wire:click="selectAllPermissions">
                                    {{ __('Pilih Semua') }}
                                </x-secondary-button>
                                <x-secondary-button size="xs" type="button" wire:click="clearAllPermissions">
                                    {{ __('Kosongkan') }}
                                </x-secondary-button>
                            </div>
                        </div>

                        {{-- Daftar Modul & Perizinan --}}
                        <div class="space-y-4 pt-1">
                            @foreach ($permissionGroups as $moduleName => $permissions)
                                @php
                                    $moduleKeys = array_keys($permissions);
                                    $activeInModule = count(array_intersect($moduleKeys, $rolePermissions));
                                    $totalInModule = count($moduleKeys);
                                    $isAllChecked = $activeInModule === $totalInModule && $totalInModule > 0;
                                @endphp
                                <div class="rounded-xl border border-slate-800 bg-slate-950/40 overflow-hidden" wire:key="module-{{ Str::slug($moduleName) }}">
                                    {{-- Module Header --}}
                                    <div class="p-3 sm:px-4 bg-slate-800/40 border-b border-slate-800/80 flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-xs sm:text-sm text-slate-200">{{ $moduleName }}</span>
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 font-mono">
                                                {{ $activeInModule }}/{{ $totalInModule }}
                                            </span>
                                        </div>

                                        <div class="flex items-center gap-1.5 text-[11px]">
                                            @if ($isAllChecked)
                                                <x-text-button size="sm" wire:click="clearAllInModule('{{ $moduleName }}')">
                                                    {{ __('Kosongkan Modul') }}
                                                </x-text-button>
                                            @else
                                                <x-text-button tone="emerald" size="sm" wire:click="selectAllInModule('{{ $moduleName }}')">
                                                    {{ __('Pilih Semua Modul') }}
                                                </x-text-button>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Module Permissions List --}}
                                    <div class="p-2 sm:p-3 divide-y divide-slate-800/40">
                                        @foreach ($permissions as $permKey => $permMeta)
                                            @php
                                                $isChecked = in_array($permKey, $rolePermissions, true);
                                            @endphp
                                            <label class="flex items-start gap-3 p-2 rounded-lg hover:bg-slate-800/40 transition cursor-pointer select-none">
                                                <input type="checkbox"
                                                    wire:click="toggleRolePermission('{{ $permKey }}')"
                                                    @checked($isChecked)
                                                    class="mt-1 rounded bg-slate-900 border-slate-700 text-emerald-500 focus:ring-emerald-500/40 focus:ring-offset-slate-950 w-4 h-4 cursor-pointer">
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span class="text-xs font-semibold text-slate-200">{{ $permMeta['label'] }}</span>
                                                        <span class="text-[10px] font-mono text-slate-500 bg-slate-900 px-1.5 py-0.5 rounded border border-slate-800">
                                                            {{ $permKey }}
                                                        </span>
                                                    </div>
                                                    <p class="text-[11px] text-slate-400 mt-0.5 leading-relaxed">
                                                        {{ $permMeta['description'] }}
                                                    </p>
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Pengguna yang Ditugaskan ke Peran Ini --}}
                        <div class="pt-4 border-t border-slate-800 space-y-3">
                            <h3 class="text-xs font-bold text-slate-300 uppercase tracking-wider flex items-center justify-between">
                                <span>{{ __('Pengguna dengan Peran Ini') }}</span>
                                <span class="text-slate-500 font-normal">({{ $selectedRole->users->count() }})</span>
                            </h3>

                            @if ($selectedRole->users->isEmpty())
                                <p class="text-xs text-slate-500 italic">{{ __('Belum ada pengguna yang ditugaskan ke peran ini.') }}</p>
                            @else
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($selectedRole->users as $u)
                                        <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 text-xs text-slate-200">
                                            <div class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 font-bold text-[10px] flex items-center justify-center">
                                                {{ Str::substr($u->name, 0, 1) }}
                                            </div>
                                            <span>{{ $u->name }}</span>
                                            <span class="text-[10px] text-slate-500 font-mono">{{ $u->email }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- TAB 2: MATRIKS PERIZINAN GLOBAL --}}
    @if ($tab === 'matrix')
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden space-y-4 p-4 sm:p-6" wire:key="tab-matrix">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-bold text-slate-100 flex items-center gap-2">
                        <i data-lucide="layout-grid" class="w-4 h-4 text-emerald-400"></i>
                        {{ __('Matriks Hak Akses Seluruh Peran') }}
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Tinjau dan sesuaikan matriks perizinan secara cepat. Centang pada sel untuk langsung mengubah hak akses peran terkait.
                    </p>
                </div>

                <x-search-input class="w-full sm:w-64" variant="form" wire:model.live.debounce.150ms="matrixSearch" placeholder="Cari izin di matriks..." />
            </div>

            <div class="border border-slate-800 rounded-lg overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-slate-800/80 border-b border-slate-800 text-slate-200">
                            <th class="p-3 font-semibold min-w-[260px] sticky left-0 bg-slate-800/95 z-10 backdrop-blur-sm">
                                {{ __('Modul & Hak Akses') }}
                            </th>
                            @foreach ($allRoles as $role)
                                @php $isSuper = $role->name === 'superadmin'; @endphp
                                <th class="p-3 font-semibold text-center min-w-[110px] {{ $isSuper ? 'bg-purple-950/30' : '' }}">
                                    <div class="flex flex-col items-center gap-0.5">
                                        <span class="uppercase tracking-wider font-bold text-[11px] {{ $badgeColors[$role->name] ?? 'text-slate-300' }}">
                                            {{ $role->name }}
                                        </span>
                                        @if ($isSuper)
                                            <span class="text-[9px] px-1 rounded bg-purple-500/20 text-purple-300 font-mono">Bypass</span>
                                        @endif
                                    </div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach (\Database\Seeders\PermissionSeeder::PERMISSION_GROUPS as $moduleName => $permissions)
                            @php
                                if ($matrixSearch) {
                                    $search = mb_strtolower($matrixSearch);
                                    $permissions = array_filter($permissions, fn ($meta, $key) => str_contains(mb_strtolower($key), $search)
                                        || str_contains(mb_strtolower($meta['label']), $search), ARRAY_FILTER_USE_BOTH);
                                }
                            @endphp

                            @if (! empty($permissions))
                                <tr class="bg-slate-950/80 font-bold text-slate-300 text-[11px]">
                                    <td colspan="{{ $allRoles->count() + 1 }}" class="px-3 py-2 bg-slate-950/80 sticky left-0 z-10">
                                        <div class="flex items-center gap-2">
                                            <i data-lucide="folder" class="w-3.5 h-3.5 text-emerald-400"></i>
                                            <span>{{ $moduleName }}</span>
                                        </div>
                                    </td>
                                </tr>

                                @foreach ($permissions as $permKey => $permMeta)
                                    <tr class="hover:bg-slate-800/30 transition">
                                        <td class="p-2.5 px-3 sticky left-0 bg-slate-900 z-10 border-r border-slate-800/80">
                                            <div class="font-medium text-slate-200">{{ $permMeta['label'] }}</div>
                                            <div class="text-[10px] font-mono text-slate-500">{{ $permKey }}</div>
                                        </td>
                                        @foreach ($allRoles as $role)
                                            @php
                                                $isSuper = $role->name === 'superadmin';
                                                $hasPerm = $role->hasPermissionTo($permKey);
                                            @endphp
                                            <td class="p-2.5 text-center {{ $isSuper ? 'bg-purple-950/10' : '' }}">
                                                @if ($isSuper)
                                                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-purple-500/20 text-purple-300" title="Superadmin bypass">
                                                        <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                                    </span>
                                                @else
                                                    <button type="button"
                                                        wire:click="toggleMatrixPermission({{ $role->id }}, '{{ $permKey }}')"
                                                        @class([
                                                            'w-5 h-5 rounded flex items-center justify-center transition mx-auto focus:outline-none focus:ring-1 focus:ring-emerald-500',
                                                            'bg-emerald-500 text-slate-950 shadow-sm shadow-emerald-500/30' => $hasPerm,
                                                            'bg-slate-800 text-transparent border border-slate-700 hover:border-slate-500' => !$hasPerm,
                                                        ])
                                                        title="{{ $hasPerm ? 'Cabut izin' : 'Beri izin' }}">
                                                        <i data-lucide="check" class="w-3.5 h-3.5 font-bold"></i>
                                                    </button>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- TAB 3: PENUGASAN PENGGUNA --}}
    @if ($tab === 'users')
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-6 space-y-4" wire:key="tab-users">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-bold text-slate-100 flex items-center gap-2">
                        <i data-lucide="user-check" class="w-4 h-4 text-emerald-400"></i>
                        {{ __('Penugasan Peran Pengguna') }}
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Kelola peran yang ditugaskan kepada staf dan operator untuk menentukan izin akses mereka saat login.
                    </p>
                </div>

                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
                    {{-- Role Filter --}}
                    <select wire:model.live="userRoleFilter"
                        class="bg-slate-950 border border-slate-800 rounded-lg px-3 py-1.5 text-xs text-slate-200 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500">
                        <option value="">{{ __('Semua Peran') }}</option>
                        @foreach ($allRoles as $r)
                            <option value="{{ $r->name }}">{{ Str::title($r->name) }}</option>
                        @endforeach
                    </select>

                    {{-- User Search --}}
                    <x-search-input class="w-full sm:w-60" variant="form" wire:model.live.debounce.150ms="userSearch" placeholder="Cari nama / email..." />

                    <x-primary-button size="sm" type="button" wire:click="openCreateUserModal" class="justify-center shrink-0">
                        <i data-lucide="user-plus" class="w-4 h-4"></i>
                        {{ __('Tambah Pengguna') }}
                    </x-primary-button>
                </div>
            </div>

            <div class="border border-slate-800 rounded-lg overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-slate-800/80 border-b border-slate-800 text-slate-300">
                            <th class="p-3 font-semibold">{{ __('Nama & Username') }}</th>
                            <th class="p-3 font-semibold">{{ __('Email') }}</th>
                            <th class="p-3 font-semibold">{{ __('Peran Saat Ini') }}</th>
                            <th class="p-3 font-semibold">{{ __('Aplikasi Mobile') }}</th>
                            <th class="p-3 font-semibold text-right">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        @forelse ($users as $user)
                            @php $isSuper = $user->hasRole('superadmin'); @endphp
                            <tr class="hover:bg-slate-800/30 transition">
                                <td class="p-3">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-7 h-7 rounded-full bg-emerald-500/20 text-emerald-400 font-bold text-xs flex items-center justify-center shrink-0">
                                            {{ Str::substr($user->name, 0, 1) }}
                                        </div>
                                        <div>
                                            <div class="font-semibold text-slate-100">{{ $user->name }}</div>
                                            <div class="text-[10px] text-slate-500 font-mono">&#64;{{ $user->username }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3 text-slate-300 font-mono text-[11px]">
                                    {{ $user->email }}
                                </td>
                                <td class="p-3">
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($user->roles as $role)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider border {{ $badgeColors[$role->name] ?? 'bg-slate-800 text-slate-300 border-slate-700' }}">
                                                @if ($role->name === 'superadmin')
                                                    <i data-lucide="zap" class="w-2.5 h-2.5 text-purple-400"></i>
                                                @endif
                                                {{ $role->name }}
                                            </span>
                                        @empty
                                            <span class="text-slate-500 italic text-[11px]">{{ __('Belum ada peran') }}</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="p-3 text-slate-300">
                                    @if ($user->tokens_count > 0)
                                        {{ $user->tokens_count }} {{ __('perangkat') }}
                                    @else
                                        <span class="text-slate-400">{{ __('Tidak login') }}</span>
                                    @endif
                                </td>
                                <td class="p-3">
                                    <div class="flex justify-end gap-2">
                                        @if ($user->tokens_count > 0)
                                            <x-secondary-button size="xs" type="button"
                                                wire:click="revokeUserTokens({{ $user->id }})"
                                                wire:confirm="{{ __('Keluarkan :name dari semua perangkat mobile? Pengguna harus login ulang di aplikasi.', ['name' => $user->name]) }}">
                                                {{ __('Keluarkan dari HP') }}
                                            </x-secondary-button>
                                        @endif
                                        <x-secondary-button size="xs" type="button" wire:click="openUserRolesModal({{ $user->id }})">
                                            {{ __('Ubah Peran') }}
                                        </x-secondary-button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-6 text-center text-slate-400">
                                    {{ __('Tidak ada data pengguna yang cocok dengan pencarian.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pt-2">
                {{ $users->links() }}
            </div>
        </div>
    @endif

    <x-modal name="create-role" :show="false" max-width="md">
        <form wire:submit="createRole" class="p-4 sm:p-6 space-y-4">
            <x-modal-header icon="shield-plus" :title="__('Tambah Peran Baru')" closeable />

            <div>
                <x-input-label for="newRoleName" value="Nama Peran *" />
                <x-text-input wire:model="newRoleName" id="newRoleName" type="text" class="w-full" placeholder="Contoh: staff qc, spv gudang" />
                <p class="text-[11px] text-slate-400 mt-1">Gunakan huruf kecil. Karakter spasi diperbolehkan.</p>
                <x-input-error :messages="$errors->get('newRoleName')" class="mt-1" />
            </div>

            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')" wire:click="$set('showCreateRoleModal', false)">{{ __('Batal') }}</x-secondary-button>
                <x-primary-button>
                    <x-loading-label target="createRole" loading="Menyimpan...">{{ __('Simpan Peran') }}</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-modal>

    <x-modal name="edit-role" :show="false" max-width="md">
        <form wire:submit="updateRole" class="p-4 sm:p-6 space-y-4">
            <x-modal-header icon="pencil" :title="__('Ubah Nama Peran')" closeable />

            <div>
                <x-input-label for="editingRoleName" value="Nama Peran *" />
                <x-text-input wire:model="editingRoleName" id="editingRoleName" type="text" class="w-full" />
                <x-input-error :messages="$errors->get('editingRoleName')" class="mt-1" />
            </div>

            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')" wire:click="$set('showEditRoleModal', false)">{{ __('Batal') }}</x-secondary-button>
                <x-primary-button>
                    <x-loading-label target="updateRole" loading="Menyimpan...">{{ __('Simpan Perubahan') }}</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-modal>

    <x-modal name="delete-role" :show="false" max-width="sm">
        <div class="p-4 sm:p-6 space-y-4">
            <x-modal-header icon="alert-triangle" tone="rose" :title="__('Konfirmasi Hapus Peran')">Tindakan ini tidak dapat dibatalkan.</x-modal-header>

            <p class="text-xs text-slate-300">
                Apakah Anda yakin ingin menghapus peran kustom ini? Pastikan tidak ada pengguna yang sedang menggunakan peran ini.
            </p>

            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')" wire:click="$set('showDeleteRoleModal', false)">{{ __('Batal') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="deleteRole">
                    <x-loading-label target="deleteRole" loading="Menghapus...">{{ __('Hapus Peran') }}</x-loading-label>
                </x-danger-button>
            </x-modal-actions>
        </div>
    </x-modal>

    <x-modal name="user-roles" :show="false" max-width="lg">
        <div class="p-4 sm:p-6 space-y-4">
            @if ($editingUser)
                <x-modal-header icon="user-cog" :title="__('Atur Peran Akun Pengguna')" closeable>{{ $editingUser->name }} (&#64;{{ $editingUser->username }})</x-modal-header>

                <div class="space-y-2 max-h-72 overflow-y-auto custom-scrollbar pr-1">
                    @foreach ($allRoles as $r)
                        @continue($r->name === 'superadmin' && ! auth()->user()->can('assignSuperadmin', \Spatie\Permission\Models\Role::class))
                        @php
                            $isSuperRole = $r->name === 'superadmin';
                        @endphp
                        <label class="flex items-start gap-3 p-2.5 rounded-lg border border-slate-800/80 bg-slate-950/60 hover:bg-slate-800/50 transition cursor-pointer select-none">
                            <x-checkbox wire:model="editingUserRoles" value="{{ $r->name }}" class="mt-0.5" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold uppercase tracking-wider text-slate-200">{{ $r->name }}</span>
                                    @if ($isSuperRole)
                                        <x-badge color="amber">Superadmin Bypass</x-badge>
            @endif
                            </div>
                            <p class="text-[11px] text-slate-400 mt-0.5">
                                @if ($isSuperRole)
                                    Dapat mengakses seluruh modul, bypass semua perizinan, dan mengelola hak akses sistem.
                                @else
                                    {{ $r->permissions_count }} izin akses terhubung.
                                @endif
                            </p>
                        </div>
                    </label>
                @endforeach
            </div>
            @endif

            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')" wire:click="$set('showUserRolesModal', false)">{{ __('Batal') }}</x-secondary-button>
                <x-primary-button wire:click="saveUserRoles">
                        {{ __('Simpan Peran Pengguna') }}
                    </x-primary-button>
            </x-modal-actions>
        </div>
    </x-modal>
    <x-record-form-modal name="create-user" :title="__('Tambah Pengguna')" subtitle="Akun login untuk pegawai beserta perannya" icon="user-plus" max-width="2xl" close-action="closeCreateUserModal">
        <form wire:submit="createUser" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-input-label for="newUserName" :value="__('Nama')" />
                    <x-text-input wire:model="newUserName" id="newUserName" class="block mt-1 w-full" autocomplete="off" required />
                    <x-input-error :messages="$errors->get('newUserName')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="newUserUsername" :value="__('Username')" />
                    <x-text-input wire:model="newUserUsername" id="newUserUsername" class="block mt-1 w-full" autocapitalize="none" spellcheck="false" autocomplete="off" required />
                    <x-input-error :messages="$errors->get('newUserUsername')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="newUserPhone" :value="__('Nomor HP / WhatsApp (opsional)')" />
                    <x-text-input wire:model="newUserPhone" id="newUserPhone" class="block mt-1 w-full" type="tel" inputmode="tel" placeholder="081234567890" autocomplete="off" />
                    <x-input-error :messages="$errors->get('newUserPhone')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="newUserEmail" :value="__('Email')" />
                    <x-text-input wire:model="newUserEmail" id="newUserEmail" class="block mt-1 w-full" type="email" autocomplete="off" required />
                    <x-input-error :messages="$errors->get('newUserEmail')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="newUserPassword" :value="__('Password awal')" />
                    <x-text-input wire:model="newUserPassword" id="newUserPassword" class="block mt-1 w-full" type="password" autocomplete="new-password" required />
                    <x-input-error :messages="$errors->get('newUserPassword')" class="mt-1.5" />
                </div>
            </div>

            <fieldset>
                <legend class="text-sm font-medium text-slate-300">{{ __('Peran') }}</legend>
                <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach ($allRoles as $r)
                        @continue($r->name === 'superadmin' && ! auth()->user()->can('assignSuperadmin', \Spatie\Permission\Models\Role::class))
                        <x-checkbox-card wire:model="newUserRoles" value="{{ $r->name }}" :label="Str::title($r->name)" :description="$r->name === 'superadmin' ? __('Akses penuh ke seluruh sistem.') : __(':count izin akses', ['count' => $r->permissions_count])" />
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('newUserRoles')" class="mt-1.5" />
            </fieldset>

            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')" wire:click="closeCreateUserModal">{{ __('Batal') }}</x-secondary-button>
                <x-primary-button>
                    <x-loading-label target="createUser" loading="Menyimpan...">{{ __('Simpan Pengguna') }}</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>
</div>
