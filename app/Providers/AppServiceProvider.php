<?php

namespace App\Providers;

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureTenantAccess;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\RolePolicy;
use App\Support\Audit\AuditContext;
use App\Support\CurrentTenant;
use App\Support\Features;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AuditContext::class);
        $this->app->scoped(CurrentTenant::class);

        // activity()->causedBy($userId) resolves the id through the current guard's provider, but
        // the Sanctum guard has none (API requests crashed with "retrieveById() on null").
        $this->app->afterResolving(CauserResolver::class, fn (CauserResolver $resolver) => $resolver->resolveUsing(
            fn (Model|int|string|null $subject) => match (true) {
                $subject instanceof Model => $subject,
                $subject === null => auth()->user(),
                default => User::query()->findOrFail($subject),
            },
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Superadmin bypass: memberikan akses otomatis ke semua perizinan dan gate di aplikasi.
        // Best practice Spatie Laravel Permission & Laravel Gate.
        // Panel Platform dikecualikan: superadmin sebuah toko bukan pengelola layanan.
        Gate::before(function ($user, string $ability) {
            if ($ability === 'manage-platform') {
                return null;
            }

            return $user->hasRole('superadmin') ? true : null;
        });

        Blade::if('feature', fn (string $key) => Features::enabled($key));
        Livewire::addPersistentMiddleware([EnsureTenantAccess::class, EnsureFeatureEnabled::class]);

        // "composer run dev" juga menyalakan Reverb (update realtime) dan scheduler (backup terjadwal).
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('reverb:start --debug', 'reverb');
            DevCommands::artisan('schedule:work', 'scheduler');
        }

        // Activity milik package spatie/laravel-activitylog, bukan App\Models, jadi konvensi
        // penebakan otomatis nama Policy Laravel tidak menemukannya sendiri: harus didaftarkan
        // manual di sini.
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        // Jejak audit hanya boleh bertambah; pembersihan resmi lewat activitylog:clean (query langsung).
        Activity::updating(fn () => false);
        Activity::deleting(fn () => false);

        $this->bootTenancy();

        // Master data dibaca semua akun yang punya izin lihat; dikelola lewat izin kelola.
        Gate::define('view-master-data', fn ($user) => $user->can('master-data.view'));
        Gate::define('manage-master-data', fn ($user) => $user->can('master-data.manage'));
        // Only superadmin passes, through Gate::before above.
        Gate::define('view-api-docs', fn ($user) => false);
        Gate::define('manage-platform', fn (User $user) => $user->isPlatformAdmin());

        // Token mobile yang lama tidak dipakai ditolak (config sanctum.idle_days), dihitung dari last_used_at.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $idleDays = (int) config('sanctum.idle_days');

            return $isValid && ($idleDays === 0 || ($token->last_used_at ?? $token->created_at)->gte(now()->subDays($idleDays)));
        });

        RateLimiter::for('display', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }

    private function bootTenancy(): void
    {
        // Job tidak lewat middleware, jadi tenant pengirimnya ikut disimpan di payload.
        Queue::createPayloadUsing(fn () => ['tenant_id' => app(CurrentTenant::class)->id()]);
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => app(CurrentTenant::class)->set($event->job->payload()['tenant_id'] ?? null));

        // Activity milik package, jadi scope dipasang dari luar. Log login terjadi sebelum tenant
        // aktif, maka tenant diambil dari user yang login.
        Activity::addGlobalScope(new TenantScope);
        Activity::creating(function (Activity $activity) {
            $activity->tenant_id ??= app(CurrentTenant::class)->id() ?? $activity->causer?->getAttribute('tenant_id');
        });
    }
}
