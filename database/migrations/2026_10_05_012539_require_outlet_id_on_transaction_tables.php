<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['sales', 'sale_payments', 'cash_shifts', 'cash_movements', 'stock_movements', 'held_orders'];

    /**
     * Dipisah dari migrasi backfill karena mengubah kolom jadi NOT NULL tidak instan di tabel besar.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('outlet_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('outlet_id')->nullable()->change();
            });
        }
    }
};
