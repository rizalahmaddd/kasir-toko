<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            // Key contoh: "PO-2026", "GRN-2026" — satu baris per prefix+tahun, di-lock lewat
            // DocumentNumberGenerator supaya penomoran aman dari race condition.
            $table->string('key')->primary();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
