<?php

namespace App\Http\Resources\V1\Onboarding;

use App\Enums\StoreType;
use App\Support\Features;
use App\Support\StorePresets;
use App\Support\StorePresets\AttributeField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A store-type preset as shown in the onboarding picker. `settings` are the POS settings it
 * writes; `disabled_features` are switched off, the other optional features switched on.
 * `capabilities` lists every business capability with `default_on` for the ones this preset turns on;
 * send the ticked keys back as `capabilities` when applying.
 *
 * @property-read StoreType $resource
 */
class StorePresetResource extends JsonResource
{
    /**
     * @return array{key: string, label: string, description: string, icon: string, categories: list<string>, sample_product_count: int, settings: array{tax_enabled: bool, tax_rate: float, tax_label: string, allow_credit: bool, allow_negative_stock: bool, payment_methods: list<string>, quick_cash: list<int>, receipt_footer: string}, disabled_features: list<string>, capabilities: list<array{key: string, label: string, description: string, default_on: bool}>, product_attributes: list<string>, suggested_units: list<string>, modifier_groups: list<string>}
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
            'capabilities' => collect(Features::optInFeatures())->map(fn (string $key) => [
                'key' => $key,
                'label' => Features::MODULES['business']['features'][explode('.', $key, 2)[1]]['label'],
                'description' => Features::MODULES['business']['features'][explode('.', $key, 2)[1]]['description'],
                'default_on' => in_array($key, StorePresets::capabilities($this->resource), true),
            ])->all(),
            'product_attributes' => array_map(fn (AttributeField $field) => $field->label, StorePresets::productAttributes($this->resource)),
            'suggested_units' => StorePresets::suggestedUnits($this->resource),
            'modifier_groups' => array_map(fn ($group) => $group->name, StorePresets::for($this->resource)->modifierGroups()),
        ];
    }
}
