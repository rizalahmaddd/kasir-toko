@use('App\Models\BackupSchedule')
@use('App\Services\BackupService')
@use('Illuminate\Support\Number')

@php
    $scopes = BackupService::SCOPES;
    $healthTone = ['fresh' => 'emerald', 'stale' => 'amber', 'none' => 'rose'][$health];
    $healthLabel = ['fresh' => 'Terlindungi', 'stale' => 'Perlu backup baru', 'none' => 'Belum ada backup'][$health];
    $originLabel = fn (string $origin) => match (true) {
        $origin === 'manual' => 'Manual',
        $origin === 'pre-restore' => 'Sebelum restore',
        $origin === 'upload' => 'Unggahan',
        default => 'Jadwal '.($scheduleNames[$origin] ?? '(dihapus)'),
    };
    $activeSchedules = $schedules->where('is_active', true)->count();
@endphp

<div class="space-y-4 sm:space-y-6" @if ($pending) wire:poll.5s @endif>
    <p class="text-xs sm:text-sm text-slate-400 max-w-3xl leading-relaxed">
        Salinan database dan file aplikasi disimpan di server. Unduh secara berkala ke tempat lain, karena backup yang hanya ada di server ikut hilang kalau servernya rusak.
    </p>

    {{-- Panel fokal: seberapa terlindungi data saat ini, dan aksi backup manual di sebelahnya. --}}
    <section class="rounded-2xl border border-slate-800 bg-slate-900 shadow-lg shadow-slate-950/40 overflow-hidden" aria-labelledby="backup-status-heading">
        <div class="grid grid-cols-1 lg:grid-cols-5">
            <div class="lg:col-span-3 p-4 sm:p-6 space-y-5">
                <div class="flex items-start gap-4">
                    <div @class([
                        'w-12 h-12 rounded-2xl border flex items-center justify-center shrink-0',
                        'bg-emerald-500/10 border-emerald-500/30 text-emerald-400' => $healthTone === 'emerald',
                        'bg-amber-500/10 border-amber-500/30 text-amber-400' => $healthTone === 'amber',
                        'bg-rose-500/10 border-rose-500/30 text-rose-400' => $healthTone === 'rose',
                    ])>
                        <i data-lucide="{{ $health === 'fresh' ? 'shield-check' : ($health === 'stale' ? 'shield-alert' : 'shield-off') }}" class="w-6 h-6"></i>
                    </div>
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 id="backup-status-heading" class="text-xs font-semibold text-slate-400">Backup terakhir</h2>
                            <x-badge :color="$healthTone">{{ $healthLabel }}</x-badge>
                        </div>
                        @if ($lastBackup)
                            <p class="text-xl sm:text-2xl font-bold text-slate-100 leading-tight">{{ $lastBackup['created_at']->diffForHumans() }}</p>
                            <p class="text-xs text-slate-400">
                                {{ $scopes[$lastBackup['scope']]['label'] }} · {{ Number::fileSize($lastBackup['size'], precision: 1) }} · {{ $originLabel($lastBackup['origin']) }}
                                · {{ $lastBackup['created_at']->translatedFormat('d M Y, H:i') }}
                            </p>
                        @else
                            <p class="text-xl sm:text-2xl font-bold text-slate-100 leading-tight">Belum pernah</p>
                            <p class="text-xs text-slate-400">Buat backup pertama di samping, lalu pasang jadwal otomatis supaya tidak lupa.</p>
                        @endif
                    </div>
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-4 border-t border-slate-800">
                    <div class="min-w-0">
                        <dt class="text-[11px] font-medium text-slate-400">Jadwal berikutnya</dt>
                        @if ($nextRun)
                            <dd class="mt-0.5 text-sm font-semibold text-slate-100">{{ $nextRun['at']->translatedFormat('D, d M · H:i') }}</dd>
                            <dd class="text-[11px] text-slate-400 truncate">{{ $nextRun['schedule']->name }} · {{ $nextRun['schedule']->scopeLabel() }}</dd>
                        @else
                            <dd class="mt-0.5 text-sm font-semibold text-slate-300">Tidak ada</dd>
                            <dd class="text-[11px] text-slate-400">Belum ada jadwal aktif</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium text-slate-400">Jadwal aktif</dt>
                        <dd class="mt-0.5 text-sm font-semibold text-slate-100 font-mono">{{ $activeSchedules }} <span class="font-sans font-normal text-slate-400">dari {{ $schedules->count() }}</span></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium text-slate-400">Penyimpanan backup</dt>
                        <dd class="mt-0.5 text-sm font-semibold text-slate-100 font-mono">{{ Number::fileSize($totalSize, precision: 1) }}</dd>
                        <dd class="text-[11px] text-slate-400">
                            {{ $totalFiles }} file{{ $freeSpace !== null ? ' · sisa disk '.Number::fileSize($freeSpace, precision: 1) : '' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="lg:col-span-2 p-4 sm:p-6 space-y-4 border-t lg:border-t-0 lg:border-l border-slate-800 bg-slate-950/40">
                <h2 class="font-bold text-sm text-slate-100">Backup sekarang</h2>

                <x-segmented class="w-full" aria-label="Jenis backup">
                    @foreach ($scopes as $key => $meta)
                        <x-tab-button size="sm" :icon="$meta['icon']" :active="$scope === $key" wire:click="$set('scope', '{{ $key }}')" class="flex-1">{{ $meta['label'] }}</x-tab-button>
                    @endforeach
                </x-segmented>
                <p class="text-xs text-slate-400 leading-relaxed min-h-[2.5rem]">{{ $scopes[$scope]['description'] ?? '' }}</p>

                @if ($scope !== 'database')
                    <x-checkbox-card wire:model="includeSecrets" label="Sertakan file .env"
                        description="Berisi APP_KEY dan password database. Perlu untuk membangun ulang server, tapi simpan arsipnya dengan aman." />
                @endif

                @if ($pending)
                    <div role="status" class="flex items-start gap-3 rounded-xl border border-sky-500/30 bg-sky-500/10 p-3 text-xs text-sky-300">
                        <x-spinner class="mt-0.5 shrink-0 text-sky-400" />
                        <p class="leading-relaxed">
                            Backup {{ mb_strtolower($scopes[$pending['scope']]['label'] ?? '') }} sedang diproses sejak {{ $pending['since']->translatedFormat('H:i') }}.
                            Halaman ini boleh ditinggal, notifikasi masuk ke lonceng begitu selesai.
                        </p>
                    </div>
                @else
                    <x-primary-button type="button" wire:click="createBackup" wire:loading.attr="disabled" wire:target="createBackup" class="w-full justify-center">
                        <i data-lucide="archive" class="w-4 h-4"></i>
                        <x-loading-label target="createBackup" loading="Mengantrekan...">{{ __('Mulai Backup') }}</x-loading-label>
                    </x-primary-button>
                @endif
            </div>
        </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-6 items-start">
        <div class="lg:col-span-8 space-y-6">
            <section class="space-y-2" aria-labelledby="schedules-heading">
                <div class="flex items-end justify-between gap-3 px-1">
                    <div>
                        <h2 id="schedules-heading" class="text-sm font-bold text-slate-200">Jadwal otomatis</h2>
                        <p class="text-xs text-slate-400">Satu jadwal bisa jalan di beberapa jam sekaligus, dan menyimpan backup-nya sendiri.</p>
                    </div>
                    <x-secondary-button size="sm" type="button" wire:click="openScheduleModal" class="shrink-0">
                        <i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i>
                        {{ __('Tambah Jadwal') }}
                    </x-secondary-button>
                </div>

                <div class="bg-slate-900/80 border border-slate-800/80 rounded-xl overflow-hidden">
                    @forelse ($schedules as $schedule)
                        @php($next = $schedule->nextRunAt(now()))
                        <div wire:key="schedule-{{ $schedule->id }}" @class([
                            'flex flex-col sm:flex-row sm:items-center gap-3 p-3.5 sm:p-4',
                            'border-t border-slate-800/80' => ! $loop->first,
                            'opacity-60' => ! $schedule->is_active,
                        ])>
                            <div class="flex items-start gap-3 min-w-0 flex-1">
                                <x-feature-switch id="schedule-active-{{ $schedule->id }}" :label="'Aktifkan jadwal '.$schedule->name"
                                    wire:click="toggleSchedule({{ $schedule->id }})" :checked="$schedule->is_active" class="mt-0.5" />
                                <div class="min-w-0 space-y-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="text-sm font-semibold text-slate-100">{{ $schedule->name }}</span>
                                        <x-badge color="sky"><i data-lucide="{{ $scopes[$schedule->scope]['icon'] ?? 'archive' }}" class="w-3 h-3"></i> {{ $schedule->scopeLabel() }}</x-badge>
                                        @if ($schedule->include_secrets)
                                            <x-badge color="amber">+ .env</x-badge>
                                        @endif
                                    </div>
                                    <p class="text-xs text-slate-300">{{ $schedule->describe() }}</p>
                                    <p class="text-[11px] text-slate-400">
                                        Simpan {{ $schedule->keep }} terakhir ·
                                        @if (! $schedule->is_active)
                                            Dijeda
                                        @elseif ($next)
                                            Berikutnya {{ $next->translatedFormat('D, d M H:i') }}
                                        @endif
                                    </p>
                                    @if ($schedule->last_status === 'failed')
                                        <p class="text-[11px] text-rose-400">Gagal {{ $schedule->last_run_at?->diffForHumans() }}: {{ \Illuminate\Support\Str::limit($schedule->last_error, 140) }}</p>
                                    @elseif ($schedule->last_status === 'success')
                                        <p class="text-[11px] text-emerald-400">Berhasil {{ $schedule->last_run_at?->diffForHumans() }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-1 self-end sm:self-center">
                                <x-icon-button icon="play" label="Jalankan sekarang" tone="edit" wire:click="runScheduleNow({{ $schedule->id }})" />
                                <x-icon-button icon="pencil" label="Ubah jadwal" wire:click="openScheduleModal({{ $schedule->id }})" />
                                <x-icon-button icon="trash-2" label="Hapus jadwal" tone="danger" wire:click="confirmDeleteSchedule({{ $schedule->id }})" />
                            </div>
                        </div>
                    @empty
                        <x-empty-state icon="calendar-clock" title="Belum ada jadwal otomatis"
                            description="Tanpa jadwal, backup hanya dibuat kalau ada yang ingat menekan tombol. Contoh umum: database setiap hari jam 02:00 dan 20:00, backup lengkap setiap Minggu.">
                            <x-primary-button size="sm" type="button" wire:click="openScheduleModal">{{ __('Buat Jadwal Pertama') }}</x-primary-button>
                        </x-empty-state>
                    @endforelse
                </div>
            </section>

            <section class="space-y-2" aria-labelledby="files-heading">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 px-1">
                    <h2 id="files-heading" class="text-sm font-bold text-slate-200">File backup</h2>
                    <x-segmented aria-label="Saring jenis backup" class="overflow-x-auto">
                        <x-tab-button size="sm" :active="$scopeFilter === 'all'" :count="$totalFiles" wire:click="$set('scopeFilter', 'all')">Semua</x-tab-button>
                        @foreach ($scopes as $key => $meta)
                            <x-tab-button size="sm" :active="$scopeFilter === $key" :count="$scopeCounts[$key] ?? 0" wire:click="$set('scopeFilter', '{{ $key }}')">{{ $meta['label'] }}</x-tab-button>
                        @endforeach
                    </x-segmented>
                </div>

                <x-table>
                    <x-slot:header>
                        <tr>
                            <x-table.th>File</x-table.th>
                            <x-table.th>Asal</x-table.th>
                            <x-table.th>Dibuat</x-table.th>
                            <x-table.th align="right">Ukuran</x-table.th>
                            <x-table.th align="right">Aksi</x-table.th>
                        </tr>
                    </x-slot:header>
                    <tbody class="divide-y divide-slate-800/60">
                        @forelse ($files as $file)
                            <x-table.tr wire:key="backup-{{ $file['name'] }}">
                                <x-table.td data-label="File">
                                    <div class="flex items-start gap-2.5 min-w-0">
                                        <i data-lucide="{{ $scopes[$file['scope']]['icon'] }}" class="w-4 h-4 mt-0.5 shrink-0 text-slate-400" aria-hidden="true"></i>
                                        <div class="min-w-0">
                                            <span class="block font-mono text-slate-200 break-all">{{ $file['name'] }}</span>
                                            <span class="text-[11px] text-slate-400">{{ $scopes[$file['scope']]['label'] }}</span>
                                        </div>
                                    </div>
                                </x-table.td>
                                <x-table.td data-label="Asal">
                                    <x-badge :color="match ($file['origin']) { 'pre-restore' => 'amber', 'upload' => 'sky', 'manual' => 'slate', default => 'emerald' }">{{ $originLabel($file['origin']) }}</x-badge>
                                </x-table.td>
                                <x-table.td data-label="Dibuat" class="text-slate-300 whitespace-nowrap">{{ $file['created_at']->translatedFormat('d M Y, H:i') }}</x-table.td>
                                <x-table.td data-label="Ukuran" align="right" class="font-mono text-slate-300 whitespace-nowrap">{{ Number::fileSize($file['size'], precision: 1) }}</x-table.td>
                                <x-table.td data-label="Aksi" align="right">
                                    <div class="inline-flex items-center gap-1">
                                        <a href="{{ route('settings.backups.download', $file['name']) }}" aria-label="Unduh {{ $file['name'] }}" title="Unduh"
                                            class="inline-flex items-center justify-center shrink-0 w-11 h-11 sm:w-8 sm:h-8 rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40 transition">
                                            <i data-lucide="download" aria-hidden="true" class="w-4 h-4"></i>
                                        </a>
                                        @if ($file['restorable'])
                                            <x-icon-button icon="history" label="Pulihkan database dari file ini" tone="edit" wire:click="confirmRestore('{{ $file['name'] }}')" />
                                        @endif
                                        <x-icon-button icon="trash-2" label="Hapus file" tone="danger" wire:click="confirmDelete('{{ $file['name'] }}')" />
                                    </div>
                                </x-table.td>
                            </x-table.tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    @if ($totalFiles === 0)
                                        <x-empty-state icon="archive" title="Belum ada backup"
                                            description="Pilih jenis backup di panel atas lalu tekan Mulai Backup, atau unggah file backup dari server lain." />
                                    @else
                                        <x-empty-state icon="filter" title="Tidak ada backup {{ mb_strtolower($scopes[$scopeFilter]['label'] ?? '') }}"
                                            description="Pilih Semua untuk melihat backup jenis lain." />
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-table>
            </section>
        </div>

        <aside class="lg:col-span-4 space-y-4">
            <form wire:submit="uploadBackup" class="bg-slate-900 border border-slate-800 rounded-xl p-4 sm:p-5 space-y-3">
                <div>
                    <h2 class="font-bold text-sm text-slate-100">Unggah file backup</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Untuk memulihkan dari backup yang disimpan di luar server ini.</p>
                </div>
                <div>
                    <x-input-label for="upload" value="File .sql.gz, .sql, atau .zip" />
                    <x-file-input wire:model="upload" id="upload" accept=".gz,.sql,.zip" variant="secondary" class="mt-1" />
                    <p class="text-[11px] text-slate-400 mt-1" wire:loading.remove wire:target="upload">Maks. 12 MB. Hanya file yang dibuat dari halaman ini yang diterima.</p>
                    <p class="text-[11px] text-slate-400 mt-1" wire:loading wire:target="upload">Mengunggah file...</p>
                    <x-input-error :messages="$errors->get('upload')" class="mt-1.5" />
                </div>
                <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="upload,uploadBackup" class="w-full justify-center" :disabled="! $upload">
                    <x-loading-label target="uploadBackup" loading="Memeriksa file...">{{ __('Simpan ke Daftar Backup') }}</x-loading-label>
                </x-secondary-button>
            </form>

            <div class="rounded-xl border border-slate-800 p-4 sm:p-5 space-y-3 text-xs text-slate-400 leading-relaxed">
                <h2 class="font-bold text-sm text-slate-100">Isi backup file aplikasi</h2>
                <p>Kode, konfigurasi, migration, dan file unggahan di <span class="font-mono text-slate-300">storage/app</span>. Folder yang bisa dibangun ulang tidak ikut:</p>
                <p class="font-mono text-[11px] text-slate-300 break-words">vendor · node_modules · public/build · .git · storage/logs · storage/framework</p>
                <p>Memulihkannya dilakukan di server: ekstrak isi folder <span class="font-mono text-slate-300">files/</span>, lalu jalankan <span class="font-mono text-slate-300">composer install</span> dan <span class="font-mono text-slate-300">npm run build</span>.</p>
            </div>

            <div class="rounded-xl border border-slate-800 p-4 sm:p-5 space-y-2 text-xs text-slate-400 leading-relaxed">
                <h2 class="font-bold text-sm text-slate-100">Yang terjadi saat restore</h2>
                <ul class="list-disc pl-4 space-y-1">
                    <li>Seluruh data diganti isi backup. Transaksi yang dibuat setelah backup itu hilang.</li>
                    <li>Backup lengkap: hanya bagian database yang dipulihkan dari sini.</li>
                    <li>Kondisi saat ini dibackup otomatis dulu (asal "Sebelum restore"), jadi restore bisa dibatalkan dengan memulihkan file itu.</li>
                    <li>Sesi login tidak ikut dipulihkan, pengguna tetap login.</li>
                </ul>
            </div>
        </aside>
    </div>

    <x-record-form-modal name="schedule-form" :title="$editingScheduleId ? 'Ubah jadwal backup' : 'Jadwal backup baru'"
        subtitle="Dicek tiap menit oleh scheduler, berlaku tanpa restart." icon="calendar-clock" max-width="2xl">
        <form wire:submit="saveSchedule" class="space-y-5">
            <div>
                <x-input-label for="scheduleName" value="Nama jadwal *" />
                <x-text-input wire:model="scheduleName" id="scheduleName" type="text" class="w-full mt-1" placeholder="Mis. Database harian" />
                <x-input-error :messages="$errors->get('scheduleName')" class="mt-1.5" />
            </div>

            <div>
                <x-input-label value="Jenis backup" />
                <x-segmented class="w-full mt-1" aria-label="Jenis backup jadwal">
                    @foreach ($scopes as $key => $meta)
                        <x-tab-button size="sm" :icon="$meta['icon']" :active="$scheduleScope === $key" wire:click="$set('scheduleScope', '{{ $key }}')" class="flex-1">{{ $meta['label'] }}</x-tab-button>
                    @endforeach
                </x-segmented>
                <x-input-error :messages="$errors->get('scheduleScope')" class="mt-1.5" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="frequency" value="Frekuensi" />
                    <x-select wire:model.live="frequency" id="frequency" class="w-full mt-1">
                        @foreach (BackupSchedule::FREQUENCIES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('frequency')" class="mt-1.5" />
                </div>
                @if ($frequency === 'monthly')
                    <div>
                        <x-input-label for="monthDay" value="Tanggal" />
                        <x-text-input wire:model="monthDay" id="monthDay" type="number" min="1" max="28" class="w-full mt-1 font-mono" />
                        <p class="text-[11px] text-slate-400 mt-1">1–28, supaya tetap jalan di bulan Februari.</p>
                        <x-input-error :messages="$errors->get('monthDay')" class="mt-1.5" />
                    </div>
                @endif
            </div>

            @if ($frequency === 'weekly')
                <fieldset>
                    <legend class="block font-medium text-xs text-slate-300">Hari</legend>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                        @foreach (BackupSchedule::WEEKDAYS as $value => $label)
                            <x-checkbox wire:model="weekdays" value="{{ $value }}" :label="$label" />
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('weekdays')" class="mt-1.5" />
                </fieldset>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
                <fieldset>
                    <legend class="block font-medium text-xs text-slate-300">Jam</legend>
                    <div class="mt-1 space-y-2">
                        @foreach ($times as $index => $time)
                            <div wire:key="time-{{ $index }}">
                                <div class="flex items-center gap-2">
                                    <x-text-input wire:model="times.{{ $index }}" type="time" class="w-40 font-mono" :aria-label="'Jam ke-'.($index + 1)" />
                                    @if (count($times) > 1)
                                        <x-icon-button icon="x" label="Hapus jam ini" tone="danger" wire:click="removeTime({{ $index }})" />
                                    @endif
                                </div>
                                <x-input-error :messages="$errors->get('times.'.$index)" class="mt-1" />
                            </div>
                        @endforeach
                    </div>
                    <x-text-button tone="emerald" size="sm" wire:click="addTime" class="mt-2">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah jam
                    </x-text-button>
                    <x-input-error :messages="$errors->get('times')" class="mt-1.5" />
                </fieldset>

                <div>
                    <x-input-label for="keep" value="Simpan berapa backup terakhir" />
                    <x-text-input wire:model="keep" id="keep" type="number" min="1" max="365" class="w-40 mt-1 font-mono" />
                    <p class="text-[11px] text-slate-400 mt-1">Hanya menghapus backup dari jadwal ini. Backup manual, unggahan, dan jadwal lain tidak tersentuh.</p>
                    <x-input-error :messages="$errors->get('keep')" class="mt-1.5" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @if ($scheduleScope !== 'database')
                    <x-checkbox-card wire:model="scheduleIncludeSecrets" label="Sertakan file .env"
                        description="Berisi APP_KEY dan password database. Simpan arsipnya dengan aman." />
                @endif
                <x-checkbox-card wire:model="scheduleActive" label="Aktif" description="Jadwal yang tidak aktif disimpan tapi tidak dijalankan." />
            </div>

            <x-modal-actions>
                <x-secondary-button type="button" wire:click="closeModal" class="justify-center">{{ __('Batal') }}</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveSchedule" class="justify-center">
                    <x-loading-label target="saveSchedule" loading="Menyimpan...">{{ __('Simpan Jadwal') }}</x-loading-label>
                </x-primary-button>
            </x-modal-actions>
        </form>
    </x-record-form-modal>

    <x-modal name="confirm-restore" :show="false" max-width="md">
        <form wire:submit="restore" class="p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 space-y-4">
            <x-modal-header icon="history" tone="rose" title="Pulihkan database dari backup ini?">
                Semua data saat ini diganti isi <span class="font-mono text-slate-200 break-all">{{ $restoring }}</span>.
                Data yang dibuat setelah backup tersebut akan hilang.
            </x-modal-header>

            <div>
                <x-input-label for="restore_password" value="Password Anda" />
                <x-text-input wire:model="password" id="restore_password" type="password" autocomplete="current-password" class="w-full mt-1" placeholder="Konfirmasi dengan password akun superadmin" />
                <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
            </div>

            <x-modal-actions>
                <x-secondary-button type="button" @click="$dispatch('close')" wire:click="cancelRestore" class="justify-center">
                    {{ __('Batal') }}
                </x-secondary-button>
                <x-danger-button type="submit" wire:loading.attr="disabled" wire:target="restore" class="justify-center">
                    <x-loading-label target="restore" loading="Memulihkan...">{{ __('Ya, Pulihkan Database') }}</x-loading-label>
                </x-danger-button>
            </x-modal-actions>
        </form>
    </x-modal>

    <x-confirm-delete-modal :title="($deleting['type'] ?? null) === 'schedule' ? 'Hapus jadwal ini?' : 'Hapus file backup ini?'"
        :description="ucfirst($deletingLabel).' dihapus permanen.'" />
</div>
