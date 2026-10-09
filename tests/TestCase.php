<?php

namespace Tests;

use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Data dari factory otomatis masuk ke tenant ini, seperti request user yang sudah login.
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->tenant = Tenant::factory()->create();
            app(CurrentTenant::class)->set($this->tenant);
        }
    }

    /**
     * Kembali ke tenant bawaan tes, mis. setelah login sebagai admin platform (tanpa tenant).
     */
    public function useDefaultTenant(): void
    {
        if (app(CurrentTenant::class)->id() === null && $this->tenant !== null) {
            app(CurrentTenant::class)->set($this->tenant);
        }
    }

    /**
     * Livewire::test() tidak lewat middleware, jadi tenant user yang dipakai login diaktifkan di sini.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        $tenantId = $user->getAttribute('tenant_id');
        app(CurrentTenant::class)->set($tenantId === null ? null : (int) $tenantId);

        if ($tenantId !== null && $user instanceof User) {
            $outlets = app(CurrentOutlet::class);
            $outlets->loadAccess($user);
            $outlets->set($outlets->pickDefault([$user->default_outlet_id]));
        }

        return parent::be($user, $guard);
    }
}
