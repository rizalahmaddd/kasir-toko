<?php

namespace App\Support\StorePresets;

use App\Enums\DrugClass;
use App\Enums\StoreType;

class PharmacyPreset extends StorePreset
{
    public function type(): StoreType
    {
        return StoreType::Pharmacy;
    }

    protected function rows(): array
    {
        return [
            'Demam & Nyeri' => [
                $this->tablet('Paracetamol 500mg Tablet', 'Paracetamol', '500 mg', DrugClass::OverTheCounter, 300, 500, 45000),
                $this->drug('Paracetamol Sirup Anak 60ml', 8500, 13000, 'botol', 3, DrugClass::OverTheCounter, 'Paracetamol', '120 mg/5 ml', 'sirup', '3 x sehari 1 sendok takar sesudah makan'),
                $this->tablet('Ibuprofen 200mg Tablet', 'Ibuprofen', '200 mg', DrugClass::LimitedOverTheCounter, 600, 950, 85000),
                PresetProduct::make('Plester Kompres Demam', 8000, 12000, 'box', 3)->drug(DrugClass::MedicalDevice),
            ],
            'Batuk & Flu' => [
                $this->drug('Obat Batuk Sirup 100ml', 14000, 19500, 'botol', 3, DrugClass::LimitedOverTheCounter, 'Dekstrometorfan HBr', '15 mg/5 ml', 'sirup', '3 x sehari 1 sendok takar'),
                $this->tablet('Obat Flu Tablet', 'Paracetamol, Pseudoefedrin, Klorfeniramin', '500/30/2 mg', DrugClass::LimitedOverTheCounter, 250, 400, 36000),
                PresetProduct::make('Tablet Hisap Pelega Tenggorokan', 8000, 12000, 'box', 4)->drug(DrugClass::OverTheCounter),
                PresetProduct::make('Minyak Kayu Putih 60ml', 19000, 24500, 'botol', 4)->drug(DrugClass::OverTheCounter),
            ],
            'Pencernaan' => [
                $this->tablet('Antasida Tablet Kunyah', 'Aluminium hidroksida, Magnesium hidroksida', '200/200 mg', DrugClass::OverTheCounter, 500, 800, 72000),
                PresetProduct::make('Oralit Sachet', 1000, 2000, 'sachet', 10)->drug(DrugClass::OverTheCounter)->attributes(['active_ingredient' => 'Natrium klorida, Kalium klorida, Glukosa', 'dosage_form' => 'serbuk'])->batch(),
                $this->tablet('Obat Diare Tablet', 'Attapulgit', '600 mg', DrugClass::OverTheCounter, 500, 800, 72000),
            ],
            'Vitamin & Suplemen' => [
                PresetProduct::make('Vitamin C 1000mg Effervescent', 17000, 23500, 'tube', 3)->drug(DrugClass::Supplement)->attributes(['active_ingredient' => 'Asam askorbat', 'strength' => '1000 mg'])->batch(),
                PresetProduct::make('Multivitamin isi 30 Tablet', 45000, 58000, 'box', 2)->drug(DrugClass::Supplement)->batch(),
                PresetProduct::make('Vitamin Anak Sirup 60ml', 22000, 29500, 'botol', 2)->drug(DrugClass::Supplement)->batch(),
            ],
            'Obat Keras (Resep)' => [
                $this->tablet('Amlodipine 5mg Tablet', 'Amlodipine besilat', '5 mg', DrugClass::Prescription, 200, 500, 45000),
                $this->tablet('Metformin 500mg Tablet', 'Metformin HCl', '500 mg', DrugClass::Prescription, 150, 400, 36000),
                $this->tablet('Omeprazole 20mg Kapsul', 'Omeprazole', '20 mg', DrugClass::Prescription, 400, 900, 80000, 'kapsul'),
            ],
            'Antibiotik (Resep)' => [
                $this->tablet('Amoxicillin 500mg Kapsul', 'Amoxicillin trihidrat', '500 mg', DrugClass::Prescription, 400, 800, 72000, 'kapsul'),
                $this->tablet('Cefadroxil 500mg Kapsul', 'Cefadroxil', '500 mg', DrugClass::Prescription, 1200, 2500, 230000, 'kapsul'),
            ],
            'Obat Kulit & Mata' => [
                $this->drug('Salep Antijamur 10g', 9000, 14000, 'tube', 3, DrugClass::LimitedOverTheCounter, 'Mikonazol nitrat', '2%', 'salep', 'Oleskan tipis 2 x sehari'),
                $this->drug('Tetes Mata 15ml', 12000, 17500, 'botol', 3, DrugClass::LimitedOverTheCounter, 'Tetrahidrozolin HCl', '0,05%', 'tetes', '3 x sehari 1-2 tetes'),
            ],
            'P3K & Perawatan Luka' => [
                PresetProduct::make('Plester Luka isi 10', 3500, 5500, 'pak', 5)->drug(DrugClass::MedicalDevice),
                PresetProduct::make('Kasa Steril', 5000, 8000, 'pak', 5)->drug(DrugClass::MedicalDevice),
                PresetProduct::make('Povidone Iodine 30ml', 12000, 16500, 'botol', 3)->drug(DrugClass::OverTheCounter)->batch(),
                PresetProduct::make('Alkohol 70% 100ml', 6000, 9500, 'botol', 3)->drug(DrugClass::OverTheCounter),
                PresetProduct::make('Perban Elastis', 12000, 18000, 'pcs', 2)->drug(DrugClass::MedicalDevice),
            ],
            'Alat Kesehatan' => [
                PresetProduct::make('Masker Medis 3 Ply', 450, 700, 'pcs', 50)->drug(DrugClass::MedicalDevice)->units(['box', 50, 32000]),
                PresetProduct::make('Termometer Digital', 25000, 38000, 'pcs', 2)->drug(DrugClass::MedicalDevice),
                PresetProduct::make('Alat Tes Kehamilan', 5000, 12000, 'pcs', 3)->drug(DrugClass::MedicalDevice),
            ],
            'Ibu & Bayi' => [
                PresetProduct::make('Minyak Telon 60ml', 14500, 18500, 'botol', 3)->drug(DrugClass::Cosmetic),
                PresetProduct::make('Bedak Bayi 100g', 9000, 13000, 'pcs', 3)->drug(DrugClass::Cosmetic),
            ],
            'Herbal & Jamu' => [
                PresetProduct::make('Jamu Masuk Angin Sachet', 2500, 4000, 'sachet', 10)->drug(DrugClass::OverTheCounter)->units(['box', 12, 45000])->batch(),
                PresetProduct::make('Madu Herbal 250ml', 35000, 48000, 'botol', 2)->drug(DrugClass::Supplement)->batch(),
            ],
            'Kontrasepsi' => [
                PresetProduct::make('Kondom isi 3', 15000, 22000, 'box', 3)->drug(DrugClass::MedicalDevice)->batch(),
                $this->tablet('Pil KB 28 Tablet', 'Levonorgestrel, Etinilestradiol', '0,15/0,03 mg', DrugClass::Prescription, 150, 300, null, 'tablet', [['strip', 28, 8000]], '1 x sehari 1 tablet pada jam yang sama'),
            ],
        ];
    }

