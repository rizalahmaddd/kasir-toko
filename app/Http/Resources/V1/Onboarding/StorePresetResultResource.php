<?php

namespace App\Http\Resources\V1\Onboarding;

use App\Http\Resources\V1\Auth\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What applying a preset created. `products_skipped` counts sample products left out because the
 * plan's product limit was reached. `capabilities` are the business capabilities now on;
 * `capabilities_kept` stayed on during a re-apply because they already hold data.
 *
 * @property-read array{tenant: Tenant, categories_created: int, products_created: int, products_skipped: int} $resource
 */
class StorePresetResultResource extends JsonResource
{
    /**
     * @return array{categories_created: int, products_created: int, products_skipped: int, capabilities: list<string>, capabilities_kept: list<string>, tenant: TenantResource}
     */
    public function toArray(Request $request): array
    {
        return [
            'categories_created' => $this->resource['categories_created'],
            'products_created' => $this->resource['products_created'],
            'products_skipped' => $this->resource['products_skipped'],
            'capabilities' => $this->resource['capabilities'] ?? [],
            'capabilities_kept' => $this->resource['capabilities_kept'] ?? [],
            'tenant' => new TenantResource($this->resource['tenant']),
        ];
    }
}
