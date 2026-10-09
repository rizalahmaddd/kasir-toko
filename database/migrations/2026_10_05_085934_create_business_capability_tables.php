<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('custom_attributes')->nullable();
            $table->string('attributes_search', 255)->nullable();
            $table->string('drug_class', 20)->nullable();
            $table->boolean('requires_prescription')->default(false);
            $table->boolean('track_batch')->default(false);
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20);
            $table->decimal('factor', 14, 3);
            $table->unsignedBigInteger('price')->nullable();
            $table->string('barcode', 64)->nullable();
            $table->boolean('is_default_sale')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'barcode']);
            $table->index('product_id');
        });

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 50)->nullable();
            $table->date('expires_at')->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->timestamp('received_at');
            $table->string('source', 20);
            $table->timestamps();

            $table->index(['product_id', 'outlet_id', 'expires_at']);
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('stock_movement_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_batch_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->date('prescription_date');
            $table->string('doctor_name', 100);
            $table->string('doctor_sip', 50)->nullable();
            $table->string('clinic_name', 150)->nullable();
            $table->string('patient_name', 100);
            $table->unsignedTinyInteger('patient_age')->nullable();
            $table->string('patient_phone', 30)->nullable();
            $table->string('patient_address', 255)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('image_path')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 150);
            $table->decimal('quantity_prescribed', 14, 3);
            $table->decimal('quantity_dispensed', 14, 3)->default(0);
            $table->unsignedTinyInteger('iteration')->default(0);
            $table->string('dosage_instructions', 150)->nullable();
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('prescription_id')->nullable()->constrained()->nullOnDelete();
            $table->json('flags')->nullable();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_factor', 14, 3)->default(1);
            $table->decimal('base_quantity', 14, 3)->nullable();
            $table->foreignId('prescription_item_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('sale_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_batch_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_batches');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prescription_item_id');
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_factor', 'base_quantity']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prescription_id');
            $table->dropColumn('flags');
        });

        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('stock_movement_batches');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('product_units');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['custom_attributes', 'attributes_search', 'drug_class', 'requires_prescription', 'track_batch']);
        });
    }
};