    public function settings(): array
    {
        return [
            'pos.quick_cash' => '[10000,20000,50000,100000]',
            'pos.receipt_footer' => 'Semoga lekas sembuh. Baca aturan pakai sebelum minum obat.',
            'pos.expiry_warning_days' => '90',
        ];
    }

    public function disabledFeatures(): array
    {
        return ['pos.receivables'];
    }

    public function capabilities(): array
    {
        return ['business.product-attributes', 'business.multi-unit', 'business.batch-expiry', 'business.prescription', 'business.components'];
    }

    public function productAttributes(): array
    {
        return [
            new AttributeField('active_ingredient', 'Zat aktif / komposisi', searchable: true, onReceipt: false, placeholder: 'mis. Paracetamol'),
            new AttributeField('strength', 'Kekuatan / dosis', placeholder: 'mis. 500 mg'),
            new AttributeField('dosage_form', 'Bentuk sediaan', 'select', [
                'tablet' => 'Tablet', 'kaplet' => 'Kaplet', 'kapsul' => 'Kapsul', 'sirup' => 'Sirup', 'salep' => 'Salep',
                'krim' => 'Krim', 'tetes' => 'Tetes', 'injeksi' => 'Injeksi', 'serbuk' => 'Serbuk', 'lainnya' => 'Lainnya',
            ]),
            new AttributeField('nie', 'No. izin edar (NIE BPOM)', placeholder: 'mis. DBL1234567890A1'),
            new AttributeField('manufacturer', 'Produsen / pabrik', searchable: true),
            new AttributeField('shelf_location', 'Lokasi rak', placeholder: 'mis. Rak B2'),
            new AttributeField('default_dosage', 'Aturan pakai default', placeholder: 'mis. 3 x sehari 1 tablet sesudah makan'),
        ];
    }

    public function suggestedUnits(): array
    {
        return ['tablet', 'kaplet', 'kapsul', 'strip', 'blister', 'box', 'botol', 'tube', 'sachet', 'ampul', 'pcs'];
    }

    public function extraRoles(): array
    {
        return [
            'apoteker' => [
                'pos.sell', 'pos.discount', 'pos.void', 'sales.view', 'receivables.manage',
                'master-data.view', 'master-data.manage', 'inventory.manage',
                'pharmacy.prescription.view', 'pharmacy.prescription.manage', 'pharmacy.prescription.verify',
            ],
            'asisten-apoteker' => [
                'pos.sell', 'master-data.view', 'inventory.manage',
                'pharmacy.prescription.view', 'pharmacy.prescription.manage',
            ],
        ];
    }

    /**
     * Obat per tablet/kapsul dengan strip isi 10 dan box isi 10 strip, dilacak batch & kedaluwarsanya.
     *
     * @param  list<array{0: string, 1: int|float, 2?: int|null}>|null  $units
     */
    private function tablet(string $name, string $ingredient, string $strength, DrugClass $class, int $cost, int $price, ?int $boxPrice, string $form = 'tablet', ?array $units = null, ?string $dosage = null): PresetProduct
    {
        return PresetProduct::make($name, $cost, $price, $form, 100)
            ->drug($class)
            ->units(...($units ?? [['strip', 10], ['box', 100, $boxPrice]]))
            ->attributes(['active_ingredient' => $ingredient, 'strength' => $strength, 'dosage_form' => $form, 'default_dosage' => $dosage ?? "3 x sehari 1 {$form} sesudah makan"])
            ->batch();
    }

    private function drug(string $name, int $cost, int $price, string $unit, int $minStock, DrugClass $class, string $ingredient, string $strength, string $form, string $dosage): PresetProduct
    {
        return PresetProduct::make($name, $cost, $price, $unit, $minStock)
            ->drug($class)
            ->attributes(['active_ingredient' => $ingredient, 'strength' => $strength, 'dosage_form' => $form, 'default_dosage' => $dosage])
            ->batch();
    }
}
