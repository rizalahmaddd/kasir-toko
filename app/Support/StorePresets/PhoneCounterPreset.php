<?php

namespace App\Support\StorePresets;

use App\Enums\StoreType;

class PhoneCounterPreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::PhoneCounter;
    }

    protected function rows(): array
    {
        return [
            'Pulsa' => [
                ['Pulsa 5.000', 5200, 7000, 'pcs', null],
                ['Pulsa 10.000', 10200, 12000, 'pcs', null],
                ['Pulsa 20.000', 19900, 22000, 'pcs', null],
                ['Pulsa 25.000', 24900, 27000, 'pcs', null],
                ['Pulsa 50.000', 49500, 52000, 'pcs', null],
                ['Pulsa 100.000', 98500, 102000, 'pcs', null],
            ],
            'Paket Data' => [
                ['Paket Data 10GB 30 Hari', 48000, 55000, 'pcs', null],
                ['Paket Data 25GB 30 Hari', 85000, 95000, 'pcs', null],
            ],
            'Token Listrik' => [
                ['Token Listrik 20.000', 21500, 23000, 'pcs', null],
                ['Token Listrik 50.000', 51500, 53000, 'pcs', null],
                ['Token Listrik 100.000', 101500, 103000, 'pcs', null],
            ],
            'Kartu Perdana & Voucher' => [
                ['Kartu Perdana Kosong', 5000, 10000, 'pcs', 5],
                ['Voucher Data Fisik 5GB', 20000, 25000, 'pcs', 5],
            ],
            'Aksesoris HP' => [
                ['Charger Fast Charging 18W', 35000, 65000, 'pcs', 3],
                ['Kabel Data Type-C', 12000, 25000, 'pcs', 5],
                ['Earphone', 15000, 35000, 'pcs', 3],
                ['Tempered Glass', 5000, 20000, 'pcs', 10],
                ['Softcase HP', 8000, 25000, 'pcs', 10],
                PresetProduct::make('Power Bank 10.000mAh', 110000, 165000, 'pcs', 2)->serial(180),
                ['Kartu Memori 32GB', 45000, 75000, 'pcs', 3],
            ],
            'Jasa' => [
                ['Jasa Pasang Tempered Glass', 0, 5000, 'pcs', null],
                ['Jasa Instal Ulang HP', 0, 50000, 'pcs', null],
                ['Jasa Servis HP', 0, 75000, 'pcs', null],
            ],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.allow_credit' => '1',
            'pos.quick_cash' => '[10000,20000,50000,100000]',
            'pos.receipt_footer' => 'Pulsa dan token yang sudah masuk tidak dapat dibatalkan. Simpan struk untuk klaim garansi aksesoris.',
        ];
    }

    public function disabledFeatures(): array
    {
        return ['pos.customer-display'];
    }

    public function capabilities(): array
    {
        return ['business.product-attributes', 'business.serial-number', 'business.pre-order'];
    }

    public function productAttributes(): array
    {
        return [
            new AttributeField('brand', 'Merek', searchable: true),
            new AttributeField('model', 'Tipe / model', searchable: true),
            new AttributeField('capacity', 'Kapasitas', placeholder: 'mis. 128 GB, 10.000 mAh'),
            new AttributeField('warranty_days', 'Garansi (hari)', 'number', onReceipt: true),
        ];
    }

    public function suggestedUnits(): array
    {
        return ['pcs', 'unit', 'voucher', 'set'];
    }
}
