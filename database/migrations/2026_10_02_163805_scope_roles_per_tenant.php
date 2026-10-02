<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spatie "teams" with tenant_id as the team key: every shop edits its own roles. The pivot
     * tables get tenant_id inside their primary key, so they are rebuilt rather than altered.
     * Fresh installs already get these columns from the Spatie migration once teams is on.
     */
    public function up(): void
    {
        if (Schema::hasColumn('roles', 'tenant_id')) {
            return;
        }

        $tenantId = DB::table('tenants')->orderBy('id')->value('id');

        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->index('tenant_id', 'roles_team_foreign_key_index');
            $table->dropUnique(['name', 'guard_name']);
            $table->unique(['tenant_id', 'name', 'guard_name']);
        });

        if ($tenantId !== null) {
            DB::table('roles')->update(['tenant_id' => $tenantId]);
        }

        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $name => $pivot) {
            $rows = DB::table($name)->get();
            Schema::drop($name);
            $this->createPivot($name, $pivot, withTenant: true);

            DB::table($name)->insert($rows->map(fn (object $row) => [
                $pivot => $row->{$pivot},
                'model_type' => $row->model_type,
                'model_id' => $row->model_id,
                'tenant_id' => DB::table('users')->where('id', $row->model_id)->value('tenant_id') ?? $tenantId,
            ])->filter(fn (array $row) => $row['tenant_id'] !== null)->values()->all());
        }

        app('cache')->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $name => $pivot) {
            $rows = DB::table($name)->get();
            Schema::drop($name);
            $this->createPivot($name, $pivot, withTenant: false);

            DB::table($name)->insert($rows->unique(fn (object $row) => "{$row->{$pivot}}-{$row->model_type}-{$row->model_id}")->map(fn (object $row) => [
                $pivot => $row->{$pivot},
                'model_type' => $row->model_type,
                'model_id' => $row->model_id,
            ])->values()->all());
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name', 'guard_name']);
            $table->dropIndex('roles_team_foreign_key_index');
            $table->dropColumn('tenant_id');
            $table->unique(['name', 'guard_name']);
        });

        app('cache')->forget(config('permission.cache.key'));
    }

    private function createPivot(string $name, string $pivot, bool $withTenant): void
    {
        $related = $pivot === 'role_id' ? 'roles' : 'permissions';
        $primary = $pivot === 'role_id' ? 'model_has_roles_role_model_type_primary' : 'model_has_permissions_permission_model_type_primary';

        Schema::create($name, function (Blueprint $table) use ($name, $pivot, $related, $primary, $withTenant) {
            $table->unsignedBigInteger($pivot);
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], "{$name}_model_id_model_type_index");
            $table->foreign($pivot)->references('id')->on($related)->cascadeOnDelete();

            if ($withTenant) {
                $table->unsignedBigInteger('tenant_id');
                $table->index('tenant_id', "{$name}_team_foreign_key_index");
                $table->primary(['tenant_id', $pivot, 'model_id', 'model_type'], $primary);
            } else {
                $table->primary([$pivot, 'model_id', 'model_type'], $primary);
            }
        });
    }
};
