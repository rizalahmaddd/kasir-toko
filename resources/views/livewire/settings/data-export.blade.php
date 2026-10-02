<div class="space-y-4 sm:space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <p class="text-xs text-slate-400 max-w-2xl">
            Unduh seluruh data {{ $tenant?->name }} sebagai satu berkas ZIP berisi CSV yang bisa dibuka di Excel.
            Angka ditulis tanpa pemisah ribuan dan data yang sudah dihapus ikut disertakan, jadi berkas ini bisa dipakai sebagai salinan pribadi atau untuk pindah aplikasi.
        </p>
        <x-primary-button size="sm" type="button" class="shrink-0" x-on:click="window.location.href = @js(route('settings.data-export.download'))">
            <i data-lucide="download" class="w-4 h-4"></i>
            <span>Unduh Data (ZIP)</span>
        </x-primary-button>
    </div>

    <x-table>
        <x-slot:header>
            <tr>
                <x-table.th>Data</x-table.th>
                <x-table.th>Berkas</x-table.th>
                <x-table.th align="right">Jumlah Baris</x-table.th>
            </tr>
        </x-slot:header>
        <tbody class="divide-y divide-slate-800/60">
            @foreach ($datasets as $dataset)
                <x-table.tr wire:key="dataset-{{ $dataset['file'] }}">
                    <x-table.td class="font-medium text-slate-100">{{ $dataset['label'] }}</x-table.td>
                    <x-table.td class="font-mono text-slate-400">{{ $dataset['file'] }}</x-table.td>
                    <x-table.td align="right" class="tabular-nums text-slate-300">{{ number_format($dataset['count'], 0, ',', '.') }}</x-table.td>
                </x-table.tr>
            @endforeach
        </tbody>
    </x-table>
</div>
