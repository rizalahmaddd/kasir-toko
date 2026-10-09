<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name', 60);
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedBigInteger('price')->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('ingredient_quantity', 14, 3)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('modifier_group_product', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['product_id', 'modifier_group_id']);
        });

        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_quantity', 14, 3);
            $table->unsignedBigInteger('price');
            $table->timestamps();

            $table->unique(['product_id', 'min_quantity']);
        });

        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();

            $table->unique(['product_id', 'component_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->json('modifiers')->nullable();
            $table->unsignedBigInteger('modifiers_total')->default(0);
        });

        Schema::create('sale_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('source', 10);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('order_type', 15)->nullable();
            $table->string('table_label', 30)->nullable();
            $table->unsignedSmallInteger('queue_number')->nullable();
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('service_charge_amount')->default(0);
        });

        Schema::table('held_orders', function (Blueprint $table) {
            $table->string('order_type', 15)->nullable();
            $table->string('table_label', 30)->nullable();

            $table->index(['outlet_id', 'table_label']);
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 60);
            $table->string('order_type', 15)->nullable();
            $table->json('items');
            $table->string('status', 10)->default('pending');
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');

        Schema::table('held_orders', function (Blueprint $table) {
            // MySQL dropped the implicit outlet_id FK index once this composite index covered it.
            $table->index('outlet_id');
            $table->dropIndex(['outlet_id', 'table_label']);
            $table->dropColumn(['order_type', 'table_label']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['order_type', 'table_label', 'queue_number', 'service_charge_rate', 'service_charge_amount']);
        });

        Schema::dropIfExists('sale_item_components');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['modifiers', 'modifiers_total']);
        });

        Schema::dropIfExists('product_components');
        Schema::dropIfExists('product_price_tiers');
        Schema::dropIfExists('modifier_group_product');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
    }
};
