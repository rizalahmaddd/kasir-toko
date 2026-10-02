<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Http\Resources\V1\MetaResource;
use App\Http\Resources\V1\NotificationResource;
use App\Http\Resources\V1\SearchResultResource;
use App\Livewire\MasterData\Products;
use App\Models\Setting;
use App\Support\Branding;
use App\Support\OpenApi\Attributes\ApiQuery;
use App\Support\OpenApi\Attributes\ApiResponse;
use App\Support\OpenApi\Attributes\ApiTag;
use App\Support\PosSettings;
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
     * dan kaki struk thermal, supaya aplikasi bisa mencetak sendiri lewat Bluetooth.
     */
    public function meta(): MetaResource
    {
        return new MetaResource([
            'app' => [
                'name' => Branding::appName(),
                'company_name' => Branding::companyName(),
                'tagline' => Branding::tagline(),
                'logo_url' => Branding::logoUrl(),
            ],
            'receipt' => [
                'store_name' => Branding::companyName(),
                'address' => (string) Setting::get('company_address', ''),
                'phone' => (string) Setting::get('company_phone', ''),
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
                'product_units' => array_combine(Products::UNITS, Products::UNITS),
            ],
        ]);
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
