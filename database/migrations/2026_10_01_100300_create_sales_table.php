<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('cash_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 15)->default('completed');
            $table->unsignedBigInteger('subtotal');
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('cash_received')->default(0);
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->unsignedBigInteger('due_amount')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamp('sold_at');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'sold_at']);
            $table->index(['customer_id', 'due_amount']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 150);
            $table->string('sku', 50)->nullable();
            $table->string('unit', 20)->default('pcs');
            $table->decimal('quantity', 14, 3);
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('cost_price')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total');
            $table->string('note', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 15)->default('sale');
            $table->string('method', 20);
            $table->unsignedBigInteger('amount');
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['method', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
