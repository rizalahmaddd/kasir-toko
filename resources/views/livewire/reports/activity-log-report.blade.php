@php
    use App\Support\Audit\AuditTrail;
    use App\Support\NumberFormatter;

    $currentTenant = app(\App\Support\CurrentTenant::class)->get();

    $eventColor = fn (?string $event) => match ($event) {
        'created' => 'emerald',
        'updated', 'password_reset' => 'sky',
        'deleted', 'voided', 'login_failed', 'lockout' => 'rose',
        'restored' => 'amber',
        default => 'slate',
    };
@endphp

<div>
@if ($currentTenant && ! $currentTenant->isPro())
    <x-pro-paywall
        title="Log Aktivitas & Audit Trail Khusus Pro"
        feature="Log Aktivitas & Audit Trail"
        description="Pantau riwayat perubahan data, jejak audit, aktivitas kasir, dan keamanan akun toko Anda secara transparan dengan paket Pro."
        icon="scroll-text"
    />
@else
<div class="space-y-4 sm:space-y-6">
    <div class="bg-slate-900/90 border border-slate-800/80 rounded-2xl p-4 sm:p-5">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                    <i data-lucide="history" class="w-4 h-4"></i>
                </div>
                <div>
                    <h2 class="font-bold text-base text-slate-100 leading-tight">{{ __('Log Aktivitas & Jejak Audit') }}</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ __('Siapa mengubah data apa, kapan, dari mana, beserta nilai sebelum dan sesudahnya.') }}
                    </p>
                </div>
            </div>

            @unless ($this->isTracing())
                <div class="shrink-0">
                    <x-date-range-filter />
                </div>
            @endunless
        </div>
    </div>

    <div class="bg-slate-900/90 border border-slate-800/80 rounded-2xl p-3.5 space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-2.5">
            <x-search-input class="xl:col-span-1" variant="form" id="activity-search" wire:model.live.debounce.400ms="search" :placeholder="__('Cari deskripsi, nilai, atau IP...')" :aria-label="__('Cari log')" />

            <x-select id="filter-log-name" wire:model.live="logName" aria-label="{{ __('Jenis aktivitas') }}">
                <option value="">{{ __('Semua jenis aktivitas') }}</option>
                @foreach ($logNames as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </x-select>

            <x-select id="filter-event" wire:model.live="event" aria-label="{{ __('Aksi') }}">
                <option value="">{{ __('Semua aksi') }}</option>
                @foreach ($events as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </x-select>

            <x-select id="filter-subject-type" wire:model.live="subjectType" aria-label="{{ __('Jenis data') }}">
                <option value="">{{ __('Semua jenis data') }}</option>
                @foreach ($subjectLabels as $class => $label)
                    <option value="{{ $class }}">{{ $label }}</option>
                @endforeach
            </x-select>

            <x-select id="filter-causer" wire:model.live="causerId" aria-label="{{ __('Pengguna') }}">
                <option value="">{{ __('Semua pengguna') }}</option>
                <option value="system">{{ __('Sistem / tanpa login') }}</option>
                @foreach ($this->causerOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-select>
        </div>

        @if ($this->isTracing() || $logName || $event || $subjectType || $causerId || $search)
            <div class="flex flex-wrap items-center justify-between gap-2">
                @if ($batch)
                    <p class="text-xs text-slate-300">
                        {{ __('Menampilkan semua perubahan dari satu aksi') }}
                        <span class="font-mono text-[11px] text-slate-400">{{ $batch }}</span>
                    </p>
                @elseif ($this->isTracing())
                    <p class="text-xs text-slate-300">
                        {{ __('Riwayat lengkap') }} {{ AuditTrail::subjectLabel($subjectType) }} #{{ $subjectId }}
                        <span class="text-slate-400">({{ __('semua tanggal') }})</span>
                    </p>
                @else
                    <span></span>
                @endif

                <x-text-button wire:click="resetFilters">
                    <i data-lucide="x" class="w-3 h-3"></i>
                    <span>{{ __('Reset filter') }}</span>
                </x-text-button>
            </div>
        @endif
    </div>

    @if ($activities->isEmpty())
        <div class="bg-slate-900/80 rounded-2xl border border-slate-800/80 p-5 sm:p-8">
            <x-empty-state
                icon="history"
                title="{{ __('Tidak ada aktivitas pada filter ini') }}"
                description="{{ __('Coba ubah rentang tanggal, kata kunci, atau reset filter.') }}"
            />
        </div>
    @else
        <div class="space-y-3">
            <div class="flex items-center justify-between px-1">
                <h3 class="font-bold text-slate-100 text-sm">{{ __('Rekam Peristiwa Sistem') }}</h3>
                <x-table.export-button action="export" label="Export Log" />
            </div>

            <x-table :pagination="$activities">
                <thead class="bg-slate-950 text-slate-400 font-semibold uppercase tracking-wider border-b border-slate-800">
                    <tr>
                        <x-table.th sortable field="created_at">{{ __('Waktu') }}</x-table.th>
                        <x-table.th sortable field="log_name">{{ __('Kategori') }}</x-table.th>
                        <x-table.th sortable field="event">{{ __('Aksi') }}</x-table.th>
                        <x-table.th sortable field="description">{{ __('Deskripsi Aktivitas') }}</x-table.th>
                        <x-table.th>{{ __('Eksekutor') }}</x-table.th>
                        <x-table.th align="right"><span class="sr-only">{{ __('Detail') }}</span></x-table.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @foreach ($activities as $activity)
                        @php($changedCount = count(AuditTrail::changeRows($activity)))
                        <x-table.tr wire:key="activity-{{ $activity->id }}">
                            <x-table.td class="text-slate-400 whitespace-nowrap font-mono text-[11px]">
                                {{ $activity->created_at->translatedFormat('d M Y H:i:s') }}
                            </x-table.td>
                            <x-table.td>
                                <x-badge color="slate">{{ $logNames[$activity->log_name] ?? $activity->log_name }}</x-badge>
                            </x-table.td>
                            <x-table.td>
                                @if ($activity->event)
                                    <x-badge :color="$eventColor($activity->event)">{{ AuditTrail::eventLabel($activity->event) }}</x-badge>
                                @else
                                    <span class="text-slate-400">-</span>
                                @endif
                            </x-table.td>
                            <x-table.td class="text-slate-200">
                                <div class="font-medium text-slate-100">{{ NumberFormatter::narrative($activity->description) }}</div>
                                @if ($reason = $activity->properties?->get('reason'))
                                    <div class="mt-1.5 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-amber-500/10 border border-amber-500/25 text-[11px] text-amber-300">
                                        <i data-lucide="message-square-text" class="w-3.5 h-3.5 text-amber-400 shrink-0"></i>
                                        <span><strong class="font-bold text-amber-200">{{ __('Alasan') }}:</strong> {{ $reason }}</span>
                                    </div>
                                @endif
                                @if ($changedCount > 0)
                                    <div class="mt-1 text-[11px] text-slate-400">{{ $changedCount }} {{ __('kolom tercatat') }}</div>
                                @endif
                            </x-table.td>
                            <x-table.td class="text-slate-300">
                                <div class="font-medium">{{ $activity->causer?->name ?? __('Sistem') }}</div>
                                @if ($activity->ip_address)
                                    <div class="text-[11px] text-slate-400 font-mono">{{ $activity->ip_address }}</div>
                                @endif
                            </x-table.td>
                            <x-table.td align="right">
                                <x-icon-button icon="file-search" :label="__('Lihat detail log').' #'.$activity->id" wire:click="showDetail({{ $activity->id }})" />
                            </x-table.td>
                        </x-table.tr>
                    @endforeach
                </tbody>
            </x-table>
        </div>
    @endif

    <x-modal name="activity-detail" max-width="4xl" focusable>
        @if ($detail = $this->selectedActivity)
            @php($rows = AuditTrail::changeRows($detail))
            <div class="p-4 sm:p-6 space-y-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-badge color="slate">{{ $logNames[$detail->log_name] ?? $detail->log_name }}</x-badge>
                            @if ($detail->event)
                                <x-badge :color="$eventColor($detail->event)">{{ AuditTrail::eventLabel($detail->event) }}</x-badge>
                            @endif
                            <span class="text-[11px] text-slate-400 font-mono">#{{ $detail->id }}</span>
                        </div>
                        <h3 class="mt-2 text-sm font-bold text-slate-100">{{ NumberFormatter::narrative($detail->description) }}</h3>
                    </div>
                    <x-icon-button icon="x" :label="__('Tutup')" x-on:click="$dispatch('close-modal', 'activity-detail')" class="shrink-0" />
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-xs">
                    <div>
                        <dt class="text-slate-400">{{ __('Waktu') }}</dt>
                        <dd class="text-slate-200 font-mono">{{ $detail->created_at->translatedFormat('d M Y H:i:s') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('Eksekutor') }}</dt>
                        <dd class="text-slate-200">{{ $detail->causer?->name ?? __('Sistem / tanpa login') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('Data') }}</dt>
                        <dd class="text-slate-200">
                            @if ($detail->subject_type)
                                {{ AuditTrail::subjectLabel($detail->subject_type) }} #{{ $detail->subject_id }}
                                @unless ($detail->subject)
                                    <span class="text-slate-400">({{ __('sudah tidak ada') }})</span>
                                @endunless
                            @else
                                -
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">{{ __('Alamat IP') }}</dt>
                        <dd class="text-slate-200 font-mono">{{ $detail->ip_address ?? '-' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-slate-400">{{ __('Halaman / perintah') }}</dt>
                        <dd class="text-slate-200 font-mono break-all">
                            @if ($detail->http_method)
                                <span class="text-slate-400">{{ $detail->http_method }}</span>
                            @endif
                            {{ $detail->url ?? '-' }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-slate-400">{{ __('Perangkat') }}</dt>
                        <dd class="text-slate-300 break-all">{{ $detail->user_agent ?? '-' }}</dd>
                    </div>
                </dl>

                <div class="space-y-2">
                    <h4 class="text-xs font-bold text-slate-100">{{ __('Perubahan nilai') }}</h4>
                    @if ($rows === [])
                        <p class="text-xs text-slate-400">{{ __('Aktivitas ini tidak menyimpan perubahan kolom.') }}</p>
                    @else
                        <x-table :stack="false">
                                <thead class="bg-slate-950 text-slate-400 text-left">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 font-semibold">{{ __('Kolom') }}</th>
                                        <th scope="col" class="px-3 py-2 font-semibold">{{ __('Sebelum') }}</th>
                                        <th scope="col" class="px-3 py-2 font-semibold">{{ __('Sesudah') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/60">
                                    @foreach ($rows as $row)
                                        <tr wire:key="change-{{ $detail->id }}-{{ $row['field'] }}">
                                            <td class="px-3 py-2 font-mono text-slate-300 align-top whitespace-nowrap">{{ $row['field'] }}</td>
                                            <td class="px-3 py-2 font-mono align-top break-all {{ $row['changed'] ? 'text-rose-300' : 'text-slate-300' }}">{{ $row['old'] ?? '-' }}</td>
                                            <td class="px-3 py-2 font-mono align-top break-all {{ $row['changed'] ? 'text-emerald-300' : 'text-slate-300' }}">{{ $row['new'] ?? '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </x-table>
                    @endif
                </div>

                @if ($detail->properties?->isNotEmpty())
                    <details class="text-xs">
                        <summary class="cursor-pointer text-slate-300 font-semibold min-h-[44px] flex items-center">{{ __('Data tambahan (JSON)') }}</summary>
                        <pre class="mt-2 p-3 rounded-lg bg-slate-950 border border-slate-800 text-slate-300 overflow-x-auto text-[11px]">{{ json_encode($detail->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif

                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-1">
                    @if ($detail->subject_type && $detail->subject_id)
                        <x-secondary-button wire:click="traceSubject(@js($detail->subject_type), '{{ $detail->subject_id }}')">
                            <i data-lucide="list-tree" class="w-4 h-4"></i>
                            {{ __('Riwayat data ini') }}
                        </x-secondary-button>
                    @endif
                    @if ($detail->batch_uuid)
                        <x-secondary-button wire:click="traceBatch('{{ $detail->batch_uuid }}')">
                            <i data-lucide="layers" class="w-4 h-4"></i>
                            {{ __('Semua perubahan dari aksi ini') }}
                        </x-secondary-button>
                    @endif
                </div>
            </div>
        @endif
    </x-modal>
</div>
@endif
</div>
