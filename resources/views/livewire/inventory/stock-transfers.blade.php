@php
    use App\Models\StockTransfer;
    use App\Support\NumberFormatter as Num;
    $outlets = $this->outlets;
@endphp

<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <p class="text-xs text-slate-400">Pindahkan stok antar outlet. Stok langsung berkurang di outlet asal dan bertambah di outlet tujuan; total stok toko tidak berubah.</p>
        <div class="flex flex-wrap items-center gap-2">
            <x-select variant="filter" wire:model.live="status" aria-label="Filter status transfer" class="flex-1 sm:flex-none">
                <option value="">Semua transfer</option>
                <option value="{{ StockTransfer::STATUS_COMPLETED }}">Selesai</option>
                <option value="{{ StockTransfer::STATUS_CANCELLED }}">Dibatalkan</option>
            </x-select>
            @if ($outlets->count() > 1)
                <x-primary-button size="sm" type="button" wire:click="openCreate" class="flex-1 sm:flex-none">
                    <i data-lucide="plus" class="w-4 h-4"></i> <span>Transfer Baru</span>
                </x-primary-button>
            @endif
        </div>
    </div>

    @if ($outlets->count() < 2)
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-3.5 text-xs text-amber-300 flex items-start gap-2">
            <i data-lucide="info" class="w-4 h-4 shrink-0 mt-0.5"></i>
            <span>Transfer butuh minimal dua outlet yang bisa Anda akses dan tidak terkunci paket.</span>
        </div>
    @endif

    @if ($transfers->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
            <x-empty-state icon="arrow-left-right" :title="$status ? 'Tidak ada transfer yang cocok' : 'Belum ada transfer stok'" description="Transfer yang dibuat dari outlet Anda tercatat di sini beserta kartu stok kedua outlet." />
        </div>
    @else
        <x-table :pagination="$transfers">
            <x-slot:header>
                <tr>
                    <x-table.th>Nomor</x-table.th>
                    <x-table.th>Dari</x-table.th>
                    <x-table.th>Ke</x-table.th>
                    <x-table.th align="right">Item</x-table.th>
                    <x-table.th>Status</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($transfers as $transfer)
                    <x-table.tr wire:key="transfer-{{ $transfer->id }}">
                        <x-table.td>
                            <div class="font-mono font-semibold text-slate-100">{{ $transfer->number }}</div>
                            <div class="text-[11px] text-slate-400">{{ $transfer->transferred_at->translatedFormat('d M Y H:i') }} · {{ $transfer->creator?->name }}</div>
                        </x-table.td>
                        <x-table.td data-label="Dari" class="text-slate-300">{{ $transfer->fromOutlet?->name }}</x-table.td>
                        <x-table.td data-label="Ke" class="text-slate-300">{{ $transfer->toOutlet?->name }}</x-table.td>
                        <x-table.td data-label="Item" align="right" class="tabular-nums text-slate-300">{{ $transfer->items_count }} produk</x-table.td>
                        <x-table.td data-label="Status">
                            @if ($transfer->isCancelled())
                                <x-badge color="slate">DIBATALKAN</x-badge>
                            @else
                                <x-badge color="emerald">SELESAI</x-badge>
                            @endif
                        </x-table.td>
                        <x-table.td data-label="Aksi" align="right">
                            <x-icon-button icon="eye" :label="'Lihat '.$transfer->number" wire:click="view({{ $transfer->id }})" />
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    {{-- Transfer baru --}}
    <x-record-form-modal name="transfer-form" title="Transfer Stok Baru" subtitle="Pindahkan stok dari satu outlet ke outlet lain" icon="arrow-left-right" max-width="2xl" close-action="closeForm">
        <form wire:submit="save" class="space-y-4">
            <div class="grid sm:grid-cols-2 gap-3.5">
                <div>
                    <x-input-label for="transfer-from" value="Dari outlet *" />
                    <x-select wire:model.live="fromOutletId" id="transfer-from">
                        <option value="">Pilih outlet asal</option>
                        @foreach ($outlets as $outlet)
                            <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('fromOutletId')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="transfer-to" value="Ke outlet *" />
                    <x-select wire:model="toOutletId" id="transfer-to">
                        <option value="">Pilih outlet tujuan</option>
                        @foreach ($outlets->where('id', '!=', (int) $fromOutletId) as $outlet)
                            <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('toOutletId')" class="mt-1.5" />
                </div>
            </div>

            <div>
                <x-input-label for="transfer-search" value="Tambah produk" />
                <x-search-input wire:model.live.debounce.300ms="productSearch" id="transfer-search" placeholder="Cari nama, SKU, atau barcode..." :disabled="$fromOutletId === ''" />
                @if ($this->productMatches->isNotEmpty())
                    <ul class="mt-1.5 rounded-lg border border-slate-800 divide-y divide-slate-800/60 overflow-hidden">
                        @foreach ($this->productMatches as $match)
                            <li wire:key="match-{{ $match->id }}">
                                <button type="button" wire:click="addProduct({{ $match->id }})" class="w-full min-h-[44px] px-3 py-2 flex items-center justify-between gap-3 text-left text-xs hover:bg-slate-800/60">
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-slate-100 truncate">{{ $match->name }}</span>
                                        <span class="block font-mono text-[11px] text-slate-400">{{ $match->sku }}</span>
                                    </span>
                                    <span class="shrink-0 tabular-nums text-slate-300">{{ Num::quantity($match->outletStock()) }} {{ $match->unit }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($items !== [])
                <div class="space-y-2">
                    @foreach ($items as $index => $item)
                        <div wire:key="item-{{ $item['product_id'] }}" class="flex items-center gap-3 rounded-lg border border-slate-800 bg-slate-950 px-3 py-2">
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-semibold text-slate-100 truncate">{{ $item['name'] }}</p>
                                <p class="text-[11px] text-slate-400">Stok di outlet asal {{ Num::quantity($item['available']) }} {{ $item['unit'] }}</p>
                            </div>
                            <x-text-input wire:model="items.{{ $index }}.quantity" inputmode="decimal" class="w-24 text-right tabular-nums" aria-label="Jumlah {{ $item['name'] }}" />
                            <x-icon-button icon="x" :label="'Hapus '.$item['name']" tone="danger" wire:click="removeItem({{ $index }})" />
                        </div>
                        <x-input-error :messages="$errors->get('items.'.$index.'.quantity')" />
                    @endforeach
                </div>
            @endif
            <x-input-error :messages="$errors->get('items')" />

            <div>
                <x-input-label for="transfer-note" value="Catatan" />
                <x-text-input wire:model="note" id="transfer-note" class="w-full" placeholder="Mis. restock cabang akhir pekan" />
                <x-input-error :messages="$errors->get('note')" class="mt-1.5" />
            </div>

            <x-modal-actions>
                <x-secondary-button type="button" wire:click="closeForm">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="save" loading="Memindahkan...">Pindahkan Stok</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>

    {{-- Detail transfer --}}
    <x-modal name="transfer-detail" max-width="lg">
        @if ($transfer = $this->viewing)
            <div class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
                <x-modal-header icon="arrow-left-right" :title="$transfer->number" closeable>
                    {{ $transfer->fromOutlet?->name }} → {{ $transfer->toOutlet?->name }} · {{ $transfer->transferred_at->translatedFormat('d M Y H:i') }}
                </x-modal-header>

                <ul class="rounded-lg border border-slate-800 divide-y divide-slate-800/60">
                    @foreach ($transfer->items as $line)
                        <li class="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                            <span class="min-w-0 truncate text-slate-100">{{ $line->product?->name }}</span>
                            <span class="shrink-0 tabular-nums font-semibold text-slate-200">{{ Num::quantity($line->quantity) }} {{ $line->product?->unit }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($transfer->note)
                    <p class="text-xs text-slate-400">Catatan: {{ $transfer->note }}</p>
                @endif
                @if ($transfer->isCancelled())
                    <p class="text-xs text-slate-400">Dibatalkan oleh {{ $transfer->canceller?->name }} pada {{ $transfer->cancelled_at?->translatedFormat('d M Y H:i') }}.</p>
                @endif

                <x-modal-actions>
                    <x-secondary-button type="button" @click="$dispatch('close-modal', 'transfer-detail')">Tutup</x-secondary-button>
                    @unless ($transfer->isCancelled())
                        <x-danger-button type="button" wire:click="confirmCancel({{ $transfer->id }})">Batalkan Transfer</x-danger-button>
                    @endunless
                </x-modal-actions>
            </div>
        @endif
    </x-modal>

    <x-modal name="transfer-cancel" max-width="sm">
        <div class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
            <x-modal-header icon="undo-2" tone="rose" title="Batalkan transfer ini?">Stok dikembalikan ke outlet asal. Ditolak bila stok di outlet tujuan sudah terpakai dan tidak cukup.</x-modal-header>
            <x-modal-actions>
                <x-secondary-button type="button" @click="$dispatch('close-modal', 'transfer-cancel')">Tidak</x-secondary-button>
                <x-danger-button type="button" wire:click="cancelTransfer" wire:loading.attr="disabled">
                    <x-loading-label target="cancelTransfer" loading="Membatalkan...">Ya, Batalkan</x-loading-label>
                </x-danger-button>
            </x-modal-actions>
        </div>
    </x-modal>
</div>
