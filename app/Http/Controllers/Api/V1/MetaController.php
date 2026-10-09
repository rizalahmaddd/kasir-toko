<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CashMovementType;
use App\Enums\DrugClass;
use App\Enums\PaymentMethod;
use App\Enums\PrescriptionStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Http\Resources\V1\MetaResource;
use App\Http\Resources\V1\NotificationResource;
use App\Http\Resources\V1\SearchResultResource;
use App\Livewire\MasterData\Products;
use App\Support\Branding;
use App\Support\CurrentOutlet;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use App\Support\OutletIdentity;
use App\Support\PosSettings;
use App\Support\ProductAttributes;
use App\Support\StorePresets\AttributeField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[ApiTag('Referensi Aplikasi', 'Akun')]
class MetaController extends Controller
{
    /**
     * Konfigurasi & enum.
     *
     * Nama/logo aplikasi, identitas toko untuk struk, dan label semua pilihan tetap untuk mengisi
     * form. Cukup diambil sekali saat aplikasi dibuka. `receipt` berisi semua yang dicetak di kepala
     * dan kaki struk thermal, supaya aplikasi bisa mencetak sendiri lewat Bluetooth; nilainya sudah
     * diresolusi untuk outlet yang sedang dipakai (alamat, telepon, header, footer, pajak, kertas),
     * dan `outlet_name` terisi hanya di toko multi-outlet. `product_attributes` adalah skema isian
     * produk tambahan sesuai jenis toko (kosong selama kapabilitas Atribut Produk Khusus mati).
     */
    public function meta(): MetaResource
    {
        $identity = OutletIdentity::for(app(CurrentOutlet::class)->get());

        return new MetaResource([
            'app' => [
                'name' => Branding::appName(),
                'company_name' => Branding::companyName(),
                'tagline' => Branding::tagline(),
                'logo_url' => Branding::logoUrl(),
            ],
            'receipt' => [
                'store_name' => Branding::companyName(),
                'outlet_name' => $identity['name'],
                'address' => (string) $identity['address'],
                'phone' => (string) $identity['phone'],
                'header' => PosSettings::get('pos.receipt_header'),
                'footer' => PosSettings::get('pos.receipt_footer'),
                'tax_label' => PosSettings::taxLabel(),
                'paper_width' => PosSettings::receiptWidth(),
                'auto_print' => PosSettings::autoPrint(),
            ],
            'enums' => [
                'customer_payment_terms' => ['0' => 'Tunai', '14' => '14 hari', '30' => '30 hari', '45' => '45 hari'],
                'payment_methods' => PaymentMethod::options(),
                'sale_statuses' => collect(SaleStatus::cases())->mapWithKeys(fn (SaleStatus $status) => [$status->value => $status->label()])->all(),
                'stock_movement_types' => collect(StockMovementType::cases())->mapWithKeys(fn (StockMovementType $type) => [$type->value => $type->label()])->all(),
                'stock_adjustment_types' => ['stock_in' => StockMovementType::StockIn->label(), 'stock_out' => StockMovementType::StockOut->label(), 'opname' => StockMovementType::Opname->label()],
                'cash_movement_types' => collect(CashMovementType::cases())->mapWithKeys(fn (CashMovementType $type) => [$type->value => $type->label()])->all(),
                'product_units' => array_combine($units = array_values(array_unique([...$this->suggestedUnits(), ...Products::UNITS])), $units),
                'drug_classes' => collect(DrugClass::cases())->mapWithKeys(fn (DrugClass $class) => [$class->value => $class->label()])->all(),
                'prescription_statuses' => collect(PrescriptionStatus::cases())->mapWithKeys(fn (PrescriptionStatus $status) => [$status->value => $status->label()])->all(),
                'prescription_modes' => PosSettings::PRESCRIPTION_MODES,
            ],
            'product_attributes' => array_map(fn (AttributeField $field) => $field->toArray(), ProductAttributes::fields()),
        ]);
    }

    /**
     * @return list<string>
     */
    private function suggestedUnits(): array
    {
        return ProductAttributes::suggestedUnits();
    }

    /**
     * Pencarian global.
     *
     * Sama dengan kotak pencarian di web (pelanggan, pengguna, dan data lain sesuai hak akses). `target` menunjuk data yang bisa dibuka lewat API.
     */
    #[ApiQuery('q', description: 'Minimal 2 karakter.', required: true)]
    #[ApiResponse(SearchResultResource::class, collection: true)]
    public function search(Request $request): AnonymousResourceCollection
    {
        $query = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];

        $component = app('livewire')->new('layout.global-search');
        $component->query = $query;
        $component->search();

        $groups = collect($component->results)
            ->reject(fn (array $group) => $group['group'] === 'Menu')
            ->map(fn (array $group) => [
                'group' => $group['group'],
                'icon' => $group['icon'],
                'color' => $group['color'],
                'items' => array_map(fn (array $item) => [
                    'label' => $item['label'],
                    'sub' => $item['sub'],
                    'flags' => $item['flags'],
                    'target' => NotificationResource::targetOf($item['url']),
                ], $group['items']),
            ])
            ->values();

        return SearchResultResource::collection($groups);
    }
}
