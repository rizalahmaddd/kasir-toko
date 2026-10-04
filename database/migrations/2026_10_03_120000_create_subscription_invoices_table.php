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
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('invoice_number', 50)->unique();
            $table->string('plan', 50);
            $table->unsignedSmallInteger('period_months')->default(1);
            $table->unsignedInteger('amount');
            $table->string('status', 30)->default('pending')->index();
            $table->string('payment_method', 30)->default('qris');
            $table->string('payment_gateway_ref', 100)->nullable()->index();
            $table->text('payment_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
