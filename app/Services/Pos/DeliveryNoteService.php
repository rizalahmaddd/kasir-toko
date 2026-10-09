<?php

namespace App\Services\Pos;

use App\Models\DeliveryNote;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\Features;

class DeliveryNoteService
{
    public function __construct(private DocumentNumberGenerator $numbers) {}

    /**
     * @param  array{recipient: string, phone?: ?string, address: string, project?: ?string, driver?: ?string, vehicle?: ?string, notes?: ?string}  $data
     *
     * @throws PosException
     */
    public function create(Sale $sale, User $user, array $data): DeliveryNote
    {
        if ($sale->isVoided()) {
            throw new PosException('Transaksi yang dibatalkan tidak bisa dibuatkan surat jalan.');
        }

        if (! Features::enabledAt('business.delivery-note', $sale->outlet_id)) {
            throw new PosException('Surat jalan tidak aktif di outlet transaksi ini. Aktifkan di pengaturan fitur outlet.', 'feature_off_at_outlet');
        }

        return DeliveryNote::query()->create([
            'outlet_id' => $sale->outlet_id,
            'sale_id' => $sale->id,
            'number' => $this->numbers->next('SJ', 5, null, Outlet::query()->find($sale->outlet_id)),
            'recipient' => trim($data['recipient']),
            'phone' => $data['phone'] ?? null,
            'address' => trim($data['address']),
            'project' => $data['project'] ?? null,
            'driver' => $data['driver'] ?? null,
            'vehicle' => $data['vehicle'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => DeliveryNote::STATUS_SENT,
            'created_by' => $user->id,
        ]);
    }

    public function markDelivered(DeliveryNote $note): DeliveryNote
    {
        $note->update(['status' => DeliveryNote::STATUS_DELIVERED, 'delivered_at' => $note->delivered_at ?? now()]);

        return $note;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],
            'project' => ['nullable', 'string', 'max:150'],
            'driver' => ['nullable', 'string', 'max:100'],
            'vehicle' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
