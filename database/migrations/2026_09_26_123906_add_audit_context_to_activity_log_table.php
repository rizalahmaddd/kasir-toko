<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->uuid('batch_uuid')->nullable()->after('properties')->index();
            $table->string('ip_address', 45)->nullable()->after('batch_uuid');
            $table->string('user_agent', 500)->nullable()->after('ip_address');
            $table->string('http_method', 10)->nullable()->after('user_agent');
            $table->text('url')->nullable()->after('http_method');

            $table->index('created_at');
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['event']);
            $table->dropIndex(['batch_uuid']);
            $table->dropColumn(['batch_uuid', 'ip_address', 'user_agent', 'http_method', 'url']);
        });
    }
};
