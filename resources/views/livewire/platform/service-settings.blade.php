@php
    use App\Support\NumberFormatter as Num;
@endphp

<div class="space-y-4 sm:space-y-6">
    <p class="text-xs text-slate-400">Aturan pendaftaran berlaku untuk toko yang baru mendaftar. Perubahan batas paket langsung berlaku untuk semua toko di paket itu, tetapi data yang sudah ada tidak dihapus.</p>

    <form wire:submit="save" class="grid lg:grid-cols-2 gap-4 sm:gap-6 items-start">
        <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
            <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="user-plus" class="w-4 h-4 text-slate-400"></i> Pendaftaran</h3>
            <x-checkbox-card wire:model="registrationOpen" label="Buka pendaftaran toko baru" description="Kalau dimatikan, halaman daftar di web dan aplikasi mobile menolak pendaftaran. Toko yang sudah ada tetap bisa masuk." />
            <div>
                <x-input-label for="trialDays" value="Lama uji coba (hari) *" />
                <x-text-input wire:model="trialDays" id="trialDays" type="number" min="1" max="90" class="w-full sm:w-40 tabular-nums" />
                <p class="text-[11px] text-slate-400 mt-1">Dihitung sejak toko mendaftar. Perpanjangan per toko diatur dari halaman Toko Pelanggan.</p>
                <x-input-error :messages="$errors->get('trialDays')" class="mt-1.5" />
            </div>
        </section>

        <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
            <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="headset" class="w-4 h-4 text-slate-400"></i> Bantuan & Dukungan Pelanggan</h3>
            <div>
                <x-input-label for="supportContact" value="Kontak WhatsApp / Helpdesk" />
                <x-text-input wire:model="supportContact" id="supportContact" class="w-full" placeholder="Mis. WhatsApp 0812-3456-7890" />
                <p class="text-[11px] text-slate-400 mt-1">Ditampilkan kepada pemilik toko jika membutuhkan bantuan layanan.</p>
                <x-input-error :messages="$errors->get('supportContact')" class="mt-1.5" />
            </div>
            <div class="p-3 rounded-lg bg-slate-800/50 border border-slate-700/50 text-[11px] text-slate-300 space-y-1">
                <div class="font-semibold text-emerald-400 flex items-center gap-1.5">
                    <i data-lucide="badge-check" class="w-3.5 h-3.5"></i> Pembayaran Otomatis Aktif (SumoPod QRIS)
                </div>
                <p class="text-slate-400">Pembayaran perpanjangan langganan diproses otomatis secara instan via Payment Gateway SumoPod (QRIS dinamis), sehingga instruksi rekening manual sudah tidak diperlukan lagi.</p>
            </div>
        </section>

        <div class="lg:col-span-2">
            <x-primary-button wire:loading.attr="disabled">
                <x-loading-label target="save" loading="Menyimpan...">Simpan Pengaturan</x-loading-label>
            </x-primary-button>
        </div>
    </form>

    <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl overflow-hidden">
        <div class="p-4 border-b border-slate-800 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="layers" class="w-4 h-4 text-slate-400"></i> Paket Langganan</h3>
                <p class="text-[11px] text-slate-400 mt-0.5">Kosongkan batas untuk tanpa batas. Harga per bulan dan per tahun otomatis digunakan pada checkout SumoPod QRIS.</p>
            </div>
            <x-primary-button size="sm" type="button" wire:click="openCreatePlan">
                <i data-lucide="plus" class="w-4 h-4"></i> Tambah Paket
            </x-primary-button>
        </div>

        <x-table class="!rounded-none !border-0 !shadow-none">
            <x-slot:header>
                <tr>
                    <x-table.th>Paket</x-table.th>
                    <x-table.th align="right">Harga / Bulan</x-table.th>
                    <x-table.th align="right">Harga / Tahun</x-table.th>
                    <x-table.th align="right">Pengguna</x-table.th>
                    <x-table.th align="right">Produk</x-table.th>
                    <x-table.th align="right">Toko</x-table.th>
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($plans as $key => $plan)
                    <x-table.tr wire:key="plan-{{ $key }}">
                        <x-table.td>
                            <span class="font-medium text-slate-100">{{ $plan['label'] }}</span>
                            <div class="font-mono text-[11px] text-slate-400">{{ $key }}</div>
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-300">
                            @if ($plan['price'] !== null)
                                @if (!empty($plan['monthly_discount']))
                                    @php $pct = round(($plan['monthly_discount'] / $plan['price']) * 100); @endphp
                                    <div class="line-through text-xs text-slate-400">{{ Num::currency($plan['price']) }}</div>
                                    <div class="font-bold text-emerald-400">{{ Num::currency(max(0, $plan['price'] - $plan['monthly_discount'])) }}</div>
                                    <span class="inline-block text-[10px] font-semibold text-amber-400 bg-amber-500/10 px-1 rounded">Diskon {{ $pct }}% (-{{ Num::currency($plan['monthly_discount']) }})</span>
                                @else
                                    <div class="font-semibold text-slate-200">{{ Num::currency($plan['price']) }}</div>
                                @endif
                                @if ($key === 'lifetime')
                                    <span class="inline-block text-[10px] font-bold text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 px-1.5 py-0.5 rounded mt-0.5">Sekali Bayar</span>
                                @endif
                            @else
                                <span>-</span>
                            @endif
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-300">
                            @if ($key === 'lifetime')
                                <span class="text-xs text-indigo-300 font-medium">Permanen</span>
                            @elseif (($plan['yearly_price'] ?? null) !== null)
                                @if (!empty($plan['yearly_discount']))
                                    @php $pct = round(($plan['yearly_discount'] / $plan['yearly_price']) * 100); @endphp
                                    <div class="line-through text-xs text-slate-400">{{ Num::currency($plan['yearly_price']) }}</div>
                                    <div class="font-bold text-emerald-400">{{ Num::currency(max(0, $plan['yearly_price'] - $plan['yearly_discount'])) }}</div>
                                    <span class="inline-block text-[10px] font-semibold text-amber-400 bg-amber-500/10 px-1 rounded">Diskon {{ $pct }}% (-{{ Num::currency($plan['yearly_discount']) }})</span>
                                @else
                                    <span>{{ Num::currency($plan['yearly_price']) }}</span>
                                @endif
                            @else
                                <span>-</span>
                            @endif
                        </x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-300">{{ $plan['max_users'] ?? 'Tanpa batas' }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-300">{{ $plan['max_products'] !== null ? Num::quantity($plan['max_products']) : 'Tanpa batas' }}</x-table.td>
                        <x-table.td align="right" class="tabular-nums text-slate-300">{{ (int) ($planUsage[$key] ?? 0) }}</x-table.td>
                        <x-table.td align="right">
                            <div class="flex items-center justify-end gap-1">
                                <x-icon-button icon="pencil" :label="'Edit paket '.$plan['label']" tone="edit" wire:click="openEditPlan('{{ $key }}')" />
                                @if ($key !== \App\Models\Tenant::PLAN_TRIAL && ! ($planUsage[$key] ?? 0))
                                    <x-icon-button icon="trash-2" :label="'Hapus paket '.$plan['label']" tone="danger" wire:click="confirmDeletePlan('{{ $key }}')" />
                                @endif
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    </section>

    <x-record-form-modal name="plan-form" :title="$editingPlan ? 'Edit Paket' : 'Tambah Paket'" subtitle="Nama, harga, diskon, dan batas paket" icon="layers" max-width="lg" close-action="closePlanModal">
        <form wire:submit="savePlan" class="space-y-4">
            <div>
                <x-input-label for="planKey" value="Kode Paket *" />
                <x-text-input wire:model="planKey" id="planKey" class="w-full font-mono" placeholder="mis. premium" :disabled="$editingPlan !== null" />
                <p class="mt-1 text-[11px] text-slate-400">Disimpan di data toko, jadi tidak bisa diubah setelah dibuat.</p>
                <x-input-error :messages="$errors->get('planKey')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label for="planLabel" value="Nama Paket *" />
                <x-text-input wire:model="planLabel" id="planLabel" class="w-full" />
                <x-input-error :messages="$errors->get('planLabel')" class="mt-1.5" />
            </div>

            {{-- Pengaturan Harga Paket --}}
            <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-3.5 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-200 flex items-center gap-1.5">
                        <i data-lucide="{{ $planKey === 'lifetime' ? 'crown' : 'calendar' }}" class="w-3.5 h-3.5 {{ $planKey === 'lifetime' ? 'text-amber-400' : 'text-emerald-400' }}"></i>
                        {{ $planKey === 'lifetime' ? 'Harga Paket Lifetime (Sekali Bayar)' : 'Langganan Bulanan' }}
                    </span>
                    @if (filled($planMonthlyFinalPrice) && (int) $planPrice > 0 && (int) $planMonthlyFinalPrice < (int) $planPrice)
                        <span class="text-[11px] font-semibold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded-full">
                            Promo: {{ Num::currency((int) $planMonthlyFinalPrice) }} (Diskon {{ $planMonthlyDiscountPercent }}%)
                        </span>
                    @endif
                </div>

                <div>
                    <x-input-label for="planPrice" :value="$planKey === 'lifetime' ? 'Harga Normal Sekali Bayar (Rp)' : 'Harga Normal per Bulan (Rp)'" />
                    <x-text-input wire:model.live.debounce.300ms="planPrice" id="planPrice" inputmode="numeric" class="w-full tabular-nums" :placeholder="$planKey === 'lifetime' ? 'Mis. 499000' : 'Mis. 20000'" />
                    <x-input-error :messages="$errors->get('planPrice')" class="mt-1.5" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1 border-t border-slate-800/60">
                    <div>
                        <x-input-label for="planMonthlyFinalPrice" value="Nilai Akhir / Promo (Rp)" />
                        <x-text-input wire:model.live.debounce.300ms="planMonthlyFinalPrice" id="planMonthlyFinalPrice" inputmode="numeric" class="w-full tabular-nums" :placeholder="$planKey === 'lifetime' ? 'Mis. 399000' : 'Mis. 15000'" />
                        <p class="text-[11px] text-slate-400 mt-1">Harga yang dibayar toko</p>
                        <x-input-error :messages="$errors->get('planMonthlyFinalPrice')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="planMonthlyDiscountPercent" value="Atau Diskon (%)" />
                        <div class="relative">
                            <x-text-input wire:model.live.debounce.300ms="planMonthlyDiscountPercent" id="planMonthlyDiscountPercent" type="number" min="0" max="100" class="w-full tabular-nums pr-8" placeholder="Mis. 20" />
                            <span class="absolute inset-y-0 right-3 flex items-center text-xs font-bold text-slate-400 pointer-events-none">%</span>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Persentase potongan</p>
                        <x-input-error :messages="$errors->get('planMonthlyDiscountPercent')" class="mt-1.5" />
                    </div>
                </div>
            </div>

            @if ($planKey !== 'lifetime')
                {{-- Pengaturan Paket Tahunan --}}
                <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-3.5 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-200 flex items-center gap-1.5">
                            <i data-lucide="calendar-range" class="w-3.5 h-3.5 text-emerald-400"></i> Langganan Tahunan
                        </span>
                        @if (filled($planYearlyFinalPrice) && (int) $planYearlyPrice > 0 && (int) $planYearlyFinalPrice < (int) $planYearlyPrice)
                            <span class="text-[11px] font-semibold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded-full">
                                Promo: {{ Num::currency((int) $planYearlyFinalPrice) }} (Diskon {{ $planYearlyDiscountPercent }}%)
                            </span>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="planYearlyPrice" value="Harga Normal per Tahun (Rp)" />
                        <x-text-input wire:model.live.debounce.300ms="planYearlyPrice" id="planYearlyPrice" inputmode="numeric" class="w-full tabular-nums" placeholder="Mis. 199000" />
                        <x-input-error :messages="$errors->get('planYearlyPrice')" class="mt-1.5" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1 border-t border-slate-800/60">
                        <div>
                            <x-input-label for="planYearlyFinalPrice" value="Nilai Akhir / Promo (Rp)" />
                            <x-text-input wire:model.live.debounce.300ms="planYearlyFinalPrice" id="planYearlyFinalPrice" inputmode="numeric" class="w-full tabular-nums" placeholder="Mis. 159000" />
                            <p class="text-[11px] text-slate-400 mt-1">Harga yang dibayar toko</p>
                            <x-input-error :messages="$errors->get('planYearlyFinalPrice')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="planYearlyDiscountPercent" value="Atau Diskon (%)" />
                            <div class="relative">
                                <x-text-input wire:model.live.debounce.300ms="planYearlyDiscountPercent" id="planYearlyDiscountPercent" type="number" min="0" max="100" class="w-full tabular-nums pr-8" placeholder="Mis. 20" />
                                <span class="absolute inset-y-0 right-3 flex items-center text-xs font-bold text-slate-400 pointer-events-none">%</span>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1">Persentase potongan</p>
                            <x-input-error :messages="$errors->get('planYearlyDiscountPercent')" class="mt-1.5" />
                        </div>
                    </div>
                </div>
            @endif
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="planMaxUsers" value="Batas Pengguna" />
                    <x-text-input wire:model="planMaxUsers" id="planMaxUsers" inputmode="numeric" class="w-full tabular-nums" placeholder="Tanpa batas" />
                    <x-input-error :messages="$errors->get('planMaxUsers')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="planMaxProducts" value="Batas Produk" />
                    <x-text-input wire:model="planMaxProducts" id="planMaxProducts" inputmode="numeric" class="w-full tabular-nums" placeholder="Tanpa batas" />
                    <x-input-error :messages="$errors->get('planMaxProducts')" class="mt-1.5" />
                </div>
            </div>

            <x-modal-actions>
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled">
                    <x-loading-label target="savePlan" loading="Menyimpan...">Simpan Paket</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>

    <x-confirm-delete-modal title="Hapus paket ini?" description="Paket yang belum dipakai toko mana pun akan hilang dari pilihan paket." />
</div>
