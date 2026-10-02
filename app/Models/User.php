<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token', 'display_key'])]
class User extends Authenticatable
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /**
     * Username disimpan huruf kecil supaya login tidak peka huruf besar/kecil.
     */
    protected function username(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : strtolower(trim($value)));
    }

    /**
     * Nomor HP disimpan dalam satu bentuk baku (lihat normalizePhone()) supaya "0812-3456",
     * "+62812 3456", dan "628123456" dianggap nomor yang sama saat login maupun cek unik.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => blank($value) ? null : self::normalizePhone($value));
    }

    /**
     * Ubah nomor HP Indonesia ke bentuk baku berawalan 0 dan hanya berisi angka.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return str_starts_with($digits, '62') ? '0'.substr($digits, 2) : $digits;
    }

    /**
     * Username wajib diawali huruf supaya tidak tertukar dengan nomor HP di form login.
     *
     * @return array<int, mixed>
     */
    public static function usernameRules(?self $ignore = null): array
    {
        return ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z][a-z0-9._]*$/', Rule::unique(self::class)->ignore($ignore?->id)];
    }

    /**
     * Dipanggil setelah nomor dinormalisasi (normalizePhone()), jadi cek unik membandingkan bentuk baku.
     *
     * @return array<int, mixed>
     */
    public static function phoneRules(?self $ignore = null): array
    {
        return ['nullable', 'string', 'regex:/^0\d{8,14}$/', Rule::unique(self::class)->ignore($ignore?->id)];
    }

    /**
     * @return array<string, string>
     */
    public static function identityValidationMessages(): array
    {
        return [
            'username.regex' => 'Username hanya boleh huruf kecil, angka, titik, dan garis bawah, diawali huruf.',
            'username.unique' => 'Username ini sudah dipakai.',
            'phone.regex' => 'Nomor HP tidak valid. Contoh: 081234567890.',
            'phone.unique' => 'Nomor HP ini sudah dipakai akun lain.',
        ];
    }

    /**
     * Apakah akun merupakan Superadmin yang memiliki bypass akses penuh ke semua modul.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('superadmin');
    }

    /**
     * Pengelola layanan SaaS: tidak terikat toko, hanya membuka panel Platform.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->tenant_id === null && $this->is_platform_admin;
    }

    /**
     * @return HasMany<CashShift, $this>
     */
    public function cashShifts(): HasMany
    {
        return $this->hasMany(CashShift::class);
    }

    /**
     * Kunci rahasia di URL layar pelanggan (tanpa login). Siapa pun yang memegang URL-nya bisa
     * melihat keranjang kasir ini, jadi ganti kuncinya kalau perangkat layar hilang.
     */
    public function displayKey(): string
    {
        if (! $this->display_key) {
            $this->rotateDisplayKey();
        }

        return $this->display_key;
    }

    public function rotateDisplayKey(): string
    {
        $this->forceFill(['display_key' => Str::random(40)])->saveQuietly();

        return $this->display_key;
    }

    public function openShift(): ?CashShift
    {
        return $this->cashShifts()->open()->latest('opened_at')->first();
    }
}
