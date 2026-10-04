<?php

namespace App\Livewire\Concerns;

use App\Support\DateInput;
use Livewire\Attributes\Url;

/**
 * Filter rentang tanggal dipakai di semua halaman Laporan (menu baru "Laporan"), supaya
 * preset "Bulan Ini" / "30 Hari Terakhir" / "Tahun Ini" konsisten dan tidak ditulis ulang di
 * tiap komponen. `mountWithDateRangeFilter()` dipanggil otomatis oleh Livewire lewat konvensi
 * trait hook (lihat Livewire\Features\SupportLifecycleHooks), bukan dipanggil manual.
 */
trait WithDateRangeFilter
{
    #[Url(history: true)]
    public string $from = '';

    #[Url(history: true)]
    public string $to = '';

    public function mountWithDateRangeFilter(): void
    {
        $this->from = DateInput::valid($this->from) ?? now()->startOfMonth()->toDateString();
        $this->to = DateInput::valid($this->to) ?? now()->toDateString();

        if ($this->from > $this->to) {
            [$this->from, $this->to] = [$this->to, $this->from];
        }
    }

    /**
     * Input tanggal yang dikosongkan atau diisi sembarang (juga lewat URL) kembali ke default,
     * dan rentang terbalik mengikuti tanggal yang baru diubah, supaya laporan tidak error atau
     * diam-diam kosong.
     */
    public function updatedWithDateRangeFilter(string $name): void
    {
        if ($name === 'from') {
            $this->from = DateInput::valid($this->from) ?? now()->startOfMonth()->toDateString();
            $this->to = max($this->to, $this->from);
        } elseif ($name === 'to') {
            $this->to = DateInput::valid($this->to) ?? now()->toDateString();
            $this->from = min($this->from, $this->to);
        }
    }

    public function presetThisMonth(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function presetLast30Days(): void
    {
        $this->from = now()->subDays(29)->toDateString();
        $this->to = now()->toDateString();
    }

    public function presetThisYear(): void
    {
        $this->from = now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }
}
