<?php

namespace App\Http\Resources\V1\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Struk dalam bentuk teks (format WhatsApp: *tebal*), cocok juga untuk printer thermal Bluetooth.
 * `whatsapp_url` sudah berisi nomor pelanggan kalau ada.
 *
 * @property-read array<string, mixed> $resource
 */
class ReceiptResource extends JsonResource
{
    /**
     * @return array{number: string, text: string, whatsapp_url: string, paper_width: string}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
