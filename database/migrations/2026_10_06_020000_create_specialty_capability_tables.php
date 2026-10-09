<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('products')->nullOnDelete();
            $table->json('variant_options')->nullable();
            $table->json('variant_values')->nullable();
            $table->boolean('track_serial')->default(false);
            $table->unsignedSmallInteger('warranty_days')->nullable();
        });

        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('serial', 64);
            $table->string('status', 10)->default('in_stock');
            $table->foreignId('sale_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'serial']);
            $table->index(['product_id', 'outlet_id', 'status']);
        });

        Schema::create('customer_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->string('type', 10)->default('order');
            $table->string('status', 15)->default('new');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 100);
            $table->string('customer_phone', 30)->nullable();
            $table->timestamp('pickup_at')->nullable();
            $table->unsignedBigInteger('estimated_total')->default(0);
            $table->unsignedBigInteger('deposit')->default(0);
            $table->string('device', 150)->nullable();
            $table->string('device_serial', 64)->nullable();
            $table->text('complaint')->nullable();
            $table->text('notes')->nullable();
            $table->string('image_path')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['outlet_id', 'status', 'pickup_at']);
        });

        Schema::create('customer_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->decimal('quantity', 14, 3);
            $table->unsignedBigInteger('price');
            $table->string('note', 150)->nullable();
        });

        Schema::create('customer_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('customer_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10)->default('deposit');
            $table->string('method', 20);
            $table->bigInteger('amount');
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        // Tanpa foreign key: customer_orders.sale_id sudah menunjuk ke sales, dan siklus FK merusak urutan backup SQLite.
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_order_id')->nullable()->index();
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('recipient', 100);
            $table->string('phone', 30)->nullable();
            $table->string('address', 500);
            $table->string('project', 150)->nullable();
            $table->string('driver', 100)->nullable();
            $table->string('vehicle', 30)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 15)->default('sent');
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['customer_order_id']);
            $table->dropColumn('customer_order_id');
        });

        Schema::dropIfExists('customer_order_payments');
        Schema::dropIfExists('customer_order_items');
        Schema::dropIfExists('customer_orders');
        Schema::dropIfExists('product_serials');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['variant_options', 'variant_values', 'track_serial', 'warranty_days']);
        });
    }
};
