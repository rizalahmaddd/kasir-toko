<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

class RestaurantPreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::Restaurant;
    }

    protected function rows(): array
    {
        return [
            'Paket Nasi' => [
                ['Nasi Ayam Goreng', 13000, 25000, 'porsi', null],
                ['Nasi Ayam Bakar', 14000, 27000, 'porsi', null],
                ['Nasi Bebek Goreng', 20000, 38000, 'porsi', null],
                ['Nasi Rawon', 15000, 30000, 'porsi', null],
                ['Gurame Bakar', 35000, 75000, 'porsi', null],
            ],
            'Lauk Pauk' => [
                ['Ayam Goreng', 9000, 18000, 'porsi', null],
                ['Sate Ayam 10 Tusuk', 14000, 30000, 'porsi', null],
                ['Telur Dadar', 3000, 7000, 'porsi', null],
                ['Tempe Goreng', 1000, 3000, 'pcs', null],
                ['Tahu Goreng', 1000, 3000, 'pcs', null],
            ],
            'Sayur & Sup' => [
                ['Sayur Asem', 4000, 10000, 'porsi', null],
                ['Cah Kangkung', 5000, 15000, 'porsi', null],
                ['Sayur Lodeh', 4000, 10000, 'porsi', null],
                ['Sop Buntut', 35000, 65000, 'porsi', null],
            ],
            'Nasi & Pelengkap' => [
                ['Nasi Putih', 2500, 6000, 'porsi', null],
                ['Sambal Terasi', 1000, 4000, 'porsi', null],
                ['Kerupuk', 1000, 3000, 'pcs', null],
            ],
            'Minuman' => [
                ['Es Teh Manis', 1500, 6000, 'gelas', null],
                ['Es Jeruk', 3000, 10000, 'gelas', null],
                ['Jus Alpukat', 8000, 18000, 'gelas', null],
                ['Kopi Hitam', 2000, 6000, 'gelas', null],
                ['Air Mineral 600ml', 2600, 6000, 'btl', 12],
            ],
            'Pencuci Mulut' => [
                ['Es Campur', 6000, 15000, 'porsi', null],
                ['Pisang Bakar Cokelat Keju', 6000, 17000, 'porsi', null],
            ],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.tax_enabled' => '1',
            'pos.tax_rate' => '10',
            'pos.tax_label' => 'PB1',
            'pos.quick_cash' => '[50000,100000,150000,200000]',
            'pos.receipt_footer' => 'Terima kasih, selamat menikmati. Kritik dan saran silakan sampaikan ke kasir.',
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
            new AttributeField('spicy_level', 'Level pedas', 'select', ['0' => 'Tidak pedas', '1' => 'Sedang', '2' => 'Pedas', '3' => 'Sangat pedas']),
        ];
    }

    public function suggestedUnits(): array
    {
        return ['porsi', 'gelas', 'pcs', 'botol', 'paket'];
    }

    public function modifierGroups(): array
    {
        return [
            new PresetModifierGroup('Level Pedas', 0, 1, [['Tidak Pedas', 0], ['Sedang', 0], ['Pedas', 0], ['Extra Pedas', 2000]], ['Paket Nasi', 'Lauk Pauk']),
            new PresetModifierGroup('Tambahan', 0, null, [['Nasi', 6000], ['Telur Ceplok', 7000], ['Sambal', 4000]], ['Paket Nasi']),
        ];
    }

    public function extraRoles(): array
    {
        return ['dapur' => ['kitchen.view']];
    }
}
