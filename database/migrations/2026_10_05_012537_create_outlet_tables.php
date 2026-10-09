<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name', 100);
            $table->string('code', 10);
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('outlet_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unique(['outlet_id', 'user_id']);
        });

        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('min_stock', 14, 3)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'outlet_id']);
            $table->index('outlet_id');
        });

        Schema::create('product_outlet_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price');
            $table->timestamps();

            $table->unique(['product_id', 'outlet_id']);
            $table->index('outlet_id');
        });

        Schema::create('outlet_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'key']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('number', 40);
            $table->foreignId('from_outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('to_outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->string('status', 20)->default('completed');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('transferred_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['from_outlet_id', 'transferred_at']);
            $table->index(['to_outlet_id', 'transferred_at']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_outlets')->default(false);
            $table->foreignId('default_outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_outlets_override')->nullable();
            $table->timestamp('outlet_priority_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['max_outlets_override', 'outlet_priority_changed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_outlet_id');
            $table->dropColumn('all_outlets');
        });

        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('outlet_settings');
        Schema::dropIfExists('product_outlet_prices');
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('outlet_user');
        Schema::dropIfExists('outlets');
    }
};
