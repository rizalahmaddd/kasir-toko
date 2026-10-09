<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

class MinimarketPreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::Minimarket;
    }

    protected function rows(): array
    {
        return [
            'Minuman' => [
                PresetProduct::make('Air Mineral 1500ml', 4500, 6500, 'btl', 12)->units(['dus', 12, 72000]),
                ['Teh Botol 350ml', 3800, 5500, 'btl', 12],
                ['Kopi Susu Kaleng 240ml', 6500, 9000, 'kaleng', 6],
                ['Minuman Isotonik 500ml', 5200, 7500, 'btl', 6],
                PresetProduct::make('Susu UHT Full Cream 1L', 17500, 21500, 'pcs', 4)->batch(),
            ],
            'Makanan Ringan' => [
                ['Keripik Kentang 68g', 8500, 11500, 'bks', 6],
                ['Biskuit Cokelat 120g', 7200, 10000, 'bks', 6],
                ['Cokelat Batang 62g', 9500, 12500, 'pcs', 6],
                ['Permen Mint Roll', 4000, 6000, 'pcs', 6],
            ],
            'Sembako' => [
                ['Beras Premium 5kg', 69000, 78000, 'karung', 3],
                ['Minyak Goreng Pouch 2L', 34000, 39500, 'bks', 4],
                ['Gula Pasir 1kg', 16500, 18900, 'bks', 6],
                PresetProduct::make('Mi Instan Goreng', 2900, 3600, 'bks', 24)->units(['dus', 40, 135000])->batch(),
            ],
            'Makanan Beku' => [
                PresetProduct::make('Nugget Ayam 500g', 36000, 44500, 'bks', 3)->batch(),
                PresetProduct::make('Sosis Sapi 500g', 32000, 39000, 'bks', 3)->batch(),
                ['Es Krim Cup', 4500, 7000, 'cup', 6],
            ],
            'Perawatan Diri' => [
                ['Pasta Gigi 190g', 11500, 14900, 'pcs', 4],
                ['Sampo 170ml', 19000, 24500, 'btl', 4],
                ['Sabun Cair Refill 450ml', 18500, 23900, 'bks', 4],
                ['Pembalut Wanita isi 10', 9500, 12500, 'bks', 4],
            ],
            'Kebersihan Rumah' => [
                ['Deterjen Cair Refill 750ml', 16500, 21000, 'bks', 4],
                ['Pembersih Lantai 780ml', 12500, 16500, 'bks', 4],
                ['Tisu Gulung isi 4', 14000, 18500, 'pak', 4],
            ],
            'Kebutuhan Bayi' => [
                ['Popok Bayi M isi 20', 52000, 62000, 'pak', 3],
                ['Minyak Telon 60ml', 14500, 18500, 'btl', 3],
            ],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.receipt_footer' => 'Terima kasih. Simpan struk ini sebagai bukti pembayaran yang sah.',
        ];
    }

    public function disabledFeatures(): array
    {
        return ['pos.receivables'];
    }

    public function capabilities(): array
    {
        return ['business.product-attributes', 'business.multi-unit', 'business.batch-expiry', 'business.tiered-price'];
    }

    public function productAttributes(): array
    {
        return [
            new AttributeField('brand', 'Merek', searchable: true),
            new AttributeField('size', 'Ukuran / isi', placeholder: 'mis. 600 ml'),
        ];
    }

    public function suggestedUnits(): array
    {
        return ['pcs', 'bks', 'btl', 'kaleng', 'dus', 'pak', 'karung', 'kg', 'liter'];
    }
}
