@php use App\Support\NumberFormatter as Num; @endphp

<div class="space-y-4 sm:space-y-6 max-w-5xl">
    <a href="{{ route('sales.index') }}" wire:navigate class="text-xs text-slate-400 hover:text-slate-200 inline-flex items-center gap-1.5 min-h-[44px]">
        <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i>
        <span>Kembali ke riwayat transaksi</span>
    </a>

    @if ($sale->isVoided())
        <div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-xs text-rose-300 flex items-start gap-3">
            <i data-lucide="ban" class="w-4 h-4 shrink-0 mt-0.5"></i>
            <div>
                <p class="font-semibold">Dibatalkan {{ $sale->voided_at->translatedFormat('d M Y H:i') }} oleh {{ $sale->voider?->name ?? '-' }}</p>
                <p class="mt-0.5 text-rose-300/90">Alasan: {{ $sale->void_reason }}</p>
            </div>
        </div>
    @endif

    @if ($sale->flags)
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-300 flex items-start gap-3">
            <i data-lucide="triangle-alert" class="w-4 h-4 shrink-0 mt-0.5"></i>
            <div>
                <p class="font-semibold">Transaksi offline ini perlu ditinjau</p>
                <p class="mt-0.5">{{ implode(' · ', $sale->flagLabels()) }}</p>
            </div>
        </div>
    @endif

    @if ($sale->prescription_id && $sale->prescription)
        <div class="rounded-xl border border-slate-800 bg-slate-900/80 p-3 text-xs text-slate-300 flex items-center gap-2.5">
            <i data-lucide="file-heart" class="w-4 h-4 text-emerald-400 shrink-0"></i>
            <span>Resep
                @can('pharmacy.prescription.view')
                    <a href="{{ route('pharmacy.prescriptions.show', $sale->prescription) }}" wire:navigate class="font-mono font-semibold text-emerald-400 hover:underline">{{ $sale->prescription->number }}</a>
                @else
                    <span class="font-mono font-semibold">{{ $sale->prescription->number }}</span>
                @endcan
                · dr. {{ $sale->prescription->doctor_name }}</span>
        </div>
    @endif

    <div class="grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-4 sm:gap-6 items-start">
        <div class="space-y-4 sm:space-y-6 min-w-0">
            <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h3 class="text-lg font-bold text-slate-100 font-mono">{{ $sale->number }}</h3>
                            <x-badge :color="$sale->status->color()">{{ $sale->status->label() }}</x-badge>
                            @if (! $sale->isVoided() && $sale->due_amount > 0)
                                <x-badge color="amber">KASBON</x-badge>
                            @endif
                        </div>
                        <p class="text-xs text-slate-400 mt-1">{{ $sale->sold_at->translatedFormat('l, d F Y · H:i') }}@if (app(\App\Support\CurrentOutlet::class)->isMultiOutlet()) · {{ $sale->outlet?->name }}@endif</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('pos.receipt', $sale) }}" target="_blank" x-on:click.prevent="window.open($el.href, '_blank')"
                            class="inline-flex items-center gap-2 min-h-[44px] px-4 rounded-lg bg-slate-800 border border-slate-700 text-slate-200 hover:bg-slate-700 text-xs font-semibold">
                            <i data-lucide="printer" class="w-4 h-4"></i> Cetak Struk
                        </a>
                        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener"
                            class="inline-flex items-center gap-2 min-h-[44px] px-4 rounded-lg bg-slate-800 border border-slate-700 text-slate-200 hover:bg-slate-700 text-xs font-semibold">
                            <i data-lucide="message-circle" class="w-4 h-4"></i> WhatsApp
                        </a>
                        @if ($this->canDeliver())
                            <x-secondary-button size="sm" class="!min-h-[44px]" wire:click="openDelivery">
                                <i data-lucide="truck" class="w-4 h-4"></i> Buat Surat Jalan
                            </x-secondary-button>
                        @endif
                    </div>
                </div>

                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 mt-4 border-t border-slate-800 text-xs">
                    <div>
                        <dt class="text-[10px] uppercase font-bold text-slate-400">Kasir</dt>
                        <dd class="text-slate-200 font-medium mt-0.5">{{ $sale->cashier->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] uppercase font-bold text-slate-400">Pelanggan</dt>
                        <dd class="text-slate-200 font-medium mt-0.5">
                            @if ($sale->customer)
                                <x-feature-link :href="route('master-data.customers.show', $sale->customer)" wire:navigate class="hover:text-emerald-400">{{ $sale->customer->name }}</x-feature-link>
                            @else
                                Umum
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[10px] uppercase font-bold text-slate-400">Shift</dt>
                        <dd class="mt-0.5">
                            <x-feature-link :href="route('shifts.show', $sale->shift)" wire:navigate class="font-mono text-slate-200 hover:text-emerald-400">{{ $sale->shift->number }}</x-feature-link>
                        </dd>
                    </div>
                    @if ($sale->orderType())
                        <div>
                            <dt class="text-[10px] uppercase font-bold text-slate-400">Pesanan</dt>
                            <dd class="text-slate-200 mt-0.5">{{ $sale->orderType()->label() }}{{ $sale->orderLabel() ? ' · '.$sale->orderLabel() : '' }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-[10px] uppercase font-bold text-slate-400">Catatan</dt>
                        <dd class="text-slate-300 mt-0.5">{{ $sale->note ?: '-' }}</dd>
                    </div>
                </dl>
            </div>

            <x-table :stack="false">
                <x-slot:header>
                    <tr>
                        <x-table.th>Barang</x-table.th>
                        <x-table.th align="right">Qty</x-table.th>
                        <x-table.th align="right" class="hidden sm:table-cell">Harga</x-table.th>
                        <x-table.th align="right">Jumlah</x-table.th>
                    </tr>
                </x-slot:header>
                <tbody class="divide-y divide-slate-800/60">
                    @foreach ($sale->items as $item)
                        <x-table.tr wire:key="item-{{ $item->id }}">
                            <x-table.td>
                                <div class="font-medium text-slate-100">{{ $item->product_name }}</div>
                                <div class="text-[11px] text-slate-400">
                                    <span class="font-mono">{{ $item->sku }}</span>
                                    <span class="sm:hidden"> · {{ Num::currency($item->price) }}</span>
                                    @if ($item->note) · <em>{{ $item->note }}</em> @endif
                                </div>
                                @if ($item->serials->isNotEmpty())
                                    <div class="text-[11px] text-slate-300 font-mono">SN: {{ $item->serials->pluck('serial')->implode(', ') }}</div>
                                @endif
                                @if ($item->modifierSummary())
                                    <div class="text-[11px] text-sky-300/90">+ {{ $item->modifierSummary() }}{{ $item->modifiers_total > 0 ? ' ('.Num::currency($item->modifiers_total).'/porsi)' : '' }}</div>
                                @endif
                                @if ($item->discount_amount > 0)
                                    <div class="text-[11px] text-emerald-400">Diskon -{{ Num::currency($item->discount_amount) }}</div>
                                @endif
                            </x-table.td>
                            <x-table.td align="right" class="tabular-nums text-slate-300 whitespace-nowrap">{{ Num::quantity($item->quantity) }} {{ $item->unit }}</x-table.td>
                            <x-table.td align="right" class="tabular-nums text-slate-400 hidden sm:table-cell">{{ Num::currency($item->price) }}</x-table.td>
                            <x-table.td align="right" class="tabular-nums font-semibold text-slate-100">{{ Num::currency($item->total) }}</x-table.td>
                        </x-table.tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>

        <div class="space-y-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 shadow-lg shadow-slate-950/30 space-y-3 text-xs">
                <dl class="space-y-1.5">
                    <div class="flex justify-between text-slate-400"><dt>Subtotal</dt><dd class="tabular-nums text-slate-200">{{ Num::currency($sale->subtotal) }}</dd></div>
                    @if ($sale->discount_amount > 0)
                        <div class="flex justify-between text-slate-400">
                            <dt>Diskon{{ $sale->discount_type === 'percent' ? ' '.Num::quantity($sale->discount_value).'%' : '' }}</dt>
                            <dd class="tabular-nums text-emerald-400">-{{ Num::currency($sale->discount_amount) }}</dd>
                        </div>
                    @endif
                    @if ($sale->service_charge_amount > 0)
                        <div class="flex justify-between text-slate-400"><dt>Service {{ Num::quantity($sale->service_charge_rate) }}%</dt><dd class="tabular-nums text-slate-200">{{ Num::currency($sale->service_charge_amount) }}</dd></div>
                    @endif
                    @if ($sale->tax_amount > 0)
                        <div class="flex justify-between text-slate-400"><dt>Pajak {{ Num::quantity($sale->tax_rate) }}%</dt><dd class="tabular-nums text-slate-200">{{ Num::currency($sale->tax_amount) }}</dd></div>
                    @endif
                </dl>
                <div class="flex justify-between items-baseline pt-2 border-t border-slate-800">
                    <span class="text-sm font-semibold text-slate-300">Total</span>
                    <span class="text-2xl font-extrabold text-slate-50 tabular-nums">{{ Num::currency($sale->total) }}</span>
                </div>

                <div class="pt-2 border-t border-slate-800 space-y-1.5">
                    <p class="text-[10px] uppercase font-bold text-slate-400">Pembayaran</p>
                    @forelse ($sale->payments as $payment)
                        <div class="flex justify-between gap-2">
                            <span class="text-slate-300">
                                {{ $payment->method->label() }}
                                @if ($payment->kind === 'receivable')
                                    <span class="text-slate-400">· pelunasan {{ $payment->paid_at->translatedFormat('d M') }}</span>
                                @elseif ($payment->kind === 'deposit')
                                    <span class="text-slate-400">· uang muka pesanan</span>
                                @endif
                                @if ($payment->reference)
                                    <span class="block text-[11px] text-slate-400 font-mono">{{ $payment->reference }}</span>
                                @endif
                            </span>
                            <span class="tabular-nums text-slate-100">{{ Num::currency($payment->amount) }}</span>
                        </div>
                    @empty
                        <p class="text-slate-400">Belum ada pembayaran.</p>
                    @endforelse
                    @if ($sale->cash_received > 0)
                        <div class="flex justify-between text-slate-400"><span>Uang diterima</span><span class="tabular-nums">{{ Num::currency($sale->cash_received) }}</span></div>
                        <div class="flex justify-between text-slate-400"><span>Kembalian</span><span class="tabular-nums">{{ Num::currency($sale->change_amount) }}</span></div>
                    @endif
                </div>

                @if (! $sale->isVoided() && $sale->due_amount > 0)
                    <div class="flex justify-between items-baseline pt-2 border-t border-slate-800">
                        <span class="font-semibold text-amber-400">Sisa kasbon</span>
                        <span class="text-lg font-bold text-amber-400 tabular-nums">{{ Num::currency($sale->due_amount) }}</span>
                    </div>
                @endif
            </div>

            @if ($sale->deliveryNotes->isNotEmpty())
                <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 space-y-2.5 text-xs">
                    <p class="text-[10px] uppercase font-bold text-slate-400">Surat jalan</p>
                    @foreach ($sale->deliveryNotes as $note)
                        <div wire:key="delivery-{{ $note->id }}" class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('delivery-notes.print', $note) }}" target="_blank" class="font-mono font-semibold text-slate-100 hover:text-emerald-300">{{ $note->number }}</a>
                                <p class="text-slate-400 truncate">{{ $note->recipient }} · {{ $note->address }}</p>
                            </div>
                            @if ($note->isDelivered())
                                <x-badge color="emerald">DITERIMA</x-badge>
                            @elseif ($this->canDeliver())
                                <x-text-button size="sm" tone="emerald" wire:click="markDelivered({{ $note->id }})">Tandai diterima</x-text-button>
                            @else
                                <x-badge color="sky">DIKIRIM</x-badge>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($this->canCollect())
                <x-primary-button type="button" class="w-full" wire:click="openCollect">
                    <i data-lucide="hand-coins" class="w-4 h-4"></i> Terima Pelunasan
                </x-primary-button>
            @endif
            @if ($this->canVoid())
                <x-secondary-button tone="danger" class="w-full" @click="$dispatch('open-modal', 'void-sale')">
                    <i data-lucide="ban" class="w-4 h-4"></i> Batalkan Transaksi
                </x-secondary-button>
            @endif
        </div>
    </div>

    @if ($this->canVoid())
        <x-modal name="void-sale" max-width="md" focusable>
            <form wire:submit="void" class="p-5 sm:p-6 space-y-4">
                <x-modal-header title="Batalkan transaksi ini?" icon="ban" tone="rose" closeable>
                    Stok barang dikembalikan dan transaksi tidak dihitung di laporan.
                    @if (! $sale->shift->isOpen() && $sale->payments->contains(fn ($payment) => $payment->method->value === 'cash'))
                        Shift transaksi ini sudah ditutup, jadi uang tunai yang dikembalikan dicatat sebagai kas keluar di shift Anda yang sedang buka.
                    @endif
                </x-modal-header>
                <div>
                    <x-input-label for="voidReason" value="Alasan pembatalan *" />
                    <x-textarea wire:model="voidReason" id="voidReason" rows="2" placeholder="Mis. salah input barang, pelanggan batal beli" />
                    <x-input-error :messages="$errors->get('voidReason')" class="mt-1.5" />
                </div>
                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Kembali</x-secondary-button>
                    <x-danger-button wire:loading.attr="disabled">
                        <x-loading-label target="void" loading="Membatalkan...">Ya, Batalkan Transaksi</x-loading-label>
                    </x-danger-button>
                </x-modal-actions>
            </form>
        </x-modal>
    @endif

    @if ($this->canCollect())
        <x-modal name="collect-payment" max-width="md" focusable>
            <form wire:submit="collect" class="p-5 sm:p-6 space-y-4">
                <x-modal-header title="Terima pelunasan kasbon" icon="hand-coins" closeable>
                    Sisa tagihan {{ Num::currency($sale->due_amount) }}{{ $sale->customer ? ' atas nama '.$sale->customer->name : '' }}.
                </x-modal-header>
                @include('livewire.sales.partials.collect-fields', ['methods' => $methods])
                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="collect" loading="Menyimpan...">Simpan Pembayaran</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        </x-modal>
    @endif

    @if ($this->canDeliver())
        <x-modal name="delivery-note" max-width="lg" focusable>
            <form wire:submit="createDelivery" class="p-5 sm:p-6 space-y-4">
                <x-modal-header title="Buat surat jalan" icon="truck" closeable>
                    Barang diambil dari transaksi ini. Surat jalan dicetak tanpa harga.
                </x-modal-header>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <x-input-label for="delivery-recipient" value="Penerima *" />
                        <x-text-input wire:model="delivery.recipient" id="delivery-recipient" class="w-full" />
                        <x-input-error :messages="$errors->get('delivery.recipient')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="delivery-phone" value="Telepon" />
                        <x-text-input wire:model="delivery.phone" id="delivery-phone" inputmode="tel" class="w-full" />
                    </div>
                </div>
                <div>
                    <x-input-label for="delivery-address" value="Alamat kirim *" />
                    <x-textarea wire:model="delivery.address" id="delivery-address" rows="2" class="w-full" />
                    <x-input-error :messages="$errors->get('delivery.address')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="delivery-project" value="Proyek / keterangan" />
                    <x-text-input wire:model="delivery.project" id="delivery-project" class="w-full" placeholder="Mis. Renovasi rumah Pak Budi" />
                </div>
                <div class="grid grid-cols-2 gap-3.5">
                    <div>
                        <x-input-label for="delivery-driver" value="Sopir" />
                        <x-text-input wire:model="delivery.driver" id="delivery-driver" class="w-full" />
                    </div>
                    <div>
                        <x-input-label for="delivery-vehicle" value="No. kendaraan" />
                        <x-text-input wire:model="delivery.vehicle" id="delivery-vehicle" class="w-full font-mono uppercase" />
                    </div>
                </div>
                <x-modal-actions>
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="createDelivery" loading="Menyimpan...">Simpan Surat Jalan</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        </x-modal>
    @endif
</div>
