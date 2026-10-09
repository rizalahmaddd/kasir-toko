<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

class BakeryPreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::Bakery;
    }

    protected function rows(): array
    {
        return [
            'Roti Manis' => [
                ['Roti Cokelat Keju', 3000, 7000, 'pcs', null],
                ['Roti Sosis', 4500, 10000, 'pcs', null],
                ['Roti Abon', 4500, 10000, 'pcs', null],
                ['Donat Gula', 2000, 5000, 'pcs', null],
                ['Croissant Butter', 7000, 16000, 'pcs', null],
            ],
            'Roti Tawar' => [
                ['Roti Tawar Kupas', 9000, 18000, 'bks', null],
                ['Roti Tawar Gandum', 11000, 22000, 'bks', null],
            ],
            'Kue Potong' => [
                ['Brownies Panggang Potong', 4500, 10000, 'pcs', null],
                ['Bolu Pandan Potong', 3500, 8000, 'pcs', null],
                ['Cheese Cake Slice', 12000, 28000, 'pcs', null],
                ['Lapis Legit Potong', 8000, 18000, 'pcs', null],
            ],
            'Kue Ulang Tahun' => [
                ['Kue Ulang Tahun 16cm', 90000, 175000, 'pcs', null],
                ['Kue Ulang Tahun 20cm', 130000, 250000, 'pcs', null],
                ['Kue Ulang Tahun 24cm', 180000, 350000, 'pcs', null],
            ],
            'Kue Kering' => [
                PresetProduct::make('Nastar 500g', 65000, 110000, 'toples', 3)->batch(),
                PresetProduct::make('Kastengel 500g', 70000, 120000, 'toples', 3)->batch(),
                PresetProduct::make('Putri Salju 500g', 55000, 95000, 'toples', 3)->batch(),
            ],
            'Minuman' => [
                ['Kopi Susu Dingin', 6000, 15000, 'cup', null],
                ['Teh Manis Dingin', 2000, 6000, 'cup', null],
            ],
            'Perlengkapan Kue' => [
                ['Lilin Angka', 2000, 5000, 'pcs', 10],
                ['Kotak Kue 20x20', 3000, 5000, 'pcs', 10],
            ],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.quick_cash' => '[20000,50000,100000,200000]',
            'pos.receipt_footer' => 'Terima kasih. Roti dan kue paling enak dinikmati di hari yang sama.',
        ];
    }

    public function disabledFeatures(): array
    {
        return ['pos.receivables'];
    }

    public function capabilities(): array
    {
        return ['business.product-attributes', 'business.batch-expiry', 'business.modifiers', 'business.pre-order'];
    }

    public function productAttributes(): array
    {
        return [
            new AttributeField('allergens', 'Info alergen', placeholder: 'mis. susu, kacang', onReceipt: false),
            new AttributeField('shelf_life_days', 'Umur simpan (hari)', 'number'),
        ];
    }

    public function suggestedUnits(): array
    {
        return ['pcs', 'loyang', 'potong', 'box', 'pak'];
    }

    public function modifierGroups(): array
    {
        return [
            new PresetModifierGroup('Hiasan Kue', 0, null, [['Tulisan Cokelat', 10000], ['Lilin Angka', 5000], ['Topper', 15000]], ['Kue Ulang Tahun']),
        ];
    }
}
