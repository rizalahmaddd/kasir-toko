<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('area', 50)->nullable();
            $table->unsignedSmallInteger('capacity')->default(2);
            $table->string('token', 40)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['outlet_id', 'name']);
        });

        Schema::create('self_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('held_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->uuid('client_uuid')->unique();
            $table->string('session_token', 64)->unique();
            $table->string('device_id', 40)->nullable();
            $table->string('order_type', 15);
            $table->string('table_label', 30)->nullable();
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->string('customer_name', 100);
            $table->string('customer_phone', 25)->nullable();

            $table->string('payment_method', 20);
            $table->string('payment_status', 25)->default('unpaid');
            $table->string('status', 20)->default('pending');

            $table->unsignedBigInteger('subtotal')->default(0);
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('service_charge_amount')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            $table->json('kitchen_sent')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 150)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['outlet_id', 'status']);
            $table->index(['outlet_id', 'created_at']);
        });

        Schema::create('self_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('self_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name', 150);
            $table->string('unit', 30)->nullable();
            $table->unsignedBigInteger('price');
            $table->decimal('quantity', 14, 3);
            $table->json('modifiers')->nullable();
            $table->unsignedBigInteger('modifiers_total')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total');
            $table->string('note', 150)->nullable();
            $table->timestamps();
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->string('self_order_token', 40)->nullable()->unique();
        });

        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->foreignId('self_order_id')->nullable()->constrained()->nullOnDelete();
        });

        // Tanpa FK: self_orders.held_order_id sudah menunjuk ke sini, dan FK dua arah membuat urutan
        // drop tabel saat restore backup SQLite tidak bisa ditentukan.
        Schema::table('held_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('self_order_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('held_orders', function (Blueprint $table) {
            $table->dropIndex(['self_order_id']);
            $table->dropColumn('self_order_id');
        });

        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('self_order_id');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->dropUnique(['self_order_token']);
            $table->dropColumn('self_order_token');
        });

        Schema::dropIfExists('self_order_items');
        Schema::dropIfExists('self_orders');
        Schema::dropIfExists('dining_tables');
    }
};
