@php
    use App\Models\ProductSerial;
@endphp

<div class="space-y-4 sm:space-y-6">
    @if ($unregistered->isNotEmpty())
        <section class="rounded-2xl border border-amber-500/30 bg-gradient-to-b from-slate-900 to-slate-800 p-4 sm:p-5 shadow-xl space-y-2">
            <p class="text-[11px] font-bold uppercase tracking-wider text-amber-400">Stok belum bernomor seri</p>
            <p class="text-xs text-slate-300">Unit ini tidak bisa dijual di kasir sampai nomor serinya didaftarkan.</p>
            <ul class="text-sm text-slate-100 space-y-0.5">
                @foreach ($unregistered as $row)
                    <li>{{ $row['product']->name }}: <span class="font-semibold tabular-nums">{{ $row['missing'] }} unit</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-list-toolbar description="Unit bernomor seri/IMEI di outlet ini. Nomor seri dicatat lewat Stok Masuk dan dipilih kasir saat menjual." search-placeholder="Cari nomor seri atau produk..." :can-manage="false">
        <x-slot:filters>
            <x-select variant="filter" wire:model.live="status" aria-label="Filter status" class="flex-1 sm:flex-none">
                <option value="{{ ProductSerial::IN_STOCK }}">Tersedia</option>
                <option value="{{ ProductSerial::SOLD }}">Terjual</option>
                <option value="{{ ProductSerial::REMOVED }}">Dikeluarkan</option>
                <option value="all">Semua</option>
            </x-select>
        </x-slot:filters>
        <x-slot:actions>
            @can('inventory.manage')
                <x-primary-button size="sm" type="button" wire:click="openRegister" class="flex-1 sm:flex-none shrink-0">
                    <i data-lucide="scan-barcode" class="w-4 h-4"></i>
                    <span>Daftarkan Nomor Seri</span>
                </x-primary-button>
            @endcan
        </x-slot:actions>
    </x-list-toolbar>

    @if ($serials->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state icon="scan-barcode" title="Tidak ada nomor seri di daftar ini" description="Aktifkan Nomor Seri di data produk, lalu catat Stok Masuk beserta nomor seri tiap unit." />
        </div>
    @else
        <x-table :pagination="$serials">
            <x-slot:header>
                <tr>
                    <x-table.th>Nomor seri</x-table.th>
                    <x-table.th>Produk</x-table.th>
                    <x-table.th>Status</x-table.th>
                    <x-table.th class="hidden sm:table-cell">Transaksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($serials as $serial)
                    <x-table.tr wire:key="serial-{{ $serial->id }}">
                        <x-table.td class="font-mono text-slate-100">{{ $serial->serial }}</x-table.td>
                        <x-table.td class="text-slate-300">{{ $serial->product?->name }}</x-table.td>
                        <x-table.td>
                            <x-badge :color="match ($serial->status) { ProductSerial::IN_STOCK => 'emerald', ProductSerial::SOLD => 'sky', default => 'slate' }">
                                {{ match ($serial->status) { ProductSerial::IN_STOCK => 'TERSEDIA', ProductSerial::SOLD => 'TERJUAL', default => 'DIKELUARKAN' } }}
                            </x-badge>
                        </x-table.td>
                        <x-table.td class="hidden sm:table-cell">
                            @if ($serial->saleItem?->sale)
                                <a href="{{ route('sales.show', $serial->saleItem->sale) }}" wire:navigate class="font-mono text-emerald-400 hover:underline">{{ $serial->saleItem->sale->number }}</a>
                                <span class="block text-[11px] text-slate-400">{{ $serial->sold_at?->translatedFormat('d M Y') }}</span>
                            @else
                                <span class="text-slate-400">-</span>
                            @endif
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-modal name="register-serials" max-width="md" focusable>
        <form wire:submit="register" class="p-5 sm:p-6 space-y-4">
            <x-modal-header title="Daftarkan nomor seri" icon="scan-barcode" closeable>Untuk stok yang sudah ada sebelum produknya memakai nomor seri. Stok tidak berubah.</x-modal-header>
            <div>
                <x-input-label for="serial-product" value="Produk *" />
                <x-select id="serial-product" wire:model="productId">
                    <option value="">Pilih produk</option>
                    @foreach ($this->serialProducts as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                    @endforeach
                </x-select>
                <x-input-error :messages="$errors->get('productId')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label for="serial-text" value="Nomor seri / IMEI *" />
                <x-textarea wire:model="serialText" id="serial-text" rows="6" class="w-full font-mono" placeholder="Satu nomor per baris, bisa hasil scan" />
                <x-input-error :messages="$errors->get('serialText')" class="mt-1.5" />
            </div>
            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled"><x-loading-label target="register" loading="Menyimpan...">Daftarkan</x-loading-label></x-primary-button>
            </x-modal-actions>
        </form>
    </x-modal>
</div>
