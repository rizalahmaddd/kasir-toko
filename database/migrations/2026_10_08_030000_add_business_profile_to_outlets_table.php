<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('store_type', 30)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('disabled_features')->nullable();
        });

        Schema::create('category_outlet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            $table->unique(['category_id', 'outlet_id']);
            $table->index('outlet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_outlet');

        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['store_type', 'capabilities', 'disabled_features']);
        });
    }
};
