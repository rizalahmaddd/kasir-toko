<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "key" was the primary key, so the table is rebuilt instead of altered; existing counters move
     * to the tenant created by the previous migration.
     */
    public function up(): void
    {
        $rows = DB::table('document_sequences')->get();
        $tenantId = DB::table('tenants')->orderBy('id')->value('id');

        Schema::drop('document_sequences');

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('key');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        if ($tenantId !== null) {
            DB::table('document_sequences')->insert($rows->map(fn (object $row) => [
                'tenant_id' => $tenantId,
                'key' => $row->key,
                'next_number' => $row->next_number,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all());
        }
    }

    public function down(): void
    {
        $rows = DB::table('document_sequences')->get()->unique('key');

        Schema::drop('document_sequences');

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });

        DB::table('document_sequences')->insert($rows->map(fn (object $row) => [
            'key' => $row->key,
            'next_number' => $row->next_number,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ])->values()->all());
    }
};
