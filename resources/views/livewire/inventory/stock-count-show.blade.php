@php
    use App\Enums\StockCountReason;
    use App\Enums\StockCountScope;
    use App\Enums\StockCountStatus;
    use App\Livewire\Inventory\StockCountShow;
    use App\Models\StockCount;
    use App\Models\StockCountSerial;
    use App\Services\Pos\StockCountPoster;
    use App\Services\Pos\StockCountVariance;
    use App\Support\NumberFormatter as Num;

    $count = $stockCount;
    $status = $count->status;
    $progress = $this->progress;
    $percent = $progress['items'] > 0 ? (int) floor($progress['counted'] * 100 / $progress['items']) : 0;
    $step = match (true) {
        in_array($status, [StockCountStatus::Posted, StockCountStatus::Posting, StockCountStatus::Cancelled], true) => 3,
        $status === StockCountStatus::Review => 2,
        default => 1,
    };
    $flagLabels = [
        StockCountVariance::FLAG_INACTIVE => 'nonaktif',
        StockCountVariance::FLAG_DELETED => 'dihapus',
        StockCountVariance::FLAG_NOT_TRACKED => 'tidak dilacak lagi',
        StockCountVariance::FLAG_MANUAL_MOVEMENT => 'ada mutasi setelah dihitung',
        StockCountVariance::FLAG_SPLIT_ENTRIES => 'dihitung berjarak > 2 jam',
        StockCountVariance::FLAG_RECOUNT_SUGGESTED => 'selisih besar',
        StockCountPoster::FLAG_BATCH_SHIFTED => 'batch habis setelah dihitung',
        StockCountVariance::FLAG_PENDING_HELD_ORDER => 'ada transaksi tertunda',
    ];
@endphp

