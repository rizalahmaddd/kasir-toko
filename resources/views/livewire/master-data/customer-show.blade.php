<div class="space-y-4 sm:space-y-6">
    <div>
        <a href="{{ route('master-data.customers') }}" wire:navigate class="text-xs text-slate-400 hover:text-slate-200 inline-flex items-center gap-1.5 min-h-[44px]">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
            <span>Kembali ke daftar pelanggan</span>
        </a>
    </div>

    <div class="bg-slate-900/80 border border-slate-800/80 rounded-2xl p-4 sm:p-6 space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2.5">
                    <h3 class="text-lg font-bold text-white">{{ $customer->name }}</h3>
                    <x-status-badge :active="$customer->is_active" />
                </div>
                <p class="text-xs text-slate-400 mt-1 font-mono">{{ $customer->code }}{{ $customer->type ? ' · '.$customer->type : '' }}</p>
            </div>
            <a href="{{ route('master-data.customers.print', $customer) }}" target="_blank" x-on:click.prevent="window.open($el.href, '_blank')" class="bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold px-4 py-2 rounded-lg text-xs inline-flex items-center justify-center gap-2 transition min-h-[44px] self-start sm:self-auto">
                <i data-lucide="printer" class="w-4 h-4"></i>
                Cetak Kartu
            </a>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 border-t border-slate-800 text-xs">
            <div>
                <span class="text-slate-400 block text-[10px] uppercase font-bold">PIC</span>
                <span class="text-slate-200 font-medium">{{ $customer->contact_person ?: '-' }}</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[10px] uppercase font-bold">Telepon / WA</span>
                <span class="text-slate-200 font-medium font-mono">{{ $customer->phone ?: '-' }}</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[10px] uppercase font-bold">Email</span>
                <span class="text-slate-200 font-medium">{{ $customer->email ?: '-' }}</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[10px] uppercase font-bold">Termin Pembayaran</span>
                <span class="text-slate-200 font-medium">{{ $customer->payment_term_days }} hari</span>
            </div>
            <div>
                <span class="text-slate-400 block text-[10px] uppercase font-bold">Batas Kasbon</span>
                <span class="text-slate-200 font-medium">{{ $customer->credit_limit === null ? 'Tanpa batas' : \App\Support\NumberFormatter::currency($customer->credit_limit) }}</span>
            </div>
            @if ($customer->npwp)
                <div>
                    <span class="text-slate-400 block text-[10px] uppercase font-bold">NPWP</span>
                    <span class="text-slate-200 font-medium font-mono">{{ $customer->npwp }}</span>
                </div>
            @endif
            @if ($customer->address)
                <div class="col-span-2 sm:col-span-4">
                    <span class="text-slate-400 block text-[10px] uppercase font-bold">Alamat</span>
                    <span class="text-slate-300">{{ $customer->address }}</span>
                </div>
            @endif
        </div>
    </div>

    @if ($this->canViewSales())
        @php
            $outstanding = $customer->outstandingBalance();
            $sales = $this->recentSales;
        @endphp
        <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl overflow-hidden">
            <div class="p-4 border-b border-slate-800 flex flex-wrap items-center justify-between gap-3">
                <h4 class="text-sm font-bold text-slate-200">Belanja Terakhir</h4>
                @if ($outstanding > 0)
                    <x-feature-link :href="route('receivables.index', ['search' => $customer->name])" wire:navigate class="text-xs font-semibold text-amber-400 hover:underline">
                        Kasbon belum lunas {{ \App\Support\NumberFormatter::currency($outstanding) }}
                    </x-feature-link>
                @endif
            </div>
            @if ($sales->isEmpty())
                <x-empty-state icon="receipt" title="Belum pernah belanja" description="Transaksi yang mencantumkan pelanggan ini akan muncul di sini." />
            @else
                <ul class="divide-y divide-slate-800/60">
                    @foreach ($sales as $sale)
                        <li>
                            <x-feature-link :href="route('sales.show', $sale)" wire:navigate class="px-4 py-2.5 flex items-center gap-3 text-xs hover:bg-slate-800/40 min-h-[48px]">
                                <span class="flex-1 min-w-0">
                                    <span class="block font-mono text-slate-200">{{ $sale->number }}</span>
                                    <span class="block text-[11px] text-slate-400">{{ $sale->sold_at->translatedFormat('d M Y H:i') }}</span>
                                </span>
                                @if ($sale->isVoided())
                                    <x-badge color="rose">BATAL</x-badge>
                                @elseif ($sale->due_amount > 0)
                                    <x-badge color="amber">SISA {{ \App\Support\NumberFormatter::currency($sale->due_amount) }}</x-badge>
                                @endif
                                <span class="tabular-nums font-semibold text-slate-100">{{ \App\Support\NumberFormatter::currency($sale->total) }}</span>
                            </x-feature-link>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl overflow-hidden">
        <div class="p-4 border-b border-slate-800 flex items-center justify-between gap-3">
            <h4 class="text-sm font-bold text-slate-200">Riwayat Perubahan</h4>
            @if ($this->canViewActivityLog())
                <a href="{{ route('reports.activity-log', ['subjectType' => \App\Models\Customer::class, 'subjectId' => $customer->id]) }}" wire:navigate
                    class="text-[11px] font-semibold text-slate-400 hover:text-slate-200 hover:underline underline-offset-2 inline-flex items-center gap-1 min-h-[44px] sm:min-h-0">
                    Lihat semua di Log Aktivitas
                    <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                </a>
            @endif
        </div>
        @if ($this->history->isEmpty())
            <x-empty-state
                icon="history"
                title="Belum ada riwayat"
                description="Setiap perubahan data pelanggan ini akan tercatat di sini."
            />
        @else
            <x-table>
                <x-slot:header>
                    <x-table.tr>
                        <x-table.th>Waktu</x-table.th>
                        <x-table.th>Aksi</x-table.th>
                        <x-table.th>Kolom Berubah</x-table.th>
                        <x-table.th>Oleh</x-table.th>
                    </x-table.tr>
                </x-slot:header>
                @foreach ($this->history as $activity)
                    <x-table.tr wire:key="customer-history-{{ $activity->id }}">
                        <x-table.td class="text-slate-400 whitespace-nowrap font-mono text-[11px]">{{ $activity->created_at->translatedFormat('d M Y H:i') }}</x-table.td>
                        <x-table.td><x-badge color="slate">{{ \App\Support\Audit\AuditTrail::eventLabel($activity->event) }}</x-badge></x-table.td>
                        <x-table.td class="text-slate-300">
                            {{ collect(\App\Support\Audit\AuditTrail::changeRows($activity))->pluck('field')->join(', ') ?: '-' }}
                        </x-table.td>
                        <x-table.td class="text-slate-300">{{ $activity->causer?->name ?? 'Sistem' }}</x-table.td>
                    </x-table.tr>
                @endforeach
            </x-table>
        @endif
    </div>
</div>
