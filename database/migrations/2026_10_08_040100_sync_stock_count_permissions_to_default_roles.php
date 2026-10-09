<?php

use App\Console\Commands\SyncRolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('saas:sync-role-permissions', ['permission' => SyncRolePermissions::STOCK_COUNT_PERMISSIONS]);
    }

    public function down(): void
    {
        // Izin tetap dibiarkan; mencabutnya bisa menimpa kustomisasi pemilik toko.
    }
};