<div class="space-y-4 sm:space-y-6" @if ($status === StockCountStatus::Posting) wire:poll.3s="pollPosting" @endif>
    {{-- Kepala dokumen --}}
    <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('inventory.opname') }}" wire:navigate class="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-slate-200 min-h-[44px] sm:min-h-0">
                <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Semua opname
            </a>
            <div class="flex flex-wrap items-center gap-2 mt-1">
                <h2 class="font-mono text-lg font-bold text-slate-100">{{ $count->number }}</h2>
                <x-badge :color="$status->color()">{{ $status->label() }}</x-badge>
                @if ($count->hold_adjustments && $count->isOpen())
                    <x-badge color="slate">Stok masuk/keluar ditahan</x-badge>
                @endif
            </div>
            <p class="text-xs text-slate-400 mt-1">
                {{ $count->scope->label() }}
                @if ($multiOutlet) · {{ $count->outlet?->name }} @endif
                · dimulai {{ $count->started_at?->translatedFormat('d M Y H:i') }} oleh {{ $count->creator?->name ?? 'Pengguna terhapus' }}
                @if ($count->note) · {{ $count->note }} @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-secondary-button size="sm" type="button" onclick="window.open('{{ route('inventory.opname.sheet', $count) }}', '_blank')">
                <i data-lucide="printer" class="w-4 h-4"></i> <span>Lembar Hitung</span>
            </x-secondary-button>
            @if ($editable && $this->isPro())
                <x-secondary-button size="sm" type="button" wire:click="downloadTemplate">
                    <i data-lucide="file-spreadsheet" class="w-4 h-4"></i> <span>Template Excel</span>
                </x-secondary-button>
                <x-secondary-button size="sm" type="button" wire:click="openImport">
                    <i data-lucide="upload" class="w-4 h-4"></i> <span>Impor Hitungan</span>
                </x-secondary-button>
            @endif
            @if ($status === StockCountStatus::Posted)
                <x-secondary-button size="sm" type="button" onclick="window.open('{{ route('inventory.opname.print', $count) }}', '_blank')">
                    <i data-lucide="file-text" class="w-4 h-4"></i> <span>Berita Acara</span>
                </x-secondary-button>
            @endif
            <x-secondary-button size="sm" type="button" wire:click="export('xlsx')">
                <i data-lucide="download" class="w-4 h-4"></i> <span>Ekspor Excel</span>
            </x-secondary-button>
            @if ($canManage && $editable)
                <x-danger-button size="sm" type="button" @click="$dispatch('open-modal', 'count-cancel')">Batalkan</x-danger-button>
            @endif
        </div>
    </div>

    @if ($otherOutlet)
        <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-3.5 text-xs text-amber-300 flex flex-wrap items-center gap-2" role="status">
            <i data-lucide="store" class="w-4 h-4 shrink-0"></i>
            <span class="flex-1 min-w-[200px]">Opname ini milik outlet <strong>{{ $count->outlet?->name }}</strong>, sedangkan outlet aktif Anda <strong>{{ app(\App\Support\CurrentOutlet::class)->get()?->name }}</strong>. Halaman ini hanya bisa dilihat; pindah ke outlet {{ $count->outlet?->name }} untuk menghitung atau menyelesaikannya.</span>
            @if (app(\App\Support\CurrentOutlet::class)->canAccess($count->outlet_id))
                <x-secondary-button size="sm" type="button" wire:click="switchToDocumentOutlet">
                    <x-loading-label target="switchToDocumentOutlet" loading="Memindahkan...">Pindah ke {{ $count->outlet?->name }}</x-loading-label>
                </x-secondary-button>
            @endif
        </div>
    @endif

    {{-- Stepper --}}
    <ol class="grid grid-cols-3 gap-2 text-xs" aria-label="Tahap opname">
        @foreach ([1 => 'Hitung', 2 => 'Periksa', 3 => $status === StockCountStatus::Cancelled ? 'Dibatalkan' : 'Selesai'] as $number => $label)
            <li @class([
                'flex items-center gap-2 rounded-lg border px-3 py-2',
                'border-emerald-500/40 bg-emerald-500/10 text-emerald-300' => $number === $step,
                'border-slate-800 text-slate-300' => $number < $step,
                'border-slate-800 text-slate-500' => $number > $step,
            ]) @if ($number === $step) aria-current="step" @endif>
                <span @class(['inline-flex w-5 h-5 items-center justify-center rounded-full text-[11px] font-bold', 'bg-emerald-500 text-slate-950' => $number <= $step, 'bg-slate-800 text-slate-400' => $number > $step])>
                    @if ($number < $step) <i data-lucide="check" class="w-3 h-3"></i> @else {{ $number }} @endif
                </span>
                <span class="font-semibold">{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    @if ($status === StockCountStatus::Posting)
        @php [$done, $total] = $postingProgress; $postPercent = $total > 0 ? min(100, (int) floor($done * 100 / $total)) : 0; @endphp
        <div class="rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-800 p-5 shadow-lg space-y-3">
            <p class="text-sm font-semibold text-slate-100">Stok sedang disesuaikan…</p>
            <div class="h-2 rounded-full bg-slate-800 overflow-hidden" role="progressbar" aria-valuenow="{{ $postPercent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progres pemrosesan">
                <div class="h-full bg-emerald-500 transition-all" style="width: {{ $postPercent }}%"></div>
            </div>
            <p class="text-xs text-slate-400">{{ Num::quantity($done) }} dari {{ Num::quantity($total) }} barang diproses. Toko tetap bisa berjualan. Halaman ini diperbarui otomatis.</p>
            @if ($canManage && $count->updated_at->lt(now()->subMinutes(10)))
                <x-secondary-button size="sm" type="button" wire:click="resumePosting">
                    <x-loading-label target="resumePosting" loading="Melanjutkan...">Lanjutkan pemrosesan</x-loading-label>
                </x-secondary-button>
            @endif
        </div>
    @elseif ($status === StockCountStatus::Posted || $status === StockCountStatus::Cancelled)
        {{-- Selesai / dibatalkan --}}
        <div class="rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-800 p-5 shadow-lg">
            @if ($status === StockCountStatus::Cancelled)
                <p class="text-sm font-semibold text-slate-100">Opname dibatalkan, stok tidak berubah.</p>
                <p class="text-xs text-slate-400 mt-1">Oleh {{ $count->canceller?->name ?? 'Pengguna terhapus' }} pada {{ $count->cancelled_at?->translatedFormat('d M Y H:i') }}@if ($count->cancel_reason). Alasan: {{ $count->cancel_reason }}@endif</p>
            @else
                @php $summary = $count->summary ?? []; @endphp
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <p class="text-[11px] text-slate-400">Barang berubah</p>
                        <p class="text-xl font-bold tabular-nums text-slate-100">{{ Num::quantity($summary['changed'] ?? 0) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400">Kurang</p>
                        <p class="text-xl font-bold tabular-nums text-rose-400">{{ Num::currency($summary['shortage_value'] ?? 0) }}</p>
                        <p class="text-[11px] text-slate-400 tabular-nums">{{ Num::quantity($summary['shortage_qty'] ?? 0) }} unit</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400">Lebih</p>
                        <p class="text-xl font-bold tabular-nums text-emerald-400">{{ Num::currency($summary['surplus_value'] ?? 0) }}</p>
                        <p class="text-[11px] text-slate-400 tabular-nums">{{ Num::quantity($summary['surplus_qty'] ?? 0) }} unit</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400">Diselesaikan</p>
                        <p class="text-sm font-semibold text-slate-100">{{ $count->posted_at?->translatedFormat('d M Y H:i') }}</p>
                        <p class="text-[11px] text-slate-400">{{ $count->poster?->name ?? 'Pengguna terhapus' }} · belum dihitung: {{ $count->uncounted_policy === StockCount::UNCOUNTED_ZERO ? 'dianggap habis' : 'stoknya dibiarkan' }}</p>
                    </div>
                </div>
                @if ($canManage && ! $otherOutlet)
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-secondary-button size="sm" type="button" wire:click="createCorrection">
                            <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                            <x-loading-label target="createCorrection" loading="Menyiapkan...">Buat opname koreksi</x-loading-label>
                        </x-secondary-button>
                        <span class="text-[11px] text-slate-400">Opname yang sudah selesai tidak bisa dibatalkan. Hitung ulang barang yang sama di dokumen baru.</span>
                    </div>
                @endif
            @endif
        </div>

        @if ($this->corrections->isNotEmpty())
            <div class="rounded-xl border border-slate-800 bg-slate-900/60 p-4 space-y-2">
                <h3 class="text-sm font-semibold text-slate-100">Koreksi susulan</h3>
                <p class="text-xs text-slate-400">Transaksi offline yang terjadi sebelum barang dihitung tapi baru masuk setelah opname selesai. Stoknya dikembalikan supaya tidak terpotong dua kali.</p>
                <ul class="divide-y divide-slate-800/60 text-xs">
                    @foreach ($this->corrections as $correction)
                        <li class="flex items-center justify-between gap-3 py-2" wire:key="correction-{{ $correction->id }}">
                            <span class="text-slate-200">{{ $correction->item?->product?->name }} · {{ $correction->sale?->number }}</span>
                            <span class="tabular-nums text-emerald-400">+{{ Num::quantity((float) $correction->quantity) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @elseif ($view === 'review' && $review)
        {{-- Periksa: dampak sebagai titik fokus --}}
        @php $impact = $this->impact ?? []; @endphp
        <div class="rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-800 p-5 shadow-lg space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <p class="text-[11px] text-slate-400">Barang berubah</p>
                    <p class="text-xl font-bold tabular-nums text-slate-100">{{ Num::quantity($impact['changed'] ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-slate-400">Kurang</p>
                    <p class="text-xl font-bold tabular-nums text-rose-400">{{ Num::currency($impact['shortage_value'] ?? 0) }}</p>
                    <p class="text-[11px] text-slate-400 tabular-nums">{{ Num::quantity($impact['shortage_qty'] ?? 0) }} unit</p>
                </div>
                <div>
                    <p class="text-[11px] text-slate-400">Lebih</p>
                    <p class="text-xl font-bold tabular-nums text-emerald-400">{{ Num::currency($impact['surplus_value'] ?? 0) }}</p>
                    <p class="text-[11px] text-slate-400 tabular-nums">{{ Num::quantity($impact['surplus_qty'] ?? 0) }} unit</p>
                </div>
                <div>
                    <p class="text-[11px] text-slate-400">Belum dihitung</p>
                    <p class="text-xl font-bold tabular-nums text-amber-400">{{ Num::quantity($impact['uncounted'] ?? 0) }}</p>
                    <p class="text-[11px] text-slate-400 tabular-nums">stok {{ Num::quantity($impact['uncounted_qty'] ?? 0) }} · {{ Num::currency($impact['uncounted_value'] ?? 0) }}</p>
                </div>
            </div>
            @if (($impact['uncounted'] ?? 0) > 0)
                <div>
                    <p class="text-xs text-slate-300 mb-1.5">Barang yang belum dihitung:</p>
                    <x-segmented class="w-full sm:w-auto sm:inline-flex [&>*]:flex-1" aria-label="Barang yang belum dihitung">
                        <x-tab-button size="sm" :active="$uncountedPolicy === StockCount::UNCOUNTED_KEEP" wire:click="$set('uncountedPolicy', '{{ StockCount::UNCOUNTED_KEEP }}')">Biarkan stoknya</x-tab-button>
                        <x-tab-button size="sm" :active="$uncountedPolicy === StockCount::UNCOUNTED_ZERO" wire:click="$set('uncountedPolicy', '{{ StockCount::UNCOUNTED_ZERO }}')">Anggap habis (0)</x-tab-button>
                    </x-segmented>
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-2">
                <x-secondary-button size="sm" type="button" wire:click="reopen">Kembali menghitung</x-secondary-button>
                <x-secondary-button size="sm" type="button" wire:click="refreshImpact">
                    <x-loading-label target="refreshImpact" loading="Menghitung...">Hitung ulang selisih</x-loading-label>
                </x-secondary-button>
                <x-primary-button size="sm" type="button" wire:click="confirmPost">
                    <x-loading-label target="confirmPost" loading="Memeriksa...">Selesaikan &amp; sesuaikan stok</x-loading-label>
                </x-primary-button>
            </div>
        </div>

        @foreach ($this->warnings as $warning)
            <div @class([
                'rounded-xl border p-3.5 text-xs flex items-start gap-2',
                'border-amber-500/30 bg-amber-500/5 text-amber-300' => $warning['tone'] === 'amber',
                'border-sky-500/30 bg-sky-500/5 text-sky-300' => $warning['tone'] === 'sky',
            ])>
                <i data-lucide="{{ $warning['icon'] }}" class="w-4 h-4 shrink-0 mt-0.5"></i>
                <span>{{ $warning['message'] }}</span>
            </div>
        @endforeach
    @else
        {{-- Hitung: kolom scan sebagai titik fokus --}}
        <div class="rounded-2xl border border-slate-800 bg-gradient-to-b from-slate-900 to-slate-800 p-4 sm:p-5 shadow-lg space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-slate-300">
                    Dihitung <span class="font-bold tabular-nums text-slate-100">{{ Num::quantity($progress['counted']) }}</span> dari {{ Num::quantity($progress['items']) }}
                    · Belum <span class="tabular-nums">{{ Num::quantity($progress['uncounted']) }}</span>
                    @if ($canSeeSystem) · Ada selisih <span class="tabular-nums">{{ Num::quantity($progress['changed']) }}</span> @endif
                </p>
                <span class="text-[11px] tabular-nums text-slate-400">{{ $percent }}%</span>
            </div>
            <div class="h-1.5 rounded-full bg-slate-800 overflow-hidden" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progres hitung">
                <div class="h-full bg-emerald-500 transition-all" style="width: {{ $percent }}%"></div>
            </div>

            @if ($editable)
                <form wire:submit="scan" x-data="{ last: '', at: 0 }" @submit="const value = $refs.code.value.trim(); if (value === last && Date.now() - at < 300) { $event.preventDefault(); $event.stopImmediatePropagation(); $refs.code.value = ''; return } last = value; at = Date.now()" class="flex gap-2">
                    <label for="count-scan" class="sr-only">Scan barcode atau ketik SKU</label>
                    <x-text-input x-ref="code" wire:model="code" id="count-scan" autofocus autocomplete="off" class="flex-1 text-base font-mono" placeholder="Scan barcode, SKU, atau IMEI lalu Enter" x-init="$nextTick(() => $el.focus())" @focus-scan.window="$el.focus()" />
                    <x-primary-button>
                        <x-loading-label target="scan" loading="...">+1</x-loading-label>
                    </x-primary-button>
                </form>
                @if ($scanMessage)
                    <div @class([
                        'rounded-lg px-3 py-2 text-xs flex flex-wrap items-center gap-2',
                        'bg-emerald-500/10 text-emerald-300' => $scanTone === 'emerald',
                        'bg-amber-500/10 text-amber-300' => $scanTone === 'amber',
                        'bg-rose-500/10 text-rose-300' => $scanTone === 'rose',
                    ]) role="status">
                        <span>{{ $scanMessage }}</span>
                        @if ($pendingAddProductId)
                            <x-text-button tone="emerald" wire:click="addPending">Tambahkan ke opname ini</x-text-button>
                        @endif
                    </div>
                @endif
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-400">
                    <span>Setiap scan menambah 1 satuan yang di-scan (barcode dus = 1 dus). Barang di beberapa rak: hitung semua lokasinya berurutan.</span>
                    <x-text-button wire:click="openUnknown">Catat barang tak dikenal</x-text-button>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-2 pt-1">
                @if (! $otherOutlet && $status === StockCountStatus::Review && $canManage)
                    <x-secondary-button size="sm" type="button" wire:click="showView('review')">Ke tahap Periksa</x-secondary-button>
                @elseif (! $otherOutlet && $status === StockCountStatus::Counting)
                    @if ($canManage)
                        <x-primary-button size="sm" type="button" wire:click="submit">
                            <x-loading-label target="submit" loading="Menyiapkan...">Lanjut ke Periksa</x-loading-label>
                        </x-primary-button>
                    @else
                        <x-primary-button size="sm" type="button" wire:click="submit">
                            <x-loading-label target="submit" loading="Mengirim...">Selesai menghitung</x-loading-label>
                        </x-primary-button>
                    @endif
                @endif
            </div>
        </div>
    @endif

    {{-- Filter --}}
    <div class="flex flex-col sm:flex-row sm:items-center gap-2">
        @unless ($review)
            <x-segmented class="w-full sm:w-auto overflow-x-auto [&>*]:shrink-0" aria-label="Saring barang">
                @foreach (StockCountShow::FILTERS as $key => $label)
                    @continue($key === 'variance' && ! $canSeeSystem)
                    <x-tab-button size="sm" :active="$filter === $key" wire:click="$set('filter', '{{ $key }}')">{{ $label }}</x-tab-button>
                @endforeach
            </x-segmented>
        @endunless
        <x-search-input wire:model.live.debounce.300ms="search" placeholder="Cari nama atau SKU..." class="sm:max-w-xs" />
        @if ($this->categories->count() > 1)
            <x-select variant="filter" wire:model.live="categoryId" aria-label="Saring kategori" class="sm:w-48">
                <option value="">Semua kategori</option>
                @foreach ($this->categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-select>
        @endif
    </div>

    {{-- Tabel barang --}}
    @if ($items->isEmpty())
        <div class="bg-slate-900/80 rounded-xl border border-slate-800/80">
            <x-empty-state icon="clipboard-check" :title="$review ? 'Tidak ada selisih' : 'Tidak ada barang yang cocok'" :description="$review ? 'Semua barang yang dihitung sama dengan stok sistem. Opname bisa langsung diselesaikan.' : 'Ubah saringan atau kata kunci pencarian.'" />
        </div>
    @else
        <x-table :pagination="$items">
            <x-slot:header>
                <tr>
                    <x-table.th>Barang</x-table.th>
                    @if ($canSeeSystem)
                        <x-table.th align="right">Stok sistem</x-table.th>
                    @endif
                    <x-table.th align="right">Hasil hitung</x-table.th>
                    @if ($canSeeSystem)
                        <x-table.th align="right">Selisih</x-table.th>
                    @endif
                    @if ($review)
                        <x-table.th>Alasan</x-table.th>
                    @endif
                    <x-table.th align="right">Aksi</x-table.th>
                </tr>
            </x-slot:header>
            <tbody class="divide-y divide-slate-800/60">
                @foreach ($items as $item)
                    @php
                        $product = $item->product;
                        $variance = $item->variance_qty !== null ? (float) $item->variance_qty : null;
                        $system = $item->reference_system_qty ?? $item->expected_qty;
                    @endphp
                    <x-table.tr wire:key="count-item-{{ $item->id }}" @class(['bg-emerald-500/5' => $item->id === $lastItemId])>
                        <x-table.td>
                            <div class="font-semibold text-slate-100">{{ $product->name }}@if ($product->variantLabel()) <span class="font-normal text-slate-400">— {{ $product->variantLabel() }}</span>@endif</div>
                            <div class="flex flex-wrap items-center gap-1 mt-0.5">
                                <span class="font-mono text-[11px] text-slate-400">{{ $product->sku }}</span>
                                @if ($product->tracksBatches())
                                    <x-badge color="slate">Batch</x-badge>
                                    @foreach ($product->batches->filter->isExpired()->take(1) as $expired)
                                        <x-badge color="rose">Ada batch kedaluwarsa</x-badge>
                                    @endforeach
                                @endif
                                @if ($product->tracksSerials())
                                    <x-badge color="slate">IMEI</x-badge>
                                @endif
                                @if ($item->needs_recount)
                                    <x-badge color="amber">Hitung ulang</x-badge>
                                @endif
                                @foreach ($item->flags ?? [] as $flag)
                                    @if (isset($flagLabels[$flag]) && ($canSeeSystem || $flag !== StockCountVariance::FLAG_RECOUNT_SUGGESTED))
                                        <x-badge :color="in_array($flag, [StockCountVariance::FLAG_INACTIVE, StockCountVariance::FLAG_DELETED, StockCountVariance::FLAG_NOT_TRACKED], true) ? 'slate' : 'amber'">{{ $flagLabels[$flag] }}</x-badge>
                                    @endif
                                @endforeach
                            </div>
                        </x-table.td>
                        @if ($canSeeSystem)
                            <x-table.td data-label="Stok sistem" align="right" class="tabular-nums text-slate-300">{{ Num::quantity((float) $system) }} {{ $product->unit }}</x-table.td>
                        @endif
                        <x-table.td data-label="Hasil hitung" align="right" class="tabular-nums">
                            @if ($item->counted_qty === null)
                                <span class="text-slate-500">Belum dihitung</span>
                            @else
                                <span class="font-semibold text-slate-100">{{ Num::quantity((float) $item->counted_qty) }}</span> <span class="text-slate-400">{{ $product->unit }}</span>
                            @endif
                        </x-table.td>
                        @if ($canSeeSystem)
                            <x-table.td data-label="Selisih" align="right" class="tabular-nums">
                                @if ($variance === null)
                                    <span class="text-slate-500">-</span>
                                @else
                                    <span @class(['font-semibold', 'text-rose-400' => $variance < 0, 'text-emerald-400' => $variance > 0, 'text-slate-400' => $variance == 0])>{{ $variance > 0 ? '+' : '' }}{{ Num::quantity($variance) }}</span>
                                    @if ($variance != 0 && $item->unit_cost)
                                        <div class="text-[11px] text-slate-400">{{ Num::currency((int) round($variance * $item->unit_cost)) }}</div>
                                    @endif
                                @endif
                            </x-table.td>
                        @endif
                        @if ($review)
                            <x-table.td data-label="Alasan">
                                <x-select :searchable="false" wire:change="setReason({{ $item->id }}, $event.target.value)" aria-label="Alasan selisih {{ $product->name }}" class="min-w-[160px]">
                                    <option value="">Pilih alasan</option>
                                    @foreach (StockCountReason::cases() as $reason)
                                        <option value="{{ $reason->value }}" @selected($item->reason === $reason)>{{ $reason->label() }}</option>
                                    @endforeach
                                </x-select>
                            </x-table.td>
                        @endif
                        <x-table.td data-label="Aksi" align="right">
                            <div class="inline-flex items-center gap-1">
                                @if ($editable)
                                    <x-icon-button :icon="$product->tracksSerials() ? 'scan-barcode' : 'pencil-line'" :label="'Isi hitungan '.$product->name" tone="edit" wire:click="openEntry({{ $item->id }})" />
                                @endif
                                @if ($review)
                                    <x-icon-button icon="rotate-ccw" :label="($item->needs_recount ? 'Batal tandai hitung ulang ' : 'Tandai hitung ulang ').$product->name" wire:click="toggleRecount({{ $item->id }})" />
                                @endif
                                <x-icon-button icon="history" :label="'Riwayat hitungan '.$product->name" wire:click="openHistory({{ $item->id }})" />
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </tbody>
        </x-table>
    @endif

    {{-- Isi hitungan --}}
    <x-modal name="count-entry" max-width="lg">
        @if ($entry = $this->entryItem)
            @php $entryProduct = $entry->product; @endphp
            <form wire:submit="saveEntry" class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
                <x-modal-header icon="pencil-line" :title="$entryProduct->name" closeable>
                    Hitungan ditambahkan ke hasil sebelumnya ({{ Num::quantity((float) ($entry->counted_qty ?? 0)) }} {{ $entryProduct->unit }}). Isi per satuan bila perlu.
                </x-modal-header>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <div>
                        <x-input-label for="entry-base" :value="$entryProduct->unit" />
                        <x-text-input wire:model="entryLines.base" id="entry-base" inputmode="decimal" class="w-full text-right tabular-nums" autofocus placeholder="0" />
                    </div>
                    @foreach ($entryProduct->units as $unit)
                        <div wire:key="entry-unit-{{ $unit->id }}">
                            <x-input-label for="entry-unit-{{ $unit->id }}" :value="$unit->name.' (isi '.Num::quantity((float) $unit->factor).')'" />
                            <x-text-input wire:model="entryLines.{{ $unit->id }}" id="entry-unit-{{ $unit->id }}" inputmode="decimal" class="w-full text-right tabular-nums" placeholder="0" />
                        </div>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('entryLines')" />

                @if ($entryProduct->tracksBatches())
                    <div>
                        <x-input-label for="entry-batch" value="Batch" />
                        <x-select wire:model.live="entryBatchId" id="entry-batch" :searchable="false">
                            <option value="">Tanpa batch (dibagi ke batch yang tidak dihitung)</option>
                            @foreach ($entryProduct->batches as $batch)
                                <option value="{{ $batch->id }}">{{ $batch->label() }}{{ $canSeeSystem ? ' · sistem '.Num::quantity((float) $batch->quantity) : '' }}{{ $batch->isExpired() ? ' · kedaluwarsa' : '' }}</option>
                            @endforeach
                            <option value="new">Batch baru…</option>
                        </x-select>
                        @if ($entryBatchId === 'new')
                            <div class="grid grid-cols-2 gap-3 mt-2">
                                <div>
                                    <x-input-label for="entry-new-batch" value="Nomor batch *" />
                                    <x-text-input wire:model="newBatchNumber" id="entry-new-batch" class="w-full" />
                                    <x-input-error :messages="$errors->get('newBatchNumber')" class="mt-1" />
                                </div>
                                <div>
                                    <x-input-label for="entry-new-expiry" value="Kedaluwarsa" />
                                    <x-text-input type="date" wire:model="newBatchExpiresAt" id="entry-new-expiry" class="w-full" />
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                <div>
                    <x-input-label for="entry-note" value="Catatan (mis. rak depan, gudang)" />
                    <x-text-input wire:model="entryNote" id="entry-note" class="w-full" />
                </div>

                <x-modal-actions>
                    <x-secondary-button type="button" @click="$dispatch('close-modal', 'count-entry')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled">
                        <x-loading-label target="saveEntry" loading="Menyimpan...">Simpan Hitungan</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </form>
        @endif
    </x-modal>

    {{-- Riwayat hitungan --}}
    <x-modal name="count-history" max-width="lg">
        @if ($history = $this->historyItem)
            <div class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
                <x-modal-header icon="history" :title="$history->product->name" closeable>Setiap hitungan tercatat. Hitungan yang salah dibatalkan, tidak dihapus.</x-modal-header>
                @if ($history->entries->isEmpty())
                    <p class="text-xs text-slate-400">Belum ada hitungan untuk barang ini.</p>
                @else
                    <ul class="rounded-lg border border-slate-800 divide-y divide-slate-800/60 text-xs">
                        @foreach ($history->entries as $line)
                            <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="entry-{{ $line->id }}">
                                <div class="min-w-0">
                                    <p @class(['font-semibold tabular-nums', 'text-slate-100' => ! $line->isVoided(), 'text-slate-500 line-through' => $line->isVoided()])>
                                        {{ Num::quantity((float) $line->quantity_base) }} {{ $history->product->unit }}
                                        @if ($line->breakdown)
                                            <span class="font-normal text-slate-400">({{ collect($line->breakdown)->map(fn ($part) => Num::quantity((float) $part['quantity']).' '.$part['name'])->implode(' + ') }})</span>
                                        @elseif ($line->unit)
                                            <span class="font-normal text-slate-400">(1 {{ $line->unit->name }})</span>
                                        @endif
                                    </p>
                                    <p class="text-[11px] text-slate-400">
                                        {{ $line->user?->name ?? 'Pengguna terhapus' }} · {{ $line->counted_at->translatedFormat('d M H:i') }} · {{ $line->source }}
                                        @if ($line->batch) · batch {{ $line->batch->label() }} @elseif ($line->new_batch_number) · batch baru {{ $line->new_batch_number }} @endif
                                        @if ($line->note) · {{ $line->note }} @endif
                                        @if ($line->isVoided()) · dibatalkan {{ $line->voider?->name }} @endif
                                    </p>
                                </div>
                                @if ($editable && ! $line->isVoided() && ($canManage || $line->user_id === auth()->id()))
                                    <x-text-button tone="rose" wire:click="voidEntry({{ $line->id }})" wire:confirm="Batalkan hitungan ini?">Batalkan</x-text-button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                <x-modal-actions>
                    <x-secondary-button type="button" @click="$dispatch('close-modal', 'count-history')">Tutup</x-secondary-button>
                </x-modal-actions>
            </div>
        @endif
    </x-modal>

    {{-- Scan IMEI --}}
    <x-modal name="count-serials" max-width="lg">
        @if ($serialItem = $this->serialItem)
            <div class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
                <x-modal-header icon="scan-barcode" :title="$serialItem->product->name" closeable>Scan setiap unit. Unit yang tidak ter-scan dianggap hilang saat opname diselesaikan.</x-modal-header>
                @if ($editable)
                    <form wire:submit="scanSerial" class="flex gap-2">
                        <label for="serial-scan" class="sr-only">Nomor seri</label>
                        <x-text-input wire:model="serialCode" id="serial-scan" autofocus autocomplete="off" class="flex-1 font-mono" placeholder="Scan IMEI / nomor seri" />
                        <x-primary-button>Catat</x-primary-button>
                    </form>
                    <x-input-error :messages="$errors->get('serialCode')" />
                @endif
                <p class="text-xs text-slate-400">{{ $this->serialRows->count() }} unit di-scan.</p>
                <ul class="max-h-80 overflow-y-auto rounded-lg border border-slate-800 divide-y divide-slate-800/60 text-xs">
                    @forelse ($this->serialRows as $row)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="serial-{{ $row->id }}">
                            <div class="min-w-0">
                                <p class="font-mono font-semibold text-slate-100">{{ $row->serial }}</p>
                                <p class="text-[11px] text-slate-400">{{ StockCountShow::serialResultLabel($row->result) }} · {{ $row->user?->name }} · {{ $row->scanned_at->translatedFormat('H:i') }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                @if ($row->result === StockCountSerial::RESULT_MATCHED)
                                    <x-badge color="emerald">Cocok</x-badge>
                                @elseif ($canManage && $editable)
                                    <x-select :searchable="false" wire:change="setSerialAction({{ $row->id }}, $event.target.value)" aria-label="Tindakan untuk {{ $row->serial }}" class="min-w-[170px]">
                                        <option value="">Perlu keputusan</option>
                                        @if ($row->result === StockCountSerial::RESULT_OTHER_OUTLET)
                                            <option value="{{ StockCountSerial::ACTION_RELOCATE }}" @selected($row->action === StockCountSerial::ACTION_RELOCATE)>Pindahkan ke outlet ini</option>
                                        @else
                                            <option value="{{ StockCountSerial::ACTION_REGISTER }}" @selected($row->action === StockCountSerial::ACTION_REGISTER)>Daftarkan sebagai stok</option>
                                        @endif
                                        <option value="{{ StockCountSerial::ACTION_IGNORE }}" @selected($row->action === StockCountSerial::ACTION_IGNORE)>Abaikan</option>
                                    </x-select>
                                @else
                                    <x-badge color="amber">{{ $row->action ? 'Sudah diputuskan' : 'Perlu keputusan' }}</x-badge>
                                @endif
                                @if ($editable && ($canManage || $row->user_id === auth()->id()))
                                    <x-icon-button icon="x" :label="'Hapus scan '.$row->serial" tone="danger" wire:click="removeSerial({{ $row->id }})" />
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="px-3 py-4 text-center text-slate-400">Belum ada unit yang di-scan.</li>
                    @endforelse
                </ul>
                <x-modal-actions>
                    <x-secondary-button type="button" @click="$dispatch('close-modal', 'count-serials'); $dispatch('focus-scan')">Selesai</x-secondary-button>
                </x-modal-actions>
            </div>
        @endif
    </x-modal>

    {{-- Barang tak dikenal --}}
    <x-modal name="count-unknown" max-width="sm">
        <form wire:submit="saveUnknown" class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
            <x-modal-header icon="circle-help" tone="amber" title="Barang tak dikenal" closeable>Dicatat supaya bisa dibuatkan produknya nanti. Stok tidak berubah.</x-modal-header>
            <div>
                <x-input-label for="unknown-barcode" value="Barcode *" />
                <x-text-input wire:model="unknownBarcode" id="unknown-barcode" class="w-full font-mono" />
                <x-input-error :messages="$errors->get('unknownBarcode')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="unknown-quantity" value="Jumlah *" />
                <x-text-input wire:model="unknownQuantity" id="unknown-quantity" inputmode="decimal" class="w-full text-right tabular-nums" />
                <x-input-error :messages="$errors->get('unknownQuantity')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="unknown-note" value="Keterangan (nama di kemasan, lokasi)" />
                <x-text-input wire:model="unknownNote" id="unknown-note" class="w-full" />
            </div>
            @if ($count->unknownItems()->exists())
                <p class="text-[11px] text-slate-400">Sudah tercatat: {{ $count->unknownItems()->pluck('barcode')->take(5)->implode(', ') }}{{ $count->unknownItems()->count() > 5 ? ', …' : '' }}</p>
            @endif
            <x-modal-actions>
                <x-secondary-button type="button" @click="$dispatch('close-modal', 'count-unknown')">Batal</x-secondary-button>
                <x-primary-button>Catat Barang</x-primary-button>
            </x-modal-actions>
        </form>
    </x-modal>

    {{-- Konfirmasi selesai --}}
    <x-modal name="count-post" max-width="md">
        @php $impact = $this->impact ?? []; @endphp
        <div class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
            <x-modal-header icon="clipboard-check" title="Selesaikan opname dan sesuaikan stok?">Setelah selesai, opname tidak bisa dibatalkan.</x-modal-header>
            <ul class="rounded-lg border border-slate-800 divide-y divide-slate-800/60 text-xs">
                <li class="flex justify-between px-3 py-2"><span class="text-slate-300">Barang yang stoknya berubah</span><span class="font-semibold tabular-nums text-slate-100">{{ Num::quantity($impact['changed'] ?? 0) }}</span></li>
                <li class="flex justify-between px-3 py-2"><span class="text-slate-300">Nilai kurang</span><span class="font-semibold tabular-nums text-rose-400">{{ Num::currency($impact['shortage_value'] ?? 0) }}</span></li>
                <li class="flex justify-between px-3 py-2"><span class="text-slate-300">Nilai lebih</span><span class="font-semibold tabular-nums text-emerald-400">{{ Num::currency($impact['surplus_value'] ?? 0) }}</span></li>
                @if (($impact['uncounted'] ?? 0) > 0)
                    <li class="flex justify-between gap-3 px-3 py-2">
                        <span class="text-slate-300">{{ Num::quantity($impact['uncounted']) }} barang belum dihitung</span>
                        <span @class(['font-semibold text-right', 'text-rose-400' => $uncountedPolicy === StockCount::UNCOUNTED_ZERO, 'text-slate-200' => $uncountedPolicy !== StockCount::UNCOUNTED_ZERO])>
                            {{ $uncountedPolicy === StockCount::UNCOUNTED_ZERO ? 'dianggap habis, kurang '.Num::currency($impact['uncounted_value'] ?? 0) : 'stoknya dibiarkan' }}
                        </span>
                    </li>
                @endif
            </ul>
            <x-modal-actions>
                <x-secondary-button type="button" @click="$dispatch('close-modal', 'count-post')">Periksa lagi</x-secondary-button>
                <x-primary-button type="button" wire:click="post" wire:loading.attr="disabled">
                    <x-loading-label target="post" loading="Menyesuaikan...">Selesaikan &amp; sesuaikan stok</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </div>
    </x-modal>

    {{-- Batalkan --}}
    @if ($canManage && $editable)
        <x-modal name="count-cancel" max-width="sm">
            <form wire:submit="cancel" class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
                <x-modal-header icon="x-circle" tone="rose" title="Batalkan opname ini?">Hitungan yang sudah masuk tidak dipakai dan stok tidak berubah.</x-modal-header>
                <div>
                    <x-input-label for="cancel-reason" value="Alasan" />
                    <x-text-input wire:model="cancelReason" id="cancel-reason" class="w-full" placeholder="Wajib bila sudah ada hitungan" />
                    <x-input-error :messages="$errors->get('cancelReason')" class="mt-1" />
                </div>
                <x-modal-actions>
                    <x-secondary-button type="button" @click="$dispatch('close-modal', 'count-cancel')">Tidak</x-secondary-button>
                    <x-danger-button wire:loading.attr="disabled">
                        <x-loading-label target="cancel" loading="Membatalkan...">Ya, Batalkan</x-loading-label>
                    </x-danger-button>
                </x-modal-actions>
            </form>
        </x-modal>
    @endif

    {{-- Impor Excel --}}
    @if ($editable && $this->isPro())
        <x-modal name="count-import" max-width="2xl">
            <div class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
                <x-modal-header icon="upload" title="Impor hasil hitung" closeable>Pakai template Excel dari halaman ini. Baris dengan kolom Hitungan kosong dilewati. Mengimpor berkas yang sama dua kali tidak membuat hitungan dobel.</x-modal-header>
                <x-file-input wire:model="importFile" accept=".xlsx,.xls,.csv" icon="file-spreadsheet" label="Pilih berkas Excel" hint="Maks. 5 MB, 5.000 baris." />
                <x-input-error :messages="$errors->get('importFile')" />
                @if ($importRows !== [])
                    @php $okRows = collect($importRows)->where('status', 'ok'); @endphp
                    <p class="text-xs text-slate-300">{{ $okRows->count() }} baris siap diimpor, {{ count($importRows) - $okRows->count() }} bermasalah.</p>
                    <ul class="max-h-72 overflow-y-auto rounded-lg border border-slate-800 divide-y divide-slate-800/60 text-xs">
                        @foreach ($importRows as $row)
                            <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="import-{{ $row['row'] }}">
                                <span class="min-w-0 truncate text-slate-200">Baris {{ $row['row'] }} · {{ $row['name'] }}</span>
                                @if ($row['status'] === 'ok')
                                    <span class="shrink-0 tabular-nums text-emerald-400">{{ Num::quantity($row['quantity']) }}</span>
                                @else
                                    <span class="shrink-0 text-rose-400">{{ $row['message'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                <x-modal-actions>
                    <x-secondary-button type="button" @click="$dispatch('close-modal', 'count-import')">Batal</x-secondary-button>
                    <x-primary-button type="button" wire:click="importNow" wire:loading.attr="disabled" :disabled="collect($importRows)->where('status', 'ok')->isEmpty()">
                        <x-loading-label target="importNow" loading="Mengimpor...">Impor Hitungan</x-loading-label>
                    </x-primary-button>
                </x-modal-actions>
            </div>
        </x-modal>
    @endif
</div>
