<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\QueryException;

/**
 * Plumbing bersama untuk komponen CRUD master data: search+paginasi, buka/tutup modal tambah-edit, konfirmasi hapus, dan notifikasi.
 * Validasi & bentuk form tetap didefinisikan per komponen karena field tiap entitas beda.
 */
trait WithCrudActions
{
    use WithDataTable;

    public string $search = '';

    public bool $showModal = false;

    public ?int $editingId = null;

    public ?int $confirmingDeleteId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->authorizeManage();

        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showModal = true;
        $this->dispatch('open-modal', 'record-form');
    }

    public function openEditModal(int $id): void
    {
        $this->authorizeManage();

        $this->resetValidation();
        $this->resetForm();
        $this->editingId = $id;
        $this->fillForm($this->modelClass()::findOrFail($id));
        $this->showModal = true;
        $this->dispatch('open-modal', 'record-form');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->editingId = null;
        $this->resetForm();
        $this->resetValidation();
        $this->dispatch('close-modal', 'record-form');
    }

    public function confirmDelete(int $id): void
    {
        $this->authorizeManage();

        $this->confirmingDeleteId = $id;
        $this->dispatch('open-modal', 'confirm-delete');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-delete');
    }

    public function delete(): void
    {
        $this->authorizeManage();

        if (! $this->confirmingDeleteId) {
            return;
        }

        $record = $this->modelClass()::findOrFail($this->confirmingDeleteId);

        if ($blockedReason = $this->guardDelete($record)) {
            $this->confirmingDeleteId = null;
            $this->dispatch('close-modal', 'confirm-delete');
            $this->notify($blockedReason, 'error');

            return;
        }

        try {
            $record->delete();
        } catch (QueryException) {
            // Jaring pengaman terakhir: constraint FK di DB. guardDelete() di atas seharusnya
            // sudah menangkap ini lebih dulu untuk model yang pakai SoftDeletes, karena hapus
            // lunak cuma UPDATE dan tidak pernah membentur restrictOnDelete di DB.
            $this->confirmingDeleteId = null;
            $this->dispatch('close-modal', 'confirm-delete');
            $this->notify(__('Data ini masih dipakai di tempat lain, tidak bisa dihapus.'), 'error');

            return;
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal', 'confirm-delete');
        $this->resetPage();
        $this->notify(__('Data berhasil dihapus.'));
    }

    public function canManage(): bool
    {
        return auth()->user()->can('manage-master-data');
    }

    protected function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    public function notify(string $message, string $type = 'success'): void
    {
        $this->dispatch('notify', message: $message, type: $type);
    }

    /**
     * Nama kelas model Eloquent yang dikelola komponen ini.
     */
    abstract protected function modelClass(): string;

    /**
     * Reset properti form ke nilai default (dipakai saat buka modal tambah / tutup modal).
     */
    abstract protected function resetForm(): void;

    /**
     * Isi properti form dari record yang sedang diedit.
     */
    abstract protected function fillForm($record): void;

    /**
     * Hook opsional: kembalikan pesan error kalau $record tidak boleh dihapus (masih dipakai
     * di tempat lain). Override di komponen yang modelnya punya dependents. Default: tidak ada
     * pengecekan tambahan.
     */
    protected function guardDelete($record): ?string
    {
        return null;
    }
}
