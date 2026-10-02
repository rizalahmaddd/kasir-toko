<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('store_type', 30)->nullable();
            $table->timestamp('onboarded_at')->nullable();
        });

        // Stores that already exist are set up by hand; only new sign-ups go through onboarding.
        DB::table('tenants')->update(['onboarded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['store_type', 'onboarded_at']);
        });
    }
};
