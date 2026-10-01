<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Baris counter per prefix+tahun (mis. "PO-2026"), hanya ditulis lewat
 * App\Services\DocumentNumberGenerator dengan row lock supaya aman dari race condition.
 */
class DocumentSequence extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'next_number'];
}
