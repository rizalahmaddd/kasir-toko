<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\BackupService;
use Carbon\CarbonInterface;
use Database\Factories\BackupScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Jadwal backup otomatis. Satu jadwal boleh punya beberapa jam (mis. 02:00 dan 20:00) dan
 * menyimpan sendiri N backup terakhirnya; backup lain (manual, jadwal lain) tidak ikut dihapus.
 */
class BackupSchedule extends Model
{
    use Auditable;

    /** @use HasFactory<BackupScheduleFactory> */
    use HasFactory;

    /** @var array<string, string> */
    public const FREQUENCIES = [
        'daily' => 'Setiap hari',
        'weekly' => 'Setiap minggu',
        'monthly' => 'Setiap bulan',
    ];

    /** @var array<int, string> ISO-8601: 1 = Senin */
    public const WEEKDAYS = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];

    protected $fillable = [
        'name', 'scope', 'frequency', 'times', 'weekdays', 'month_day', 'keep', 'include_secrets', 'is_active',
        'last_run_at', 'last_status', 'last_file', 'last_error', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'times' => 'array',
            'weekdays' => 'array',
            'month_day' => 'integer',
            'keep' => 'integer',
            'include_secrets' => 'boolean',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function filePrefix(): string
    {
        return "auto-s{$this->id}";
    }

    public function isDueAt(CarbonInterface $moment): bool
    {
        return $this->is_active
            && in_array($moment->format('H:i'), $this->times ?? [], true)
            && $this->runsOnDay($moment);
    }

    public function nextRunAt(CarbonInterface $after): ?Carbon
    {
        if (! $this->is_active || empty($this->times)) {
            return null;
        }

        $times = collect($this->times)->sort()->values();

        // Jadwal bulanan paling jauh ~31 hari lagi, jadi 62 hari selalu cukup.
        for ($offset = 0; $offset <= 62; $offset++) {
            $day = Carbon::instance($after)->startOfDay()->addDays($offset);

            if (! $this->runsOnDay($day)) {
                continue;
            }

            foreach ($times as $time) {
                [$hour, $minute] = array_map(intval(...), explode(':', $time));
                $candidate = $day->copy()->setTime($hour, $minute);

                if ($candidate->greaterThan($after)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function describe(): string
    {
        $times = collect($this->times)->sort()->implode(', ');

        return match ($this->frequency) {
            'weekly' => 'Setiap '.collect($this->weekdays)->sort()->map(fn (int $day) => self::WEEKDAYS[$day])->implode(', '),
            'monthly' => "Setiap tanggal {$this->month_day}",
            default => 'Setiap hari',
        }." · {$times}";
    }

    public function scopeLabel(): string
    {
        return BackupService::SCOPES[$this->scope]['label'] ?? $this->scope;
    }

    private function runsOnDay(CarbonInterface $day): bool
    {
        return match ($this->frequency) {
            'daily' => true,
            'weekly' => in_array($day->dayOfWeekIso, $this->weekdays ?? [], true),
            'monthly' => $day->day === $this->month_day,
            default => false,
        };
    }
}
