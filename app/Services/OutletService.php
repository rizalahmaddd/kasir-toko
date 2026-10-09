<?php

namespace App\Services;

use App\Enums\StoreType;
use App\Models\CashShift;
use App\Models\HeldOrder;
use App\Models\Outlet;
use App\Models\OutletSetting;
use App\Models\ProductOutletPrice;
use App\Models\Scopes\OutletAccessScope;
use App\Models\StockCount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\OutletFeatures;
use App\Support\OutletSettings;
use App\Support\PlanLimits;
use App\Support\StorePresets;
use App\Support\TenantRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Siklus hidup outlet: buat, ubah, jadikan utama, nonaktifkan, hapus, atur akses pengguna, dan
 * salin pengaturan & harga dari outlet lain. Semua aturan batas paket dan keamanan data ada di sini
 * supaya halaman web dan API memperlakukan outlet dengan cara yang sama.
 */
class OutletService
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?Outlet $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', TenantRule::unique('outlets', 'name')->ignore($ignore?->id)],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,10}$/', TenantRule::unique('outlets', 'code')->ignore($ignore?->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.unique' => 'Nama outlet ini sudah dipakai.',
            'code.unique' => 'Kode outlet ini sudah dipakai.',
            'code.regex' => 'Kode outlet 2-10 karakter, hanya huruf besar dan angka.',
        ];
    }

    /**
     * $preset diisi bila outlet ini jenis usaha lain dari outlet sumbernya, mis. Apotek di toko Kelontong.
     *
     * @param  array{name: string, code: string, address?: ?string, phone?: ?string}  $data
     * @param  array{store_type: StoreType, include_sample_products?: bool, categories?: list<string>|null, capabilities?: list<string>|null}|null  $preset
     */
    public function create(array $data, User $actor, ?int $copyFromOutletId = null, ?array $preset = null): Outlet
    {
        return DB::transaction(function () use ($data, $actor, $copyFromOutletId, $preset) {
            PlanLimits::ensureCanAdd('outlets', 'name');

            $existing = Outlet::query()->count();
            $source = $copyFromOutletId ? Outlet::query()->findOrFail($copyFromOutletId) : null;

            $outlet = Outlet::query()->create([
                'name' => $data['name'],
                'code' => strtoupper($data['code']),
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_primary' => $existing === 0,
                'is_active' => true,
                'priority' => (int) Outlet::query()->max('priority') + 1,
            ]);

            $this->seedStocks($outlet);
            $this->inheritBusinessProfile($outlet, $source);

            // Pengguna terbatas di toko satu outlet selama ini memakai outlet utama tanpa penugasan; jadikan eksplisit.
            if ($existing === 1) {
                $this->assignUnassignedUsersToPrimary();
            }

            if (! $actor->hasAllOutletAccess()) {
                $outlet->users()->syncWithoutDetaching([$actor->id => ['tenant_id' => $outlet->tenant_id]]);
            }

            if ($source) {
                $this->copyConfiguration($source, $outlet);
            }

            if ($preset !== null) {
                app(StorePresetApplier::class)->applyToOutlet(
                    $outlet,
                    $preset['store_type'],
                    $preset['include_sample_products'] ?? true,
                    $preset['categories'] ?? null,
                    $preset['capabilities'] ?? null,
                );
            }

            activity()->performedOn($outlet)->causedBy($actor)->event('created')
                ->log("Outlet {$outlet->name} ({$outlet->code}) dibuat".($source ? ", pengaturan dan harga disalin dari {$source->name}" : '').'.');

            return $outlet;
        });
    }

    /**
     * Outlet baru memakai jenis usaha dan fitur outlet yang disalin, atau fitur outlet utama. Tanpa
     * ini outlet baru akan mengikuti daftar toko, yang bisa memuat fitur khusus outlet lain.
     */
    private function inheritBusinessProfile(Outlet $outlet, ?Outlet $source): void
    {
        $template = $source ?? Outlet::query()->where('is_primary', true)->whereKeyNot($outlet->id)->first();

        if (! $template) {
            return;
        }

        $outlet->forceFill([
            'store_type' => $source?->store_type,
            'capabilities' => $template->capabilities,
            'disabled_features' => $template->disabled_features,
        ])->saveQuietly();
        OutletFeatures::flush();
    }

    /**
     * Ganti jenis usaha outlet. Hanya menambah: kategori dan fitur preset baru dinyalakan, fitur dan
     * kategori lama tetap ada supaya data transaksi outlet ini tidak kehilangan konteksnya.
     */
    public function changeStoreType(Outlet $outlet, StoreType $type, bool $includeSampleProducts = false): Outlet
    {
        if ($outlet->store_type === $type) {
            return $outlet;
        }

        app(StorePresetApplier::class)->applyToOutlet(
            $outlet,
            $type,
            $includeSampleProducts,
            null,
            array_values(array_unique([...OutletFeatures::capabilities($outlet->id), ...StorePresets::capabilities($type)])),
        );

        return $outlet;
    }

    /**
     * @param  array{name: string, code: string, address?: ?string, phone?: ?string}  $data
     */
    public function update(Outlet $outlet, array $data): Outlet
    {
        $code = strtoupper($data['code']);

        if ($code !== $outlet->code && $outlet->hasHistory()) {
            throw ValidationException::withMessages(['code' => 'Kode outlet tidak bisa diubah karena sudah dipakai di nomor transaksi.']);
        }

        $outlet->update([
            'name' => $data['name'],
            'code' => $code,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);

        return $outlet;
    }

    public function setPrimary(Outlet $outlet, User $actor): Outlet
    {
        return DB::transaction(function () use ($outlet, $actor) {
            $outlets = Outlet::query()->lockForUpdate()->get();
            $current = $outlets->firstWhere('is_primary', true);

            if ($current?->is($outlet)) {
                return $outlet;
            }

            if (! $outlet->is_active) {
                throw ValidationException::withMessages(['outlet' => 'Outlet nonaktif tidak bisa dijadikan utama.']);
            }

            $this->ensurePriorityChangeAllowed();

            $outlets->where('is_primary', true)->each(fn (Outlet $other) => $other->update(['is_primary' => false]));
            $outlet->update(['is_primary' => true]);
            $this->markPriorityChanged();

            activity()->performedOn($outlet)->causedBy($actor)->event('updated')->log("Outlet {$outlet->name} dijadikan outlet utama.");

            return $outlet;
        });
    }

    /**
     * Urutan prioritas menentukan outlet mana yang tetap beroperasi saat jumlah outlet melebihi batas
     * paket. Saat belum melebihi batas urutan bebas diubah; saat melebihi, dibatasi sekali per 30 hari
     * supaya toko Gratis tidak bergiliran memakai banyak outlet.
     *
     * @param  list<int>  $orderedIds
     */
    public function setPriorities(array $orderedIds, User $actor): void
    {
        DB::transaction(function () use ($orderedIds, $actor) {
            $outlets = Outlet::query()->lockForUpdate()->get()->keyBy('id');
            $ordered = collect($orderedIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $outlets->has($id))->unique()->values();

            if ($ordered->isEmpty()) {
                return;
            }

            $before = Outlet::query()->active()->byPriority()->pluck('id')->all();

            $position = 1;
            foreach ($ordered as $id) {
                $outlets[$id]->update(['priority' => $position++]);
            }

            if (Outlet::query()->active()->byPriority()->pluck('id')->all() === $before) {
                return;
            }

            $this->ensurePriorityChangeAllowed();
            $this->markPriorityChanged();

            activity()->causedBy($actor)->event('updated')->log('Urutan prioritas outlet diubah.');
        });
    }

    public function deactivate(Outlet $outlet, User $actor): Outlet
    {
        if ($outlet->is_primary) {
            throw ValidationException::withMessages(['outlet' => 'Outlet utama tidak bisa dinonaktifkan. Jadikan outlet lain sebagai utama dulu.']);
        }

        if (CashShift::query()->withoutGlobalScopes()->where('outlet_id', $outlet->id)->whereNull('closed_at')->exists()) {
            throw ValidationException::withMessages(['outlet' => 'Masih ada shift kasir yang terbuka di outlet ini. Tutup shift itu dulu.']);
        }

        $openCount = StockCount::query()->withoutGlobalScope(OutletAccessScope::class)->open()->where('outlet_id', $outlet->id)->value('number');

        if ($openCount !== null) {
            throw ValidationException::withMessages(['outlet' => "Masih ada stok opname {$openCount} yang berjalan di outlet ini. Selesaikan atau batalkan opname itu dulu."]);
        }

        $outlet->update(['is_active' => false]);

        activity()->performedOn($outlet)->causedBy($actor)->event('updated')->log("Outlet {$outlet->name} dinonaktifkan.");

        return $outlet;
    }

    public function activate(Outlet $outlet, User $actor): Outlet
    {
        if ($outlet->is_active) {
            return $outlet;
        }

        DB::transaction(function () use ($outlet, $actor) {
            PlanLimits::ensureCanAdd('outlets', 'outlet');
            $outlet->update(['is_active' => true]);

            activity()->performedOn($outlet)->causedBy($actor)->event('updated')->log("Outlet {$outlet->name} diaktifkan kembali.");
        });

        return $outlet;
    }

    public function delete(Outlet $outlet, User $actor): void
    {
        if ($outlet->is_primary) {
            throw ValidationException::withMessages(['outlet' => 'Outlet utama tidak bisa dihapus.']);
        }

        if ($outlet->hasHistory()) {
            throw ValidationException::withMessages(['outlet' => 'Outlet ini punya riwayat transaksi atau stok sehingga tidak bisa dihapus. Nonaktifkan saja.']);
        }

        DB::transaction(function () use ($outlet, $actor) {
            HeldOrder::query()->withoutGlobalScopes()->where('outlet_id', $outlet->id)->delete();
            User::query()->where('default_outlet_id', $outlet->id)->update(['default_outlet_id' => null]);
            $outlet->delete();
            app(BusinessCapabilities::class)->refreshStoreList();

            activity()->causedBy($actor)->event('deleted')->log("Outlet {$outlet->name} dihapus.");
        });
    }

    /**
     * Akses pengguna ke outlet. Pemilik selalu semua outlet, jadi penugasannya diabaikan.
     *
     * @param  list<int>  $outletIds
     */
    public function syncUserAccess(User $user, bool $allOutlets, array $outletIds): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $valid = Outlet::query()->whereIn('id', $outletIds)->pluck('id')->all();

        if (! $allOutlets && $valid === [] && Outlet::query()->count() > 1) {
            throw ValidationException::withMessages(['outlets' => 'Pilih minimal satu outlet, atau centang "Semua outlet".']);
        }

        DB::transaction(function () use ($user, $allOutlets, $valid) {
            $user->forceFill(['all_outlets' => $allOutlets])->save();
            $tenantId = (int) $user->tenant_id;
            $user->outlets()->sync(collect($allOutlets ? [] : $valid)->mapWithKeys(fn (int $id) => [$id => ['tenant_id' => $tenantId]])->all());

            if ($user->default_outlet_id !== null && ! $allOutlets && ! in_array($user->default_outlet_id, $valid, true)) {
                $user->forceFill(['default_outlet_id' => $valid[0] ?? null])->save();
            }
        });
    }

    /**
     * Atur siapa saja yang boleh memakai $outlet. Pengguna "semua outlet" dan pemilik tidak diubah, dan
     * pengguna terbatas tidak boleh kehilangan outlet terakhirnya. Mengembalikan jumlah akun yang berubah.
     *
     * @param  list<int>  $userIds
     */
    public function syncOutletUsers(Outlet $outlet, array $userIds): int
    {
        $wanted = collect($userIds)->map(fn ($id) => (int) $id);
        $changed = 0;

        DB::transaction(function () use ($outlet, $wanted, &$changed) {
            foreach (User::query()->get()->reject(fn (User $user) => $user->isPlatformAdmin() || $user->hasAllOutletAccess()) as $user) {
                $has = $user->outlets()->whereKey($outlet->id)->exists();
                $wants = $wanted->contains($user->id);

                if ($has && ! $wants) {
                    if ($user->outlets()->count() <= 1) {
                        throw ValidationException::withMessages(['user_ids' => "{$user->name} harus punya minimal satu outlet. Pindahkan dulu ke outlet lain."]);
                    }

                    $user->outlets()->detach($outlet->id);
                    $changed++;
                } elseif (! $has && $wants) {
                    $user->outlets()->attach($outlet->id, ['tenant_id' => $outlet->tenant_id]);
                    $changed++;
                }
            }
        });

        return $changed;
    }

    /**
     * Ganti seluruh penimpaan pajak/metode bayar/struk dan harga khusus $target dengan milik $source.
     * Hasilnya salinan, bukan tautan: perubahan di $source sesudahnya tidak ikut mengubah $target.
     * $includeBusiness ikut menyamakan jenis usaha, fitur khusus, dan fitur kasir yang dimatikan.
     */
    public function copyConfiguration(Outlet $source, Outlet $target, bool $includeBusiness = false): void
    {
        if ($source->is($target)) {
            return;
        }

        DB::transaction(function () use ($source, $target, $includeBusiness) {
            if ($includeBusiness) {
                app(BusinessCapabilities::class)->syncOutlet($target, OutletFeatures::capabilities($source->id));
                OutletFeatures::setDisabled($target, $source->disabled_features ?? []);
                $target->forceFill(['store_type' => $source->store_type])->save();
            }

            OutletSetting::query()->where('outlet_id', $target->id)->delete();
            OutletSetting::query()->where('outlet_id', $source->id)->get()->each(fn (OutletSetting $setting) => OutletSetting::query()->create([
                'outlet_id' => $target->id,
                'key' => $setting->key,
                'value' => $setting->value,
            ]));
            OutletSettings::forget($target->id);

            ProductOutletPrice::query()->where('outlet_id', $target->id)->delete();
            ProductOutletPrice::query()->where('outlet_id', $source->id)->get()->chunk(500)->each(
                fn ($chunk) => ProductOutletPrice::query()->insert($chunk->map(fn (ProductOutletPrice $price) => [
                    'tenant_id' => $price->tenant_id,
                    'product_id' => $price->product_id,
                    'outlet_id' => $target->id,
                    'price' => $price->price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()),
            );
        });
    }

    /**
     * Harga khusus outlet untuk satu produk; null menghapusnya sehingga outlet kembali memakai harga bawaan.
     */
    public function setProductPrice(int $productId, int $outletId, ?int $price): void
    {
        if ($price === null) {
            ProductOutletPrice::query()->where('product_id', $productId)->where('outlet_id', $outletId)->delete();

            return;
        }

        ProductOutletPrice::query()->updateOrCreate(['product_id' => $productId, 'outlet_id' => $outletId], ['price' => $price]);
    }

    /**
     * Baris stok 0 untuk semua produk (termasuk yang sudah dihapus) supaya daftar stok outlet baru lengkap.
     */
    private function seedStocks(Outlet $outlet): void
    {
        DB::table('product_stocks')->insertUsing(
            ['tenant_id', 'product_id', 'outlet_id', 'stock', 'min_stock', 'created_at', 'updated_at'],
            DB::table('products')->where('tenant_id', $outlet->tenant_id)->selectRaw('tenant_id, id, ?, 0, NULL, ?, ?', [$outlet->id, now(), now()]),
        );
    }

    private function assignUnassignedUsersToPrimary(): void
    {
        $primary = Outlet::query()->where('is_primary', true)->first();

        if (! $primary) {
            return;
        }

        $unassigned = User::query()->where('all_outlets', false)
            ->whereNotIn('id', DB::table('outlet_user')->where('tenant_id', $primary->tenant_id)->select('user_id'))
            ->pluck('id');

        foreach ($unassigned->chunk(500) as $chunk) {
            DB::table('outlet_user')->insertOrIgnore($chunk->map(fn ($userId) => [
                'tenant_id' => $primary->tenant_id,
                'outlet_id' => $primary->id,
                'user_id' => $userId,
            ])->all());
        }
    }

    private function tenant(): Tenant
    {
        return app(CurrentTenant::class)->get() ?? throw new \LogicException('Outlet hanya bisa dikelola dalam konteks toko.');
    }

    /**
     * Pembatasan hanya berlaku saat jumlah outlet aktif melebihi batas paket.
     */
    private function ensurePriorityChangeAllowed(): void
    {
        $tenant = $this->tenant()->fresh();
        $overLimit = Outlet::query()->active()->count() > $tenant->maxOutlets();
        $changedAt = $tenant->outlet_priority_changed_at;

        if ($overLimit && $changedAt && $changedAt->gt(now()->subDays(Tenant::OUTLET_PRIORITY_COOLDOWN_DAYS))) {
            $next = $changedAt->copy()->addDays(Tenant::OUTLET_PRIORITY_COOLDOWN_DAYS)->translatedFormat('d F Y');

            throw ValidationException::withMessages(['outlet' => "Outlet yang beroperasi baru bisa diganti lagi mulai {$next} karena jumlah outlet melebihi batas paket."]);
        }
    }

    private function markPriorityChanged(): void
    {
        $tenant = $this->tenant()->fresh();

        if (Outlet::query()->active()->count() > $tenant->maxOutlets()) {
            $tenant->forceFill(['outlet_priority_changed_at' => now()])->save();
        }
    }
}
