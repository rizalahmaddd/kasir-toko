<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Rule unique/exists bawaan memakai query builder sehingga tidak kena global scope tenant;
 * pakai ini untuk tabel milik toko supaya SKU sama di toko lain tidak bentrok dan ID milik toko
 * lain tidak lolos validasi.
 */
class TenantRule
{
    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', app(CurrentTenant::class)->id());
    }

    public static function exists(string $table, string $column = 'NULL'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', app(CurrentTenant::class)->id());
    }
}
