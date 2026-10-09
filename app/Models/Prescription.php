<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Resep dokter. Sengaja tanpa BelongsToOutlet: sisa resep (iter) boleh ditebus di outlet lain toko yang sama.
 * Data pasien dan foto resep hanya untuk pengguna berizin pharmacy.prescription.view.
 */
class Prescription extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'number',
        'prescription_date',
        'doctor_name',
        'doctor_sip',
        'clinic_name',
        'patient_name',
        'patient_age',
        'patient_phone',
        'patient_address',
        'customer_id',
        'image_path',
        'status',
        'created_by',
        'verified_by',
        'verified_at',
        'notes',
    ];

    /**
     * @return HasMany<PrescriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * @param  Builder<Prescription>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [PrescriptionStatus::Pending->value, PrescriptionStatus::PartiallyDispensed->value]);
    }

    /**
     * @param  Builder<Prescription>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where('number', 'like', "%{$term}%")
            ->orWhere('patient_name', 'like', "%{$term}%")
            ->orWhere('doctor_name', 'like', "%{$term}%"));
    }

    protected function casts(): array
    {
        return [
            'prescription_date' => 'date',
            'patient_age' => 'integer',
            'status' => PrescriptionStatus::class,
            'verified_at' => 'datetime',
        ];
    }
}
