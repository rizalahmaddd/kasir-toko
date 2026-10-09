<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->string('status', 20)->default('counting');
            $table->string('scope', 20);
            $table->json('scope_category_ids')->nullable();
            $table->boolean('blind_count')->default(true);
            $table->boolean('hold_adjustments')->default(false);
            $table->string('uncounted_policy', 20)->default('keep');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['outlet_id', 'status']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            // Produk memakai soft delete; hard delete produk yang pernah diopname harus gagal.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('expected_qty', 14, 3)->default(0);
            $table->decimal('counted_qty', 14, 3)->nullable();
            $table->timestamp('reference_at')->nullable();
            $table->decimal('reference_system_qty', 14, 3)->nullable();
            $table->decimal('variance_qty', 14, 3)->nullable();
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->string('reason', 30)->nullable();
            $table->boolean('needs_recount')->default(false);
            $table->json('flags')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('stock_count_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('stock_count_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('product_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('new_batch_number', 50)->nullable();
            $table->date('new_batch_expires_at')->nullable();
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->json('breakdown')->nullable();
            $table->decimal('quantity_base', 14, 3);
            $table->decimal('system_qty_at_count', 14, 3);
            $table->timestamp('counted_at');
            $table->string('source', 10);
            $table->string('note', 255)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['stock_count_item_id', 'voided_at']);
        });

        Schema::create('stock_count_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('serial', 64);
            $table->string('result', 20);
            $table->string('action', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id', 'serial']);
        });

        Schema::create('stock_count_unknown_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 64);
            $table->decimal('quantity', 14, 3);
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_count_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('stock_count_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_movement_id')->constrained();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();
        });

        // Kolom nullable di akhir tabel supaya MySQL 8 memakai ALGORITHM=INSTANT; null berarti sama dengan created_at.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable();
            $table->index(['product_id', 'outlet_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'outlet_id', 'occurred_at']);
            $table->dropColumn('occurred_at');
        });

        Schema::dropIfExists('stock_count_corrections');
        Schema::dropIfExists('stock_count_unknown_items');
        Schema::dropIfExists('stock_count_serials');
        Schema::dropIfExists('stock_count_entries');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
