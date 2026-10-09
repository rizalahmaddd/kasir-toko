<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

class BuildingSupplyPreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::BuildingSupply;
    }

    protected function rows(): array
    {
        return [
            'Semen & Bata' => [
                ['Semen Portland 40kg', 58000, 65000, 'sak', 10],
                ['Semen Portland 50kg', 68000, 76000, 'sak', 10],
                ['Mortar Perekat Bata Ringan 40kg', 75000, 88000, 'sak', 5],
                PresetProduct::make('Bata Merah', 700, 1000, 'pcs', 500)->units(['seribu', 1000, 950000]),
            ],
            'Besi & Baja' => [
                ['Besi Beton 8mm', 48000, 56000, 'batang', 20],
                ['Besi Beton 10mm', 75000, 86000, 'batang', 20],
                ['Hollow Galvanis 4x4', 35000, 43000, 'batang', 10],
                ['Kawat Bendrat', 24000, 30000, 'kg', 5],
            ],
            'Cat & Pelapis' => [
                ['Cat Tembok Interior 5kg', 85000, 110000, 'galon', 3],
                ['Cat Kayu & Besi 1kg', 55000, 70000, 'kaleng', 3],
                ['Thinner 1L', 18000, 24000, 'kaleng', 3],
                ['Kuas Cat 3 Inci', 8000, 12000, 'pcs', 6],
            ],
            'Pipa & Sanitasi' => [
                ['Pipa PVC 1/2 Inci 4m', 22000, 28000, 'batang', 10],
                ['Pipa PVC 3 Inci 4m', 85000, 105000, 'batang', 5],
                ['Keran Air Plastik', 8000, 13000, 'pcs', 6],
                ['Lem Pipa PVC', 7000, 10000, 'pcs', 6],
            ],
            'Listrik' => [
                PresetProduct::make('Kabel NYA 1,5mm', 4000, 5500, 'meter', 50)->units(['roll', 50, 250000]),
                ['Lampu LED 12 Watt', 15000, 22000, 'pcs', 6],
                ['Stop Kontak Arde', 12000, 18000, 'pcs', 6],
                ['Saklar Tunggal', 9000, 14000, 'pcs', 6],
            ],
            'Paku & Perkakas' => [
                PresetProduct::make('Paku 5cm', 16000, 20000, 'kg', 5)->units(['dus', 25, 475000]),
                ['Meteran 5m', 18000, 27000, 'pcs', 3],
                ['Palu Kambing', 35000, 50000, 'pcs', 2],
                ['Gergaji Kayu', 40000, 58000, 'pcs', 2],
            ],
            'Kayu & Triplek' => [
                ['Triplek 9mm', 110000, 135000, 'lembar', 5],
                ['Kayu Kaso 5/7 4m', 38000, 48000, 'batang', 10],
            ],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.allow_credit' => '1',
            'pos.payment_methods' => '["cash","qris","transfer"]',
            'pos.quick_cash' => '[50000,100000,500000,1000000]',
            'pos.receipt_footer' => 'Periksa barang sebelum meninggalkan toko. Barang yang sudah dibeli tidak dapat dikembalikan.',
        ];
    }

    public function disabledFeatures(): array
    {
        return ['pos.customer-display'];
    }

    public function capabilities(): array
    {
        return ['business.product-attributes', 'business.multi-unit', 'business.tiered-price', 'business.delivery-note'];
    }

    public function productAttributes(): array
    {
        return [
            new AttributeField('brand', 'Merek', searchable: true),
            new AttributeField('specification', 'Ukuran / spesifikasi', searchable: true, placeholder: 'mis. 10mm x 12m, 1/2 inci'),
        ];
    }

    public function suggestedUnits(): array
    {
        return ['pcs', 'sak', 'kg', 'batang', 'meter', 'lembar', 'dus', 'roll', 'kubik', 'galon', 'kaleng', 'set'];
    }
}
