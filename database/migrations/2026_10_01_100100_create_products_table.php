<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 50)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('name', 150);
            $table->string('unit', 20)->default('pcs');
            $table->unsignedBigInteger('cost_price')->default(0);
            $table->unsignedBigInteger('price');
            $table->boolean('track_stock')->default(true);
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('min_stock', 14, 3)->default(0);
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
