@php
    use App\Enums\CustomerOrderStatus;
    use App\Support\NumberFormatter as Num;
@endphp

<div class="space-y-4 sm:space-y-6">
    <div class="grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-4 sm:gap-6 items-start">
        <div class="space-y-4 sm:space-y-6 min-w-0">
            <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h3 class="text-lg font-bold text-slate-100 font-mono">{{ $order->number }}</h3>
                            <x-badge :color="$order->status->color()">{{ strtoupper($order->status->label()) }}</x-badge>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">Dicatat {{ $order->created_at->translatedFormat('d M Y · H:i') }} oleh {{ $order->creator?->name ?? '-' }}</p>
                    </div>
                    <x-secondary-button size="sm" :href="route('orders.print', $order).'?print=1'" target="_blank">
                        <i data-lucide="printer" class="w-4 h-4"></i>
                        <span>Cetak Nota</span>
                    </x-secondary-button>
                </div>

                {{-- Status Progress Stepper --}}
                <div class="pt-1 pb-1">
                    @php
                        $steps = [
                            CustomerOrderStatus::New->value => 'Diterima',
                            CustomerOrderStatus::InProgress->value => 'Dikerjakan',
                            CustomerOrderStatus::Ready->value => 'Siap Diambil',
                            CustomerOrderStatus::PickedUp->value => 'Selesai',
                        ];
                        $stepKeys = array_keys($steps);
                        $currentIdx = array_search($order->status->value, $stepKeys, true);
                        if ($currentIdx === false && $order->status === CustomerOrderStatus::Cancelled) {
                            $currentIdx = -1;
                        }
                    @endphp
                    @if ($order->status === CustomerOrderStatus::Cancelled)
                        <div class="rounded-lg bg-rose-500/10 border border-rose-500/20 px-3 py-2 text-xs text-rose-300 flex items-center gap-2">
                            <i data-lucide="ban" class="w-4 h-4 shrink-0 text-rose-400"></i>
                            <span>Pesanan ini dibatalkan{{ $order->cancel_reason ? ': '.$order->cancel_reason : '.' }}</span>
                        </div>
                    @else
                        <div class="grid grid-cols-4 gap-2 text-center text-xs">
                            @foreach ($steps as $key => $label)
                                @php
                                    $idx = array_search($key, $stepKeys, true);
                                    $isPassed = $currentIdx !== false && $idx <= $currentIdx;
                                    $isCurrent = $order->status->value === $key;
                                @endphp
                                <div class="flex flex-col items-center">
                                    <div @class([
                                        'w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold transition-colors',
                                        'bg-emerald-500 text-white ring-2 ring-emerald-500/30' => $isPassed && ! $isCurrent,
                                        'bg-sky-500 text-white ring-2 ring-sky-500/30 ring-offset-2 ring-offset-slate-900' => $isCurrent,
                                        'bg-slate-800 text-slate-500 border border-slate-700' => ! $isPassed,
                                    ])>
                                        @if ($isPassed && ! $isCurrent)
                                            <i data-lucide="check" class="w-3.5 h-3.5"></i>
                                        @else
                                            {{ $idx + 1 }}
                                        @endif
                                    </div>
                                    <span @class([
                                        'text-[10px] mt-1 font-semibold leading-tight',
                                        'text-slate-100' => $isCurrent,
                                        'text-emerald-400' => $isPassed && ! $isCurrent,
                                        'text-slate-500' => ! $isPassed,
                                    ])>{{ $label }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 border-t border-slate-800 text-xs">
                    <div>
                        <dt class="text-[10px] uppercase font-bold text-slate-400">Pelanggan</dt>
                        <dd class="text-slate-200 mt-0.5">{{ $order->customer_name }}<span class="block text-slate-400">{{ $order->customer_phone }}</span></dd>
                    </div>
                    <div>
                        <dt class="text-[10px] uppercase font-bold text-slate-400">{{ $order->isService() ? 'Perkiraan selesai' : 'Ambil' }}</dt>
                        <dd class="text-slate-200 mt-0.5">{{ $order->pickup_at?->translatedFormat('l, d M Y · H:i') ?? '-' }}</dd>
                    </div>
                    @if ($order->isService())
                        <div>
                            <dt class="text-[10px] uppercase font-bold text-slate-400">Perangkat</dt>
                            <dd class="text-slate-200 mt-0.5">{{ $order->device ?: '-' }}<span class="block text-slate-400 font-mono">{{ $order->device_serial }}</span></dd>
                        </div>
                        <div>
                            <dt class="text-[10px] uppercase font-bold text-slate-400">Keluhan</dt>
                            <dd class="text-slate-300 mt-0.5">{{ $order->complaint ?: '-' }}</dd>
                        </div>
                    @endif
                    <div class="col-span-2">
                        <dt class="text-[10px] uppercase font-bold text-slate-400">Catatan</dt>
                        <dd class="text-slate-300 mt-0.5 whitespace-pre-line">{{ $order->notes ?: '-' }}</dd>
                    </div>
                </dl>
            </div>

            @if ($order->items->isEmpty())
                <div class="bg-slate-900/80 rounded-xl border border-slate-800/80 overflow-hidden">
                    <x-empty-state icon="package" title="Belum ada barang/biaya" description="Barang atau sparepart ditambahkan di kasir saat pelunasan." />
                </div>
            @else
                <x-table>
                    <x-slot:header>
                        <tr>
                            <x-table.th>Barang / jasa</x-table.th>
                            <x-table.th align="right">Qty</x-table.th>
                            <x-table.th align="right">Harga</x-table.th>
                        </tr>
                    </x-slot:header>
                    <tbody class="divide-y divide-slate-800/60">
                        @foreach ($order->items as $item)
                            <x-table.tr wire:key="item-{{ $item->id }}">
                                <x-table.td>
                                    <div class="font-medium text-slate-100">{{ $item->name }}</div>
                                    @if ($item->note)
                                        <div class="text-[11px] text-slate-400 italic">{{ $item->note }}</div>
                                    @endif
                                </x-table.td>
                                <x-table.td align="right" class="tabular-nums text-slate-300">{{ Num::quantity($item->quantity) }}</x-table.td>
                                <x-table.td align="right" class="tabular-nums text-slate-300">{{ Num::currency($item->price) }}</x-table.td>
                            </x-table.tr>
                        @endforeach
                    </tbody>
                </x-table>
            @endif
        </div>

        <div class="space-y-4">
            <section class="rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-800 p-4 sm:p-5 shadow-xl space-y-2 text-sm">
                <div class="flex justify-between text-slate-400"><span>Perkiraan total</span><span class="tabular-nums text-slate-200">{{ Num::currency($order->estimated_total) }}</span></div>
                <div class="flex justify-between text-slate-400"><span>Uang muka</span><span class="tabular-nums text-emerald-400">-{{ Num::currency($order->deposit) }}</span></div>
                <div class="flex justify-between items-baseline pt-2 border-t border-slate-700">
                    <span class="font-semibold text-slate-300">Sisa (perkiraan)</span>
                    <span class="text-2xl font-extrabold text-slate-50 tabular-nums">{{ Num::currency($order->remaining()) }}</span>
                </div>
                @if ($order->sale)
                    <p class="text-xs text-slate-400 pt-1">Dilunasi lewat <a href="{{ route('sales.show', $order->sale) }}" wire:navigate class="font-mono text-emerald-400 hover:underline">{{ $order->sale->number }}</a>.</p>
                @endif
            </section>

            @if ($order->payments->isNotEmpty())
                <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 space-y-1.5 text-xs">
                    <p class="text-[10px] uppercase font-bold text-slate-400">Riwayat uang muka</p>
                    @foreach ($order->payments as $payment)
                        <div class="flex justify-between gap-2">
                            <span class="text-slate-300">{{ $payment->kind === 'refund' ? 'Dikembalikan' : 'DP' }} · {{ $payment->method->label() }} <span class="text-slate-400">{{ $payment->paid_at->translatedFormat('d M H:i') }}</span></span>
                            <span @class(['tabular-nums', 'text-rose-400' => $payment->amount < 0, 'text-slate-100' => $payment->amount >= 0])>{{ Num::currency($payment->amount) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($order->isOpen())
                @if ($canSettle)
                    <x-primary-button size="sm" class="w-full" :href="route('pos.cashier', ['order' => $order->id])">
                        <i data-lucide="banknote" class="w-4 h-4"></i>
                        <span>Lunasi di Kasir</span>
                    </x-primary-button>
                @endif
                <div class="grid grid-cols-3 gap-1.5">
                    @foreach ([CustomerOrderStatus::New, CustomerOrderStatus::InProgress, CustomerOrderStatus::Ready] as $option)
                        <x-tab-button size="sm" :active="$order->status === $option" wire:click="setStatus('{{ $option->value }}')" class="justify-center text-xs py-1.5">{{ $option->label() }}</x-tab-button>
                    @endforeach
                </div>
                <x-secondary-button size="sm" class="w-full" wire:click="openPay">
                    <i data-lucide="hand-coins" class="w-4 h-4"></i>
                    <span>Terima Uang Muka</span>
                </x-secondary-button>
                <x-secondary-button size="sm" tone="danger" class="w-full" @click="$dispatch('open-modal', 'order-cancel')">
                    <i data-lucide="ban" class="w-4 h-4"></i>
                    <span>Batalkan Pesanan</span>
                </x-secondary-button>
            @endif
        </div>
    </div>

    <x-modal name="order-pay" max-width="md" focusable>
        <form wire:submit="pay" class="p-5 sm:p-6 space-y-4">
            <x-modal-header title="Terima uang muka" icon="hand-coins" closeable>Uang muka tunai masuk laci shift Anda sebagai kas masuk.</x-modal-header>
            <div class="grid grid-cols-2 gap-3.5">
                <div>
                    <x-input-label for="pay-amount" value="Nominal" />
                    <x-text-input wire:model="payAmount" id="pay-amount" inputmode="numeric" class="w-full font-mono" />
                    <x-input-error :messages="$errors->get('payAmount')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="pay-method" value="Metode" />
                    <x-select id="pay-method" wire:model="payMethod">
                        @foreach ($methods as $method)
                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
            <div>
                <x-input-label for="pay-ref" value="Referensi (non-tunai)" />
                <x-text-input wire:model="payReference" id="pay-ref" class="w-full" />
            </div>
            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled"><x-loading-label target="pay" loading="Menyimpan...">Simpan Uang Muka</x-loading-label></x-primary-button>
            </x-modal-actions>
        </form>
    </x-modal>

    <x-modal name="order-cancel" max-width="md" focusable>
        <form wire:submit="cancel" class="p-5 sm:p-6 space-y-4">
            <x-modal-header title="Batalkan pesanan ini?" icon="ban" tone="rose" closeable>Pesanan yang dibatalkan tidak bisa dilunasi lagi.</x-modal-header>
            @if ($order->deposit > 0)
                <x-checkbox-card wire:model="refund" label="Kembalikan uang muka ({{ Num::currency($order->deposit) }})" description="DP tunai keluar dari laci shift Anda. Matikan bila DP hangus sesuai perjanjian." />
            @endif
            <div>
                <x-input-label for="cancel-reason" value="Alasan *" />
                <x-text-input wire:model="cancelReason" id="cancel-reason" class="w-full" />
                <x-input-error :messages="$errors->get('cancelReason')" class="mt-1.5" />
            </div>
            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')">Kembali</x-secondary-button>
                <x-danger-button wire:loading.attr="disabled"><x-loading-label target="cancel" loading="Membatalkan...">Batalkan Pesanan</x-loading-label></x-danger-button>
            </x-modal-actions>
        </form>
    </x-modal>
</div>
