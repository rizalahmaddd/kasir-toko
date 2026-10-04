<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename plan 'basic' → 'free'.
     *
     * Plan 'basic' adalah sisa konfigurasi lama yang sudah dihapus. Semua tenant
     * yang masih memakai plan ini dipindahkan ke 'free' agar konsisten.
     */
    public function up(): void
    {
        DB::table('tenants')->where('plan', 'basic')->update(['plan' => 'free']);
    }

    public function down(): void
    {
        // Tidak di-rollback: 'basic' sudah tidak ada di konfigurasi sistem.
    }
};
