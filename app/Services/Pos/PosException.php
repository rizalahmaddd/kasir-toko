<?php

namespace App\Services\Pos;

use RuntimeException;

/**
 * Penolakan yang pesannya aman ditampilkan langsung ke kasir. $context membawa data tambahan
 * untuk layar kasir, mis. harga terbaru saat harga produk berubah di tengah transaksi.
 */
class PosException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, public readonly string $reason = 'invalid', public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
