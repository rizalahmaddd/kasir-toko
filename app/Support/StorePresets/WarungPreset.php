<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

class WarungPreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::Warung;
    }

    protected function rows(): array
    {
        return [
            'Sembako' => [
                ['Beras Medium 1kg', 12500, 14000, 'kg', 10],
                ['Gula Pasir 1kg', 16500, 18500, 'kg', 5],
                ['Minyak Goreng Pouch 1L', 17000, 19500, 'bks', 6],
                ['Telur Ayam', 28000, 31000, 'kg', 3],
                ['Tepung Terigu 1kg', 11500, 13500, 'bks', 3],
                ['Garam Dapur 250g', 2500, 3500, 'bks', 5],
            ],
            'Mi & Makanan Instan' => [
                PresetProduct::make('Mi Instan Goreng', 2900, 3500, 'bks', 20)->units(['dus', 40, 125000]),
                ['Mi Instan Kuah Ayam Bawang', 2700, 3300, 'bks', 20],
                ['Sarden Kaleng 155g', 9500, 12000, 'kaleng', 3],
            ],
            'Minuman' => [
                PresetProduct::make('Air Mineral 600ml', 2600, 4000, 'btl', 12)->units(['dus', 24, 85000]),
                ['Teh Kotak 200ml', 3000, 4000, 'pcs', 12],
                PresetProduct::make('Kopi Susu Sachet', 1300, 2000, 'sachet', 20)->units(['renteng', 10, 18000]),
                ['Susu Kental Manis Sachet', 1400, 2000, 'sachet', 10],
            ],
            'Makanan Ringan' => [
                ['Wafer Cokelat', 1500, 2000, 'pcs', 12],
                ['Kacang Kulit 70g', 5000, 7000, 'bks', 5],
                ['Keripik Singkong 100g', 4000, 6000, 'bks', 5],
            ],
            'Rokok' => [
                PresetProduct::make('Rokok Kretek Filter isi 12', 24500, 27000, 'bks', 5)->units(['slop', 10, 265000]),
                ['Rokok Putih isi 16', 31000, 34000, 'bks', 3],
                ['Rokok Kretek Eceran', 2100, 2500, 'batang', null],
            ],
            'Kebutuhan Rumah' => [
                ['Sabun Cuci Piring 400ml', 7000, 9000, 'bks', 3],
                ['Deterjen Bubuk Sachet', 1100, 1500, 'sachet', 12],
                ['Sabun Mandi Batang', 3500, 5000, 'pcs', 6],
                ['Sampo Sachet', 900, 1500, 'sachet', 12],
                ['Obat Nyamuk Bakar', 5000, 7000, 'box', 3],
            ],
            'Gas & Air' => [
                ['Gas LPG 3kg (isi ulang)', 19000, 22000, 'tabung', 2],
                ['Isi Ulang Air Galon', 4000, 7000, 'galon', null],
            ],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.allow_credit' => '1',
            'pos.allow_negative_stock' => '1',
            'pos.payment_methods' => '["cash","qris"]',
            'pos.quick_cash' => '[5000,10000,20000,50000]',
            'pos.receipt_footer' => 'Terima kasih sudah belanja. Kasbon dicatat dan bisa dicek kapan saja.',
        ];
    }

    public function disabledFeatures(): array
    {
        return ['pos.customer-display'];
    }

    public function capabilities(): array
    {
        return ['business.product-attributes', 'business.multi-unit', 'business.tiered-price'];
    }

    public function productAttributes(): array
    {
        return [
            new AttributeField('brand', 'Merek', searchable: true),
        ];
    }

    public function suggestedUnits(): array
    {
        return ['pcs', 'bks', 'btl', 'dus', 'pak', 'renteng', 'slop', 'batang', 'kg', 'liter', 'sachet', 'tabung'];
    }
}
