<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

class CafePreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::Cafe;
    }

    protected function rows(): array
    {
        return [
            'Kopi' => [
                ['Espresso', 6000, 18000, 'cup', null],
                ['Americano', 7000, 22000, 'cup', null],
                ['Cafe Latte', 9500, 28000, 'cup', null],
                ['Cappuccino', 9500, 28000, 'cup', null],
                ['Kopi Susu Gula Aren', 8500, 24000, 'cup', null],
                ['Caramel Macchiato', 11000, 32000, 'cup', null],
                ['Manual Brew V60', 10000, 30000, 'cup', null],
            ],
            'Non-Kopi' => [
                ['Cokelat', 9000, 26000, 'cup', null],
                ['Matcha Latte', 11000, 30000, 'cup', null],
                ['Red Velvet Latte', 10000, 28000, 'cup', null],
            ],
            'Teh' => [
                ['Teh Tarik', 6000, 20000, 'cup', null],
                ['Lemon Tea', 5000, 18000, 'cup', null],
                ['Lychee Tea', 7000, 22000, 'cup', null],
            ],
            'Camilan' => [
                ['Kentang Goreng', 8000, 22000, 'porsi', null],
                ['Pisang Goreng Keju', 7000, 20000, 'porsi', null],
                ['Roti Bakar Cokelat', 8000, 22000, 'porsi', null],
                ['Croissant Butter', 12000, 25000, 'pcs', null],
            ],
            'Makanan' => [
                ['Nasi Goreng Kampung', 12000, 32000, 'porsi', null],
                ['Mie Goreng Spesial', 11000, 30000, 'porsi', null],
                ['Chicken Katsu Rice', 16000, 38000, 'porsi', null],
            ],
            'Tambahan' => [
                ['Extra Shot Espresso', 3000, 6000, 'shot', null],
                ['Air Mineral 600ml', 2600, 8000, 'btl', 12],
            ],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.tax_enabled' => '1',
            'pos.tax_rate' => '10',
            'pos.tax_label' => 'PB1',
            'pos.quick_cash' => '[20000,50000,100000,200000]',
            'pos.receipt_footer' => 'Terima kasih sudah mampir. Sampai jumpa di kunjungan berikutnya.',
        ];
    }

    public function disabledFeatures(): array
    {
        return ['pos.receivables'];
    }

    public function capabilities(): array
    {
        return ['business.product-attributes', 'business.modifiers', 'business.order-type'];
    }

    public function productAttributes(): array
    {
        return [
            new AttributeField('allergens', 'Info alergen', placeholder: 'mis. susu, kacang', onReceipt: false),
            new AttributeField('caffeine', 'Kafein', 'select', ['none' => 'Tanpa kafein', 'low' => 'Rendah', 'normal' => 'Normal', 'high' => 'Tinggi']),
        ];
    }

    public function suggestedUnits(): array
    {
        return ['cup', 'gelas', 'porsi', 'pcs', 'botol'];
    }

    public function modifierGroups(): array
    {
        return [
            new PresetModifierGroup('Ukuran', 1, 1, [['Regular', 0], ['Large', 5000]], ['Kopi', 'Non-Kopi', 'Teh']),
            new PresetModifierGroup('Gula', 0, 1, [['Normal', 0], ['Less Sugar', 0], ['No Sugar', 0]], ['Kopi', 'Non-Kopi', 'Teh']),
            new PresetModifierGroup('Es', 0, 1, [['Normal Ice', 0], ['Less Ice', 0], ['Hot', 0]], ['Kopi', 'Non-Kopi', 'Teh']),
            new PresetModifierGroup('Tambahan Kopi', 0, null, [['Extra Shot', 6000], ['Oat Milk', 8000], ['Sirup Vanilla', 5000]], ['Kopi']),
        ];
    }

    public function extraRoles(): array
    {
        return ['dapur' => ['kitchen.view']];
    }
}
