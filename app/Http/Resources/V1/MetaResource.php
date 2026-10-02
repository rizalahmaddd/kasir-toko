<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Konfigurasi aplikasi dan label enum untuk mengisi pilihan di form. Semua enum berbentuk
 * `{nilai: label}`.
 *
 * @property-read array<string, mixed> $resource
 */
class MetaResource extends JsonResource
{
    /**
     * @return array{app: array{name: string, company_name: string, tagline: string|null, logo_url: string|null}, receipt: array{store_name: string, address: string, phone: string, header: string, footer: string, tax_label: string, paper_width: string, auto_print: bool}, enums: array<string, array<string, string>>}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
