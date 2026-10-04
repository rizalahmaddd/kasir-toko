<?php

namespace App\Http\Resources\V1\Onboarding;

use App\Enums\StoreType;
use App\Support\StorePresets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A store-type preset as shown in the onboarding picker. `settings` are the POS settings it
 * writes; `disabled_features` are switched off, the other optional features switched on.
 *
 * @property-read StoreType $resource
 */
class StorePresetResource extends JsonResource
{
    /**
     * @return array{key: string, label: string, description: string, icon: string, categories: list<string>, sample_product_count: int, settings: array{tax_enabled: bool, tax_rate: float, tax_label: string, allow_credit: bool, allow_negative_stock: bool, payment_methods: list<string>, quick_cash: list<int>, receipt_footer: string}, disabled_features: list<string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource->value,
            'label' => $this->resource->label(),
            'description' => $this->resource->description(),
            'icon' => $this->resource->icon(),
            'categories' => StorePresets::categories($this->resource),
            'sample_product_count' => StorePresets::sampleProductCount($this->resource),
            'settings' => StorePresets::settingsSummary($this->resource),
            'disabled_features' => StorePresets::disabledFeatures($this->resource),
        ];
    }
}
