<?php

namespace App\Providers;

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\RolePolicy;
use App\Support\Audit\AuditContext;
use App\Support\Features;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
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
        Gate::before(function ($user, string $ability) {
            return $user->hasRole('superadmin') ? true : null;
        });

        Blade::if('feature', fn (string $key) => Features::enabled($key));
        Livewire::addPersistentMiddleware([EnsureFeatureEnabled::class]);

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

        // Master data dibaca semua akun yang punya izin lihat; dikelola lewat izin kelola.
        Gate::define('view-master-data', fn ($user) => $user->can('master-data.view'));
        Gate::define('manage-master-data', fn ($user) => $user->can('master-data.manage'));

        RateLimiter::for('display', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
