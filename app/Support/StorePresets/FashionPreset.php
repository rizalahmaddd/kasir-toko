<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

class FashionPreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::Fashion;
    }

    protected function rows(): array
    {
        return [
            'Atasan Pria' => [
                PresetProduct::make('Kaos Polos Cotton Combed 30s', 32000, 65000, 'pcs', 6)->variants(['Ukuran' => ['S', 'M', 'L', 'XL'], 'Warna' => ['Hitam', 'Putih']]),
                ['Kemeja Flanel Pria', 65000, 135000, 'pcs', 3],
                ['Kemeja Batik Lengan Pendek', 75000, 150000, 'pcs', 3],
                ['Polo Shirt Pria', 55000, 110000, 'pcs', 3],
            ],
            'Atasan Wanita' => [
                ['Blouse Wanita Rayon', 48000, 99000, 'pcs', 3],
                ['Tunik Wanita', 60000, 125000, 'pcs', 3],
                ['Cardigan Rajut', 55000, 115000, 'pcs', 3],
            ],
            'Bawahan' => [
                PresetProduct::make('Celana Jeans Pria', 95000, 189000, 'pcs', 3)->variants(['Ukuran' => ['28', '30', '32', '34']]),
                ['Celana Chino Pria', 75000, 149000, 'pcs', 3],
                ['Celana Kulot Wanita', 50000, 99000, 'pcs', 3],
                ['Rok Plisket', 45000, 95000, 'pcs', 3],
            ],
            'Busana Muslim' => [
                ['Gamis Wanita', 110000, 225000, 'pcs', 2],
                ['Baju Koko Pria', 75000, 150000, 'pcs', 3],
                ['Hijab Segi Empat Voal', 25000, 55000, 'pcs', 6],
                ['Hijab Bergo Instan', 20000, 45000, 'pcs', 6],
            ],
            'Pakaian Anak' => [
                ['Kaos Anak', 20000, 45000, 'pcs', 6],
                ['Setelan Anak', 45000, 95000, 'pcs', 3],
                ['Dress Anak', 50000, 105000, 'pcs', 3],
            ],
            'Aksesoris' => [
                ['Kaos Kaki', 5000, 12000, 'pasang', 12],
                ['Ikat Pinggang Kulit', 35000, 75000, 'pcs', 3],
                ['Topi Baseball', 25000, 55000, 'pcs', 3],
                ['Dompet Pria', 40000, 85000, 'pcs', 3],
            ],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.quick_cash' => '[50000,100000,200000,300000]',
            'pos.receipt_footer' => 'Penukaran barang maksimal 3 hari dengan struk dan label yang masih utuh.',
        ];
    }

    public function disabledFeatures(): array
    {
        return ['pos.receivables'];
    }

    public function capabilities(): array
    {
        return ['business.product-attributes', 'business.variants'];
    }

    public function productAttributes(): array
    {
        return [
            new AttributeField('brand', 'Merek', searchable: true),
            new AttributeField('material', 'Bahan', placeholder: 'mis. katun combed 30s'),
            new AttributeField('gender', 'Untuk', 'select', ['pria' => 'Pria', 'wanita' => 'Wanita', 'unisex' => 'Unisex', 'anak' => 'Anak']),
            new AttributeField('size', 'Ukuran', placeholder: 'mis. M, L, XL, 32'),
            new AttributeField('color', 'Warna'),
        ];
    }

    public function suggestedUnits(): array
    {
        return ['pcs', 'pasang', 'lusin', 'kodi'];
    }
}
