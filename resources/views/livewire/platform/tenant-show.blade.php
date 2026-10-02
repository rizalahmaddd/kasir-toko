@php
    use App\Support\NumberFormatter as Num;

    $usage = $this->usage;
    $owner = $this->owner;
    $reason = $tenant->blockedReason();
    $limits = ['users' => $tenant->limit('users'), 'products' => $tenant->limit('products')];
@endphp

<div class="space-y-4 sm:space-y-6">
    <div>
        <a href="{{ route('platform.tenants') }}" wire:navigate class="text-xs text-slate-400 hover:text-slate-200 inline-flex items-center gap-1.5 min-h-[44px]">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
            <span>Kembali ke daftar toko</span>
        </a>
    </div>

    <div class="bg-slate-900/80 border border-slate-800/80 rounded-2xl p-4 sm:p-6 space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-lg font-bold text-white">{{ $tenant->name }}</h3>
                    <x-badge :color="$tenant->isOnTrial() ? 'amber' : 'sky'">{{ $tenant->planLabel() }}</x-badge>
                    @if ($reason === null)
                        <x-badge color="emerald">Aktif</x-badge>
                    @elseif ($reason === 'tenant_suspended')
                        <x-badge color="rose">Nonaktif</x-badge>
                    @else
                        <x-badge color="amber">Masa aktif habis</x-badge>
                    @endif
                </div>
                <p class="text-xs text-slate-400 mt-1 font-mono">{{ $tenant->slug }}</p>
            </div>

            @if ($this->canManage())
                <div class="flex flex-wrap gap-2">
                    <x-primary-button size="sm" wire:click="openExtend">
                        <i data-lucide="calendar-plus" class="w-4 h-4"></i> Perpanjang
                    </x-primary-button>
                    <x-secondary-button size="sm" wire:click="openChangePlan">
                        <i data-lucide="layers" class="w-4 h-4"></i> Ubah Paket
                    </x-secondary-button>
                    @if ($tenant->isActive())
                        <x-danger-button size="sm" x-on:click="$dispatch('open-modal', 'toggle-status')">
                            <i data-lucide="ban" class="w-4 h-4"></i> Nonaktifkan
                        </x-danger-button>
                    @else
                        <x-secondary-button size="sm" x-on:click="$dispatch('open-modal', 'toggle-status')">
                            <i data-lucide="circle-check" class="w-4 h-4"></i> Aktifkan
                        </x-secondary-button>
                    @endif
                </div>
            @endif
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 border-t border-slate-800 text-xs">
            <div>
                <span class="text-slate-400 block text-[10px] uppercase font-bold">Aktif Sampai</span>
                <span class="text-slate-200 font-medium tabular-nums">{{ $tenant->accessEndsAt()?->translatedFormat('d M Y') ?? 'Tanpa batas' }}</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[10px] uppercase font-bold">Jenis Toko</span>
                <span class="text-slate-200 font-medium">{{ $tenant->store_type?->label() ?? ($tenant->isOnboarded() ? 'Tidak dipilih' : 'Belum persiapan') }}</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[10px] uppercase font-bold">Terdaftar</span>
                <span class="text-slate-200 font-medium tabular-nums">{{ $tenant->created_at?->translatedFormat('d M Y') }}</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[10px] uppercase font-bold">Transaksi Terakhir</span>
                <span class="text-slate-200 font-medium tabular-nums">{{ $usage['last_sale_at']?->diffForHumans() ?? 'Belum ada' }}</span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <x-dashboard.stat title="Pengguna" :value="$usage['users'].($limits['users'] ? ' / '.$limits['users'] : '')" icon="users" :tone="$limits['users'] && $usage['users'] >= $limits['users'] ? 'amber' : 'slate'" :subtitle="$limits['users'] ? 'Batas paket' : 'Tanpa batas'" />
        <x-dashboard.stat title="Produk" :value="Num::quantity($usage['products']).($limits['products'] ? ' / '.Num::quantity($limits['products']) : '')" icon="package" :tone="$limits['products'] && $usage['products'] >= $limits['products'] ? 'amber' : 'slate'" :subtitle="$limits['products'] ? 'Batas paket' : 'Tanpa batas'" />
        <x-dashboard.stat title="Omzet 30 Hari" :value="Num::currency($usage['revenue_30d'])" icon="trending-up" :subtitle="Num::quantity($usage['sales_30d']).' transaksi'" />
        <x-dashboard.stat title="Total Omzet" :value="Num::currency($usage['revenue'])" icon="wallet" :subtitle="Num::quantity($usage['sales']).' transaksi'" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
        <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800 flex flex-wrap items-center justify-between gap-3">
                <h4 class="text-sm font-bold text-slate-200">Pemilik Toko</h4>
                @if ($owner && $this->canManage())
                    <x-text-button wire:click="openResetPassword">Reset password</x-text-button>
                @endif
            </div>
            @if ($owner)
                <dl class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div>
                        <dt class="text-slate-400 text-[10px] uppercase font-bold">Nama</dt>
                        <dd class="text-slate-200 font-medium">{{ $owner->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 text-[10px] uppercase font-bold">Username</dt>
                        <dd class="text-slate-200 font-mono">{{ $owner->username }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 text-[10px] uppercase font-bold">Email</dt>
                        <dd class="text-slate-200 break-all">{{ $owner->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 text-[10px] uppercase font-bold">Nomor HP</dt>
                        <dd class="text-slate-200 font-mono">{{ $owner->phone ?: '-' }}</dd>
                    </div>
                </dl>
            @else
                <x-empty-state icon="user-x" title="Pemilik tidak ditemukan" description="Toko ini belum punya akun superadmin." />
            @endif
        </div>

        <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800">
                <h4 class="text-sm font-bold text-slate-200">Pengguna Toko</h4>
            </div>
            <ul class="divide-y divide-slate-800/60 max-h-72 overflow-y-auto custom-scrollbar">
                @foreach ($this->users as $user)
                    <li class="px-4 py-2.5 flex items-center gap-3 text-xs min-h-[48px]" wire:key="user-{{ $user->id }}">
                        <span class="flex-1 min-w-0">
                            <span class="block text-slate-200 font-medium truncate">{{ $user->name }}</span>
                            <span class="block text-[11px] text-slate-400 truncate">{{ $user->email }}</span>
                        </span>
                        <x-badge color="slate">{{ $user->role_names }}</x-badge>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl overflow-hidden">
        <div class="p-4 border-b border-slate-800">
            <h4 class="text-sm font-bold text-slate-200">Riwayat Langganan</h4>
        </div>
        @if ($this->subscriptionLogs->isEmpty())
            <x-empty-state icon="history" title="Belum ada riwayat" description="Perpanjangan, ganti paket, dan perubahan status oleh admin platform tercatat di sini." />
        @else
            <ul class="divide-y divide-slate-800/60">
                @foreach ($this->subscriptionLogs as $log)
                    <li class="px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 text-xs" wire:key="log-{{ $log->id }}">
                        <span class="flex-1 min-w-0">
                            <span class="block text-slate-200 font-medium">
                                {{ $log->actionLabel() }}
                                @if ($log->from_plan !== $log->to_plan)
                                    <span class="text-slate-400 font-normal">· {{ config("saas.plans.{$log->from_plan}.label", $log->from_plan) }} → {{ config("saas.plans.{$log->to_plan}.label", $log->to_plan) }}</span>
                                @endif
                            </span>
                            <span class="block text-[11px] text-slate-400">
                                {{ $log->created_at->translatedFormat('d M Y H:i') }} · {{ $log->user?->name ?? 'Sistem' }}
                                @if ($log->from_ends_at != $log->to_ends_at)
                                    · aktif sampai {{ $log->to_ends_at?->translatedFormat('d M Y') ?? 'tanpa batas' }}
                                @endif
                                @if ($log->note)
                                    · {{ $log->note }}
                                @endif
                            </span>
                        </span>
                        @if ($log->amount !== null)
                            <span class="tabular-nums font-semibold text-emerald-400">{{ Num::currency($log->amount) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($this->canManage())
        <x-modal name="extend-tenant" :show="false" max-width="md">
            <form wire:submit="extend" class="p-4 sm:p-6 space-y-4">
                <x-modal-header icon="calendar-plus" title="Perpanjang Masa Aktif" closeable />
                <p class="text-xs text-slate-400">Dihitung dari {{ $tenant->accessEndsAt()?->isFuture() ? 'akhir masa aktif sekarang ('.$tenant->accessEndsAt()->translatedFormat('d M Y').')' : 'hari ini' }}.</p>
                <div>
                    <x-input-label for="extendDays" value="Lama Perpanjangan *" />
                    <x-select id="extendDays" wire:model="extendDays">
                        @foreach ([7 => '7 hari', 30 => '30 hari', 90 => '3 bulan', 180 => '6 bulan', 365 => '1 tahun'] as $days => $label)
                            <option value="{{ $days }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('extendDays')" class="mt-1.5" />
                </div>
                @include('livewire.platform.partials.payment-fields')
                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="extend" loading="Menyimpan...">Perpanjang</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        </x-modal>

        <x-modal name="change-plan" :show="false" max-width="md">
            <form wire:submit="changePlan" class="p-4 sm:p-6 space-y-4">
                <x-modal-header icon="layers" title="Ubah Paket" closeable />
                <div>
                    <x-input-label for="plan" value="Paket *" />
                    <x-select id="plan" wire:model="plan">
                        @foreach ($plans as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('plan')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="access_ends_at" value="Aktif Sampai" />
                    <x-text-input wire:model="access_ends_at" id="access_ends_at" type="date" class="w-full" />
                    <p class="mt-1 text-[11px] text-slate-500">Kosongkan untuk tanpa batas waktu.</p>
                    <x-input-error :messages="$errors->get('access_ends_at')" class="mt-1.5" />
                </div>
                @include('livewire.platform.partials.payment-fields')
                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="changePlan" loading="Menyimpan...">Simpan</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        </x-modal>

        <x-modal name="toggle-status" :show="false" max-width="sm">
            <div class="p-4 sm:p-6 space-y-4">
                @if ($tenant->isActive())
                    <x-modal-header icon="ban" tone="rose" title="Nonaktifkan toko ini?" closeable />
                    <p class="text-sm text-slate-400">Semua pengguna {{ $tenant->name }} tidak bisa masuk ke web maupun aplikasi mobile sampai toko diaktifkan lagi. Datanya tetap tersimpan.</p>
                @else
                    <x-modal-header icon="circle-check" title="Aktifkan toko ini?" closeable />
                    <p class="text-sm text-slate-400">Pengguna {{ $tenant->name }} bisa masuk lagi selama masa aktifnya belum habis.</p>
                @endif
                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    @if ($tenant->isActive())
                        <x-danger-button wire:click="toggleStatus" wire:loading.attr="disabled">
                            <x-loading-label target="toggleStatus" loading="Memproses...">Nonaktifkan</x-loading-label>
                        </x-danger-button>
                    @else
                        <x-primary-button type="button" wire:click="toggleStatus" wire:loading.attr="disabled">
                            <x-loading-label target="toggleStatus" loading="Memproses...">Aktifkan</x-loading-label>
                        </x-primary-button>
                    @endif
                </x-modal-actions>
            </div>
        </x-modal>

        @if ($owner)
            <x-modal name="reset-owner-password" :show="false" max-width="md">
                <form wire:submit="resetOwnerPassword" class="p-4 sm:p-6 space-y-4">
                    <x-modal-header icon="key-round" tone="amber" title="Reset Password Pemilik" closeable />
                    <p class="text-xs text-slate-400">Password {{ $owner->name }} diganti dan semua sesi aplikasi mobile-nya dikeluarkan. Sampaikan password baru lewat jalur yang aman.</p>
                    <div>
                        <x-input-label for="newPassword" value="Password Baru *" />
                        <x-text-input wire:model="newPassword" id="newPassword" type="text" class="w-full font-mono" autocomplete="off" />
                        <x-input-error :messages="$errors->get('newPassword')" class="mt-1.5" />
                    </div>
                    <x-modal-actions>
                        <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                        <x-primary-button wire:loading.attr="disabled">
                            <x-loading-label target="resetOwnerPassword" loading="Menyimpan...">Reset Password</x-loading-label>
                        </x-primary-button>
                    </x-modal-actions>
                </form>
            </x-modal>
        @endif
    @endif
</div>
