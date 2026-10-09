<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kelompok pilihan tambahan di kasir, mis. Ukuran atau Level Gula. min_select > 0 berarti wajib dipilih;
 * max_select null berarti boleh memilih sebanyak apa pun.
 */
class ModifierGroup extends Model
{
    use Auditable;
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = ['name', 'min_select', 'max_select', 'sort_order', 'is_active'];

    /**
     * @return HasMany<Modifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('sort_order');
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }

    /**
     * Ringkasan aturan pilih untuk kasir, mis. "Wajib, pilih 1".
     */
    public function ruleLabel(): string
    {
        $min = $this->min_select;
        $max = $this->max_select;

        return match (true) {
            $min > 0 && $max === $min => "Wajib, pilih {$min}",
            $min > 0 && $max === null => "Wajib, minimal {$min}",
            $min > 0 => "Wajib, pilih {$min}-{$max}",
            $max !== null => "Opsional, maksimal {$max}",
            default => 'Opsional',
        };
    }

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
