<?php

namespace App\Http\Resources\V1\Inventory;

use App\Http\Resources\V1\UserSummaryResource;
use App\Models\StockCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dokumen stok opname. `status`: `counting` (sedang dihitung), `review` (diperiksa, hitungan masih bisa
 * dikoreksi), `posting` (stok sedang disesuaikan), `posted` (selesai), `cancelled`. `can_see_system`
 * false berarti penghitung tidak boleh melihat stok sistem (blind count); angka sistem tidak dikirim.
 *
 * @mixin StockCount
 */
class StockCountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeSystem = $user->can('inventory.opname.manage') || ! $this->blind_count;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'scope' => $this->scope->value,
            'scope_label' => $this->scope->label(),
            'outlet' => $this->outlet ? ['id' => $this->outlet->id, 'name' => $this->outlet->name, 'code' => $this->outlet->code] : null,
            'blind_count' => $this->blind_count,
            'hold_adjustments' => $this->hold_adjustments,
            'can_see_system' => $canSeeSystem,
            'can_manage' => $user->can('inventory.opname.manage'),
            'note' => $this->note,
            'items_count' => $this->whenCounted('items'),
            'counted_items_count' => $this->whenCounted('counted_items'),
            'summary' => $canSeeSystem ? $this->summary : null,
            'uncounted_policy' => $this->uncounted_policy,
            'created_by' => $this->creator ? new UserSummaryResource($this->creator) : null,
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
