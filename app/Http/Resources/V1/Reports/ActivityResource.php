<?php

namespace App\Http\Resources\V1\Reports;

use App\Http\Resources\V1\UserSummaryResource;
use App\Support\Audit\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/**
 * Satu baris jejak audit. `causer` null berarti dilakukan sistem. `changes` berisi
 * `{attributes, old}` untuk perubahan data.
 *
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array{id: int, log_name: string|null, event: string|null, event_label: string|null, description: string, subject_type: string|null, subject_label: string|null, subject_id: string|null, causer: UserSummaryResource|null, changes: array<string, mixed>, reason: string|null, ip_address: string|null, batch_uuid: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'event' => $this->event,
            'event_label' => $this->event ? AuditTrail::eventLabel($this->event) : null,
            'description' => $this->description,
            'subject_type' => $this->subject_type,
            'subject_label' => $this->subject_type ? AuditTrail::subjectLabel($this->subject_type) : null,
            'subject_id' => $this->subject_id !== null ? (string) $this->subject_id : null,
            'causer' => $this->causer ? new UserSummaryResource($this->causer) : null,
            'changes' => (object) ($this->attribute_changes?->toArray() ?? []),
            'reason' => $this->properties?->get('reason'),
            'ip_address' => $this->ip_address,
            'batch_uuid' => $this->batch_uuid,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
