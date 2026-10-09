<?php

namespace App\Services;

use App\Models\CustomerOrder;
use App\Models\DeliveryNote;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductComponent;
use App\Models\ProductPriceTier;
use App\Models\ProductSerial;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Scopes\OutletAccessScope;
use App\Services\Pos\BatchService;
use App\Support\Features;
use App\Support\OutletFeatures;

/**
 * Sakelar kapabilitas usaha (Features modul business). Semua perubahan lewat sini supaya efek
 * sampingnya ikut jalan, mis. batch disamakan dengan stok saat Batch & Kedaluwarsa dinyalakan.
 */
class BusinessCapabilities
{
    public function __construct(private BatchService $batches) {}

    /**
     * Sakelar level toko (Pengaturan Fitur, preset onboarding). Kapabilitas yang baru dinyalakan
     * ikut menyala di semua outlet, yang dimatikan ikut mati di semua outlet.
     *
     * @param  list<string>  $enabled  daftar lengkap kapabilitas yang menyala setelah ini
     */
    public function sync(array $enabled): void
    {
        $before = Features::enabledKeys();
        $enabled = array_values(array_intersect(Features::optInFeatures(), $enabled));
        $added = array_diff($enabled, $before);
        $removed = array_diff($before, $enabled);

        if ($added !== [] || $removed !== []) {
            Outlet::query()->whereNotNull('capabilities')->get()->each(fn (Outlet $outlet) => $this->store(
                $outlet,
                array_diff([...$outlet->capabilities, ...$added], $removed),
            ));
        }

        $this->publish($enabled, $before);
    }

    /**
     * Kapabilitas satu outlet. Daftar toko menjadi gabungan semua outlet, sehingga master data
     * (form produk, laporan) tetap menampilkan field yang dipakai outlet mana pun.
     *
     * @param  list<string>  $enabled
     */
    public function syncOutlet(Outlet $outlet, array $enabled): void
    {
        $before = Features::enabledKeys();

        // Outlet yang masih mengikuti daftar toko dibekukan dulu, supaya kapabilitas yang baru
        // dinyalakan untuk outlet ini tidak ikut menyala di sana.
        Outlet::query()->whereNull('capabilities')->whereKeyNot($outlet->id)->get()
            ->each(fn (Outlet $other) => $this->store($other, $before));

        $this->store($outlet, $enabled);
        $this->refreshStoreList();
    }

    /**
     * Hitung ulang daftar toko dari outlet, mis. setelah outlet dihapus.
     */
    public function refreshStoreList(): void
    {
        $before = Features::enabledKeys();
        $outlets = Outlet::query()->get(['id', 'capabilities']);

        if ($outlets->isEmpty() || $outlets->every(fn (Outlet $outlet) => $outlet->capabilities === null)) {
            return;
        }

        $union = $outlets->flatMap(fn (Outlet $outlet) => $outlet->capabilities ?? $before)->unique()->all();

        $this->publish(array_values(array_intersect(Features::optInFeatures(), $union)), $before);
    }

    /**
     * @param  list<string>  $enabled
     * @param  list<string>  $before
     */
    private function publish(array $enabled, array $before): void
    {
        Features::setEnabled($enabled);
        $turnedOn = array_diff(Features::enabledKeys(), $before);

        if (in_array('business.batch-expiry', $turnedOn, true)) {
            $this->batches->reconcileAll();
        }
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function store(Outlet $outlet, array $keys): void
    {
        $outlet->forceFill(['capabilities' => array_values(array_intersect(Features::optInFeatures(), $keys))])->saveQuietly();
        OutletFeatures::flush();
    }

    /**
     * @param  list<string>  $keys
     */
    public function enable(array $keys): void
    {
        $this->sync([...Features::enabledKeys(), ...$keys]);
    }

    /**
     * Kapabilitas yang sudah punya data tersimpan; preset tidak mematikannya.
     *
     * @return list<string>
     */
    public function withData(): array
    {
        $checks = [
            'business.product-attributes' => fn () => Product::query()->whereNotNull('custom_attributes')->exists(),
            'business.multi-unit' => fn () => ProductUnit::query()->exists(),
            'business.batch-expiry' => fn () => ProductBatch::query()->whereNotNull('batch_number')->exists(),
            'business.prescription' => fn () => Prescription::query()->exists(),
            'business.components' => fn () => ProductComponent::query()->exists(),
            'business.modifiers' => fn () => ModifierGroup::query()->exists(),
            'business.tiered-price' => fn () => ProductPriceTier::query()->exists(),
            'business.order-type' => fn () => Sale::query()->whereNotNull('order_type')->exists(),
            'business.variants' => fn () => Product::query()->whereNotNull('parent_id')->exists(),
            'business.serial-number' => fn () => ProductSerial::query()->exists(),
            'business.pre-order' => fn () => CustomerOrder::query()->withoutGlobalScopes([OutletAccessScope::class])->exists(),
            'business.delivery-note' => fn () => DeliveryNote::query()->withoutGlobalScopes([OutletAccessScope::class])->exists(),
        ];

        return array_keys(array_filter($checks, fn (callable $check) => $check()));
    }
}
