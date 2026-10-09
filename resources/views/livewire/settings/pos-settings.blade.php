<div class="space-y-4 sm:space-y-6">
    <p class="text-xs text-slate-400">Aturan yang dipakai layar kasir, checkout, dan struk. Berlaku untuk semua kasir.@if (app(\App\Support\CurrentOutlet::class)->isMultiOutlet()) Pajak, metode pembayaran, struk, dan QRIS di sini adalah nilai bawaan toko; tiap outlet bisa menimpanya di <x-feature-link :href="route('settings.outlets')" wire:navigate class="text-emerald-400 hover:underline">halaman Outlet</x-feature-link>.@endif</p>

    <form wire:submit="save" class="grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-4 sm:gap-6 items-start">
        <div class="space-y-4 sm:space-y-5">
            <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="wallet" class="w-4 h-4 text-slate-400"></i> Pembayaran</h3>
                <div>
                    <x-input-label value="Metode yang tersedia di kasir" />
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach (\App\Enums\PaymentMethod::cases() as $method)
                            <label @class(['flex items-center gap-2.5 min-h-[48px] px-3 rounded-lg border border-slate-800 bg-slate-950 text-xs font-semibold text-slate-200', 'opacity-70' => $method->value === 'cash', 'cursor-pointer' => $method->value !== 'cash'])>
                                <x-checkbox wire:model="paymentMethods" value="{{ $method->value }}" :disabled="$method->value === 'cash'" />
                                <i data-lucide="{{ $method->icon() }}" class="w-4 h-4 text-slate-400"></i>
                                {{ $method->shortLabel() }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1.5">Tunai selalu aktif karena dipakai untuk kembalian dan rekap laci.</p>
                </div>
                <div>
                    <x-input-label for="quickCash" value="Tombol nominal cepat (tunai)" />
                    <x-text-input wire:model="quickCash" id="quickCash" class="w-full font-mono" placeholder="10000, 20000, 50000, 100000" />
                    <p class="text-[11px] text-slate-400 mt-1">Pisahkan dengan koma. Kasir juga otomatis ditawari nominal pembulatan terdekat.</p>
                    <x-input-error :messages="$errors->get('quickCash')" class="mt-1.5" />
                </div>
                <x-checkbox-card wire:model="allowCredit" label="Izinkan kasbon (bayar sebagian)" description="Sisa pembayaran dicatat sebagai piutang atas nama pelanggan. Pelanggan wajib dipilih." />
            </section>

            <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                <div>
                    <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="qr-code" class="w-4 h-4 text-slate-400"></i> QRIS bernominal</h3>
                    <p class="text-[11px] text-slate-400 mt-1">Unggah QRIS statis toko. Saat pembayaran QRIS, aplikasi membuat QR berisi nominal tagihan sehingga pembeli tidak perlu mengetik jumlahnya. Uang tetap masuk ke rekening merchant yang sama.</p>
                </div>

                @if ($qrisMerchant)
                    <div class="flex flex-col sm:flex-row gap-4 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3.5">
                        <div class="w-32 h-32 shrink-0 rounded-lg bg-white p-1.5 [&>svg]:w-full [&>svg]:h-full mx-auto sm:mx-0">{!! $qrisPreview !!}</div>
                        <div class="min-w-0 flex-1 space-y-1.5 text-xs">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-badge color="emerald">TERBACA</x-badge>
                                @unless ($qrisSaved)
                                    <x-badge color="amber">BELUM DISIMPAN</x-badge>
                                @endunless
                            </div>
                            <p class="text-sm font-bold text-slate-100">{{ $qrisMerchant['name'] }}</p>
                            <p class="text-slate-400">{{ $qrisMerchant['city'] }}</p>
                            <p class="text-slate-400">Contoh di samping: QR dinamis Rp10.000. Coba pindai dengan aplikasi bank untuk memastikan nama merchant dan nominal muncul benar.</p>
                            <x-text-button tone="rose" size="sm" wire:click="removeQris">Hapus QRIS</x-text-button>
                        </div>
                    </div>
                @endif

                <div>
                    <x-input-label for="qrisImage" :value="$qrisMerchant ? 'Ganti dengan QRIS lain' : 'Gambar QRIS statis'" />
                    <x-file-input wire:model="qrisImage" id="qrisImage" accept="image/*" icon="qr-code" label="Pilih foto atau tangkapan layar QRIS" hint="Pastikan QR tajam dan tidak terpotong. Maks. 5 MB." />
                    <x-input-error :messages="$errors->get('qrisImage')" class="mt-1.5" />
                </div>

                <details class="group" @if ($errors->has('qrisText')) open @endif>
                    <summary class="text-[11px] font-semibold text-slate-400 hover:text-slate-200 cursor-pointer min-h-[44px] sm:min-h-0 flex items-center">Gambar tidak terbaca? Tempel teks QRIS</summary>
                    <div class="mt-2 space-y-2">
                        <x-textarea wire:model="qrisText" rows="3" class="font-mono text-[11px]" placeholder="000201010211..." />
                        <x-input-error :messages="$errors->get('qrisText')" />
                        <x-secondary-button size="sm" wire:click="applyQrisText">Periksa Teks QRIS</x-secondary-button>
                    </div>
                </details>
            </section>

            <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="percent" class="w-4 h-4 text-slate-400"></i> Pajak</h3>
                <x-checkbox-card wire:model.live="taxEnabled" label="Tambahkan pajak ke setiap transaksi" description="Pajak dihitung dari total sesudah diskon, ditambahkan di atas harga jual." />
                @if ($taxEnabled)
                    <div class="grid grid-cols-2 gap-3.5">
                        <div>
                            <x-input-label for="taxLabel" value="Nama pajak" />
                            <x-text-input wire:model="taxLabel" id="taxLabel" class="w-full" />
                            <x-input-error :messages="$errors->get('taxLabel')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="taxRate" value="Tarif (%)" />
                            <x-text-input wire:model="taxRate" id="taxRate" inputmode="decimal" class="w-full font-mono" />
                            <x-input-error :messages="$errors->get('taxRate')" class="mt-1.5" />
                        </div>
                    </div>
                @endif
            </section>

            <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="hand-platter" class="w-4 h-4 text-slate-400"></i> Service charge</h3>
                <div class="grid grid-cols-2 gap-3.5 items-start">
                    <div>
                        <x-input-label for="serviceChargeRate" value="Tarif (%)" />
                        <x-text-input wire:model="serviceChargeRate" id="serviceChargeRate" inputmode="decimal" class="w-full font-mono" placeholder="0 = tidak dipungut" />
                        <x-input-error :messages="$errors->get('serviceChargeRate')" class="mt-1.5" />
                    </div>
                    <p class="text-[11px] text-slate-400 pt-6">Dihitung dari subtotal sesudah diskon, lalu ikut dikenai pajak.</p>
                </div>
                @if (\App\Support\Features::enabled('business.order-type'))
                    <x-checkbox-card wire:model="serviceChargeDineInOnly" label="Hanya untuk makan di tempat" description="Pesanan bawa pulang dan antar tidak dikenai service charge." />
                @endif
            </section>

            <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="warehouse" class="w-4 h-4 text-slate-400"></i> Stok</h3>
                <x-checkbox-card wire:model="allowNegativeStock" label="Tetap boleh menjual saat stok di sistem habis" description="Berguna kalau barang fisik ada tapi stok belum dicatat. Stok bisa menjadi minus dan ditandai merah di halaman Stok." />
                @if (\App\Support\Features::enabled('inventory.opname'))
                    <div class="grid sm:grid-cols-3 gap-3.5 pt-1">
                        <div>
                            <x-input-label for="opnameReasonAbove" value="Opname: alasan wajib bila selisih di atas (Rp)" />
                            <x-text-input wire:model="opnameReasonAbove" id="opnameReasonAbove" inputmode="numeric" class="w-full font-mono" placeholder="0 = tidak wajib" />
                            <x-input-error :messages="$errors->get('opnameReasonAbove')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="opnameRecountPercent" value="Sarankan hitung ulang bila selisih di atas (%)" />
                            <x-text-input wire:model="opnameRecountPercent" id="opnameRecountPercent" inputmode="decimal" class="w-full font-mono" />
                            <x-input-error :messages="$errors->get('opnameRecountPercent')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="opnameAlertAbove" value="Kabari pemilik bila nilai kurang di atas (Rp)" />
                            <x-text-input wire:model="opnameAlertAbove" id="opnameAlertAbove" inputmode="numeric" class="w-full font-mono" placeholder="0 = mati" />
                            <x-input-error :messages="$errors->get('opnameAlertAbove')" class="mt-1.5" />
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-400 -mt-1.5">Persen dihitung dari stok sistem barang itu. Kabar ke pemilik dikirim saat opname diselesaikan, termasuk ke yang menyelesaikannya.</p>
                @endif
            </section>

            @if (\App\Support\Features::enabled('business.batch-expiry'))
                <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                    <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="calendar-x-2" class="w-4 h-4 text-slate-400"></i> Kedaluwarsa</h3>
                    <x-checkbox-card wire:model="blockExpiredSale" label="Tolak penjualan batch yang sudah kedaluwarsa" description="Kasir tidak bisa menjual stok yang tanggal kedaluwarsanya sudah lewat. Transaksi offline tetap diterima dan ditandai." />
                    <div class="max-w-xs">
                        <x-input-label for="expiryWarningDays" value="Peringatan kedaluwarsa (hari sebelumnya)" />
                        <x-text-input wire:model="expiryWarningDays" id="expiryWarningDays" inputmode="numeric" class="w-full" />
                        <x-input-error :messages="$errors->get('expiryWarningDays')" class="mt-1.5" />
                    </div>
                    <div class="grid grid-cols-2 gap-3.5">
                        <div>
                            <x-input-label for="nearExpiryPercent" value="Diskon otomatis ED dekat (%)" />
                            <x-text-input wire:model="nearExpiryPercent" id="nearExpiryPercent" inputmode="decimal" class="w-full font-mono" placeholder="0 = mati" />
                            <x-input-error :messages="$errors->get('nearExpiryPercent')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="nearExpiryDays" value="Berlaku bila ED ≤ (hari)" />
                            <x-text-input wire:model="nearExpiryDays" id="nearExpiryDays" inputmode="numeric" class="w-full font-mono" />
                            <x-input-error :messages="$errors->get('nearExpiryDays')" class="mt-1.5" />
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-400 -mt-1.5">Unit yang diambil dari batch yang kedaluwarsa dalam rentang ini otomatis dipotong di kasir, mis. roti H-1. Hanya untuk penjualan dalam satuan dasar.</p>
                </section>
            @endif

            @if (\App\Support\Features::enabled('business.prescription'))
                <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                    <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="file-heart" class="w-4 h-4 text-slate-400"></i> Resep obat</h3>
                    <div>
                        <x-input-label for="prescriptionMode" value="Aturan obat wajib resep" />
                        <x-select id="prescriptionMode" wire:model="prescriptionMode">
                            @foreach (\App\Support\PosSettings::PRESCRIPTION_MODES as $mode => $label)
                                <option value="{{ $mode }}">{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="grid grid-cols-[8rem_1fr] gap-3.5 items-start">
                        <div>
                            <x-input-label for="photoRetentionYears" value="Simpan foto resep" />
                            <x-text-input wire:model="photoRetentionYears" id="photoRetentionYears" inputmode="numeric" class="w-full font-mono" />
                            <x-input-error :messages="$errors->get('photoRetentionYears')" class="mt-1.5" />
                        </div>
                        <p class="text-[11px] text-slate-400 pt-6">Tahun. Foto resep yang lebih lama dihapus otomatis tiap malam; data resepnya tetap. Isi 0 untuk menyimpan selamanya. Resep apotek umumnya disimpan minimal 5 tahun.</p>
                    </div>
                    <x-checkbox-card wire:model="allowControlledDrugs" label="Izinkan narkotika & psikotropika dijual lewat kasir" description="Aplikasi tidak membuat laporan SIPNAP dan tidak menjamin kepatuhan regulasi farmasi. Nyalakan hanya jika apotek Anda mencatat dan melaporkannya sendiri." />
                </section>
            @endif

            <section class="bg-slate-900/80 border border-slate-800/80 rounded-xl p-4 sm:p-5 space-y-3.5">
                <h3 class="text-sm font-bold text-slate-100 flex items-center gap-2"><i data-lucide="printer" class="w-4 h-4 text-slate-400"></i> Struk</h3>
                <div>
                    <x-input-label value="Lebar kertas printer thermal" />
                    <x-segmented class="w-full sm:w-auto sm:inline-flex [&>*]:flex-1">
                        <x-tab-button :active="$receiptWidth === '58'" wire:click="$set('receiptWidth', '58')">58 mm</x-tab-button>
                        <x-tab-button :active="$receiptWidth === '80'" wire:click="$set('receiptWidth', '80')">80 mm</x-tab-button>
                    </x-segmented>
                </div>
                <div>
                    <x-input-label for="receiptHeader" value="Teks tambahan di atas struk" />
                    <x-textarea wire:model.live.debounce.500ms="receiptHeader" id="receiptHeader" rows="2" placeholder="Mis. Buka setiap hari 07.00–21.00" />
                    <x-input-error :messages="$errors->get('receiptHeader')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="receiptFooter" value="Teks di bawah struk" />
                    <x-textarea wire:model.live.debounce.500ms="receiptFooter" id="receiptFooter" rows="2" placeholder="Mis. Barang yang sudah dibeli tidak dapat dikembalikan" />
                    <x-input-error :messages="$errors->get('receiptFooter')" class="mt-1.5" />
                </div>
                <x-checkbox-card wire:model="autoPrint" label="Cetak struk otomatis setelah bayar (laptop/PC)" description="Langsung membuka dialog cetak. Di tablet & ponsel kasir tetap menekan tombol Cetak Struk." />
                <p class="text-[11px] text-slate-400">Nama, alamat, telepon, dan logo toko diambil dari Profil Perusahaan.</p>
            </section>

            <div class="flex justify-end">
                <x-primary-button wire:loading.attr="disabled" class="w-full sm:w-auto">
                    <x-loading-label target="save" loading="Menyimpan...">Simpan Pengaturan Kasir</x-loading-label>
                </x-primary-button>
            </div>
        </div>

        {{-- Pratinjau struk: titik fokus halaman --}}
        <aside class="lg:sticky lg:top-4 space-y-2">
            <p class="text-xs font-semibold text-slate-400">Pratinjau struk</p>
            <div class="rounded-2xl bg-[#e2e8f0] p-4 shadow-lg shadow-slate-950/30 flex justify-center">
                <div @class(['bg-white text-black font-mono text-[10px] leading-snug p-3 shadow', 'w-[58mm]' => $receiptWidth === '58', 'w-[72mm]' => $receiptWidth === '80'])>
                    <p class="text-center font-bold text-[12px]">{{ \App\Support\Branding::companyName() }}</p>
                    @if ($address = \App\Models\Setting::get('company_address'))
                        <p class="text-center">{{ $address }}</p>
                    @endif
                    @if ($receiptHeader)
                        <p class="text-center whitespace-pre-line">{{ $receiptHeader }}</p>
                    @endif
                    <p class="border-t border-dashed border-black my-1.5"></p>
                    <p>Kopi Susu</p>
                    <p class="flex justify-between"><span>2 x 18.000</span><span>36.000</span></p>
                    <p>Roti Bakar</p>
                    <p class="flex justify-between"><span>1 x 15.000</span><span>15.000</span></p>
                    <p class="border-t border-dashed border-black my-1.5"></p>
                    <p class="flex justify-between font-bold"><span>TOTAL</span><span>Rp51.000</span></p>
                    <p class="flex justify-between"><span>Tunai</span><span>100.000</span></p>
                    <p class="flex justify-between"><span>Kembali</span><span>49.000</span></p>
                    @if ($receiptFooter)
                        <p class="border-t border-dashed border-black my-1.5"></p>
                        <p class="text-center whitespace-pre-line">{{ $receiptFooter }}</p>
                    @endif
                </div>
            </div>
        </aside>
    </form>
</div>
