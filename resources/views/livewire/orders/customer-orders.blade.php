@php
    use App\Enums\CustomerOrderStatus;
    use App\Models\CustomerOrder;
    use App\Support\NumberFormatter as Num;
@endphp

<div class="space-y-4 sm:space-y-6">
    <x-list-toolbar
        description="Pesanan dengan tanggal ambil dan uang muka, atau tiket servis. Pelunasannya dilakukan di kasir lewat tombol Lunasi di Kasir."
        search-placeholder="Cari nomor, nama, telepon, atau IMEI..."
        :can-manage="false"
    >
        <x-slot:filters>
            <x-select variant="filter" wire:model.live="status" aria-label="Filter status" class="flex-1 sm:flex-none">
                <option value="open">Belum selesai</option>
                @foreach (CustomerOrderStatus::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
                <option value="all">Semua</option>
            </x-select>
            <x-select variant="filter" wire:model.live="type" aria-label="Filter jenis" class="flex-1 sm:flex-none">
                <option value="">Pesanan & servis</option>
                <option value="{{ CustomerOrder::TYPE_ORDER }}">Pesanan</option>
                <option value="{{ CustomerOrder::TYPE_SERVICE }}">Servis</option>
            </x-select>
        </x-slot:filters>
        <x-slot:actions>
            <x-secondary-button size="sm" type="button" wire:click="openCreate('service')" class="flex-1 sm:flex-none shrink-0">
                <i data-lucide="wrench" class="w-4 h-4"></i>
                <span>Tiket Servis</span>
            </x-secondary-button>
            <x-primary-button size="sm" type="button" wire:click="openCreate('order')" class="flex-1 sm:flex-none shrink-0">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Pesanan Baru</span>
            </x-primary-button>
        </x-slot:actions>
    </x-list-toolbar>

    @if ($orders->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
            <x-empty-state
                icon="clipboard-list"
                :title="$search ? 'Tidak ada pesanan yang cocok' : 'Belum ada pesanan di daftar ini'"
                description="Catat pesanan kue, barang pre-order, atau HP yang diservis. Uang muka langsung masuk laci shift Anda."
            />
        </div>
    @else
        <x-table :pagination="$orders">
            <x-slot:header>
                <tr>
                    <x-table.th>Pesanan</x-table.th>
                    <x-table.th>Pelanggan</x-table.th>
                    <x-table.th>Ambil</x-table.th>
                    <x-table.th align="right" class="hidden sm:table-cell">Perkiraan</x-table.th>
                    <x-table.th align="right">DP</x-table.th>
                    <x-table.th>Status</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($orders as $order)
                    <x-table.tr wire:key="order-{{ $order->id }}">
                        <x-table.td>
                            <a href="{{ route('orders.show', $order) }}" wire:navigate class="font-mono font-semibold text-slate-100 hover:text-emerald-300">{{ $order->number }}</a>
                            <div class="text-[11px] text-slate-400">{{ $order->isService() ? 'Servis'.($order->device ? ' · '.$order->device : '') : $order->items_count.' baris' }}</div>
                        </x-table.td>
                        <x-table.td>
                            <div class="text-slate-100">{{ $order->customer_name }}</div>
                            <div class="text-[11px] text-slate-400">{{ $order->customer_phone ?: '-' }}</div>
                        </x-table.td>
                        <x-table.td class="whitespace-nowrap">
                            @if ($order->pickup_at)
                                <span @class(['text-rose-400 font-semibold' => $order->isOpen() && $order->pickup_at->isPast(), 'text-slate-200' => ! ($order->isOpen() && $order->pickup_at->isPast())])>{{ $order->pickup_at->translatedFormat('d M · H:i') }}</span>
                            @else
                                <span class="text-slate-400">-</span>
                            @endif
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-300 hidden sm:table-cell">{{ Num::currency($order->estimated_total) }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-100">{{ Num::currency($order->deposit) }}</x-table.td>
                        <x-table.td><x-badge :color="$order->status->color()">{{ strtoupper($order->status->label()) }}</x-badge></x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    <x-record-form-modal name="order-form" :title="($form['type'] ?? '') === 'service' ? 'Tiket Servis Baru' : 'Pesanan Baru'" subtitle="Uang muka tunai tercatat sebagai kas masuk di shift Anda" icon="clipboard-list" max-width="3xl" close-action="closeOrderForm">
        <form wire:submit="save" class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                <div>
                    <x-input-label for="order-customer" value="Pelanggan terdaftar" />
                    <x-select id="order-customer" wire:model.live="form.customer_id">
                        <option value="">Bukan pelanggan terdaftar</option>
                        @foreach ($this->customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input-label for="order-name" value="Nama pemesan *" />
                    <x-text-input wire:model="form.customer_name" id="order-name" class="w-full" />
                    <x-input-error :messages="$errors->get('form.customer_name')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="order-phone" value="Telepon" />
                    <x-text-input wire:model="form.customer_phone" id="order-phone" inputmode="tel" class="w-full" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                <div>
                    <x-input-label for="order-pickup" :value="($form['type'] ?? '') === 'service' ? 'Perkiraan selesai' : 'Tanggal & jam ambil'" />
                    <x-text-input wire:model="form.pickup_at" id="order-pickup" type="datetime-local" class="w-full" />
                    <x-input-error :messages="$errors->get('form.pickup_at')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="order-notes" value="Catatan (desain, permintaan khusus)" />
                    <x-text-input wire:model="form.notes" id="order-notes" class="w-full" />
                </div>
            </div>

            @if (($form['type'] ?? '') === 'service')
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <x-input-label for="order-device" value="Perangkat (merek & tipe)" />
                        <x-text-input wire:model="form.device" id="order-device" class="w-full" placeholder="Mis. Samsung A54" />
                    </div>
                    <div>
                        <x-input-label for="order-serial" value="IMEI / nomor seri" />
                        <x-text-input wire:model="form.device_serial" id="order-serial" class="w-full font-mono" />
                    </div>
                </div>
                <div>
                    <x-input-label for="order-complaint" value="Keluhan" />
                    <x-textarea wire:model="form.complaint" id="order-complaint" rows="2" class="w-full" />
                </div>
            @endif

            <section class="space-y-2">
                <div class="flex items-center justify-between gap-3">
                    <x-input-label :value="($form['type'] ?? '') === 'service' ? 'Biaya & sparepart (perkiraan)' : 'Barang pesanan *'" class="!mb-0" />
                    <x-text-button size="sm" tone="emerald" wire:click="addFreeLine">+ Baris tanpa produk</x-text-button>
                </div>
                <x-search-input wire:model.live.debounce.300ms="productSearch" placeholder="Cari produk untuk ditambahkan..." />
                @if ($this->productChoices->isNotEmpty())
                    <div class="rounded-xl border border-slate-800 divide-y divide-slate-800/60">
                        @foreach ($this->productChoices as $product)
                            <button type="button" wire:key="pick-{{ $product->id }}" wire:click="addProduct({{ $product->id }})" class="w-full flex items-center justify-between gap-3 px-3 min-h-[44px] text-left text-sm text-slate-200 hover:bg-slate-800/40">
                                <span class="truncate">{{ $product->name }}</span>
                                <span class="tabular-nums text-slate-400">{{ Num::currency($product->effectivePrice()) }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                <x-input-error :messages="$errors->get('items')" />
                @foreach ($items as $index => $item)
                    <div wire:key="order-line-{{ $index }}" class="grid grid-cols-[1fr_5rem_7rem_auto] gap-2 items-start rounded-lg border border-slate-800/80 p-2.5">
                        <div>
                            <x-text-input wire:model="items.{{ $index }}.name" aria-label="Nama barang/jasa" class="w-full" :readonly="filled($item['product_id'])" />
                            <x-text-input wire:model="items.{{ $index }}.note" aria-label="Catatan baris" class="w-full mt-1.5 !text-xs" placeholder="Catatan (mis. tulisan 'Happy Birthday')" />
                            <x-input-error :messages="$errors->get('items.'.$index.'.name')" class="mt-1" />
                        </div>
                        <x-text-input wire:model="items.{{ $index }}.quantity" aria-label="Jumlah" inputmode="decimal" class="w-full font-mono text-right" />
                        <div>
                            <x-text-input wire:model="items.{{ $index }}.price" aria-label="Harga satuan" inputmode="numeric" class="w-full font-mono text-right" placeholder="Harga" />
                            <x-input-error :messages="$errors->get('items.'.$index.'.price')" class="mt-1" />
                        </div>
                        <x-icon-button icon="trash" label="Hapus baris" tone="danger" wire:click="removeLine({{ $index }})" />
                    </div>
                @endforeach
            </section>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5 bg-slate-950/40 p-4 rounded-xl border border-slate-800/60">
                <div>
                    <x-input-label for="order-deposit" value="Uang muka (DP)" />
                    <x-text-input wire:model="form.deposit" id="order-deposit" inputmode="numeric" class="w-full font-mono" placeholder="0" />
                    <x-input-error :messages="$errors->get('form.deposit')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="order-deposit-method" value="Dibayar dengan" />
                    <x-select id="order-deposit-method" wire:model="form.deposit_method">
                        @foreach ($methods as $method)
                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input-label for="order-deposit-ref" value="Referensi (non-tunai)" />
                    <x-text-input wire:model="form.deposit_reference" id="order-deposit-ref" class="w-full" />
                </div>
            </div>

            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="save" loading="Menyimpan...">Simpan Pesanan</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>
</div>
