<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Baris counter per tenant dan prefix+tahun (mis. "PO-2026"), hanya ditulis lewat
 * App\Services\DocumentNumberGenerator dengan row lock supaya aman dari race condition.
 */
class DocumentSequence extends Model
{
    use BelongsToTenant;

    protected $fillable = ['key', 'next_number'];
}
