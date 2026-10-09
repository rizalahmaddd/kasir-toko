<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_count_entries', function (Blueprint $table) {
            $table->decimal('batch_system_qty', 14, 3)->nullable()->after('new_batch_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_entries', function (Blueprint $table) {
            $table->dropColumn('batch_system_qty');
        });
    }
};
