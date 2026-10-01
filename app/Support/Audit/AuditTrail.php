<?php

namespace App\Support\Audit;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Jejak perubahan data per baris (created/updated/deleted/restored) dari trait Auditable,
 * terpisah dari log peristiwa bisnis manual activity('...') yang sudah ada di service.
 */
class AuditTrail
{
    public const LOG_NAME = 'audit';

    /**
     * Kolom yang tidak pernah boleh masuk log, selain $hidden milik tiap model.
     *
     * @var list<string>
     */
    public const EXCLUDED_ATTRIBUTES = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'updated_at',
    ];

    /**
     * @var array<string, string>
     */
    public const EVENTS = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'restored' => 'Dipulihkan',
        'voided' => 'Dibatalkan',
        'login' => 'Login',
        'logout' => 'Logout',
        'login_failed' => 'Login Gagal',
        'lockout' => 'Login Diblokir',
        'password_reset' => 'Reset Password',
        'exported' => 'Ekspor',
    ];

    /**
     * @var array<class-string<Model>, string>
     */
    public const SUBJECT_LABELS = [
        Customer::class => 'Pelanggan',
        Setting::class => 'Pengaturan',
        User::class => 'Pengguna',
        Role::class => 'Peran',
    ];

    /**
     * Urutan kolom yang dipakai sebagai penanda baris di deskripsi log (nomor dokumen dulu).
     *
     * @var list<string>
     */
    private const IDENTIFIER_COLUMNS = [
        'number', 'code', 'key', 'username',
    ];

    public static function subjectLabel(Model|string|null $subject): string
    {
        if ($subject === null) {
            return '-';
        }

        $class = $subject instanceof Model ? $subject::class : $subject;

        return self::SUBJECT_LABELS[$class] ?? Str::headline(class_basename($class));
    }

    public static function eventLabel(?string $event): string
    {
        return self::EVENTS[$event] ?? Str::headline((string) $event);
    }

    /**
     * Baris before/after dari attribute_changes, siap ditampilkan: kolom yang dibuat hanya punya "new",
     * yang dihapus hanya punya "old".
     *
     * @return list<array{field: string, old: ?string, new: ?string, changed: bool}>
     */
    public static function changeRows(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $old = $changes['old'] ?? [];
        $new = $changes['attributes'] ?? [];

        return collect(array_keys($old + $new))
            ->map(fn (string $field): array => [
                'field' => $field,
                'old' => array_key_exists($field, $old) ? self::formatValue($old[$field]) : null,
                'new' => array_key_exists($field, $new) ? self::formatValue($new[$field]) : null,
                'changed' => array_key_exists($field, $old) && array_key_exists($field, $new),
            ])
            ->values()
            ->all();
    }

    public static function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    public static function describe(Model $model, string $event): string
    {
        $verb = match ($event) {
            'created' => 'dibuat',
            'updated' => 'diubah',
            'deleted' => method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting() ? 'dihapus (soft delete)' : 'dihapus permanen',
            'restored' => 'dipulihkan',
            default => $event,
        };

        return trim(self::subjectLabel($model).' '.self::identify($model)).' '.$verb.'.';
    }

    public static function identify(Model $model): string
    {
        $attributes = $model->getAttributes();
        $parts = [];

        foreach (self::IDENTIFIER_COLUMNS as $column) {
            if (filled($attributes[$column] ?? null)) {
                $parts[] = $attributes[$column];
                break;
            }
        }

        if (filled($attributes['name'] ?? null) && ! in_array($attributes['name'], $parts, true)) {
            $parts[] = $attributes['name'];
        }

        if ($model->getKey() !== null) {
            $parts[] = '(#'.$model->getKey().')';
        }

        return implode(' ', $parts);
    }
}
