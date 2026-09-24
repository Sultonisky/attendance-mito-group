<?php

namespace App\Models;

use App\Contracts\AttendanceSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'outsource_code',
    'name',
    'password',
    'status',
])]
class Outsource extends Model implements AttendanceSubject
{
    /** @use HasFactory<OutsourceFactory> */
    use HasFactory, SoftDeletes;

    /** Default numeric login PIN when none is provided (create / import / backfill). */
    public const DEFAULT_LOGIN_PIN = '123456';

    /** Business employee-code prefix used by import + manual create. */
    public const CODE_PREFIX = 'DM2026';

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'deleted_at' => 'datetime',
        ];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEndDate(): ?CarbonImmutable
    {
        return null;
    }

    public function isAttendanceActive(): bool
    {
        return $this->status === 'active' && ! $this->trashed();
    }

    /**
     * @return BelongsToMany<WorkLocation>
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(WorkLocation::class, 'outsource_store_assignments', 'outsource_id', 'store_id')
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<WorkLocation>
     */
    public function activeStores(): BelongsToMany
    {
        return $this->stores()->wherePivot('status', 'active');
    }

    /**
     * Next outsource login code in the DM2026#### sequence.
     * Continues after the highest existing DM2026 code (including soft-deleted).
     * Example: …DM20260123 imported → next manual create is DM20260124.
     */
    public static function generateNextCode(): string
    {
        $prefix = self::CODE_PREFIX;
        $prefixLength = strlen($prefix);

        $max = static::withTrashed()
            ->pluck('outsource_code')
            ->filter(static function (mixed $code) use ($prefix, $prefixLength): bool {
                if (! is_string($code) || ! str_starts_with($code, $prefix)) {
                    return false;
                }

                $suffix = substr($code, $prefixLength);

                return $suffix !== '' && ctype_digit($suffix);
            })
            ->map(static fn (string $code): int => (int) substr($code, $prefixLength))
            ->max();

        $next = ((int) ($max ?? 0)) + 1;

        do {
            $code = sprintf('%s%04d', $prefix, $next);
            $exists = static::withTrashed()->where('outsource_code', $code)->exists();
            $next++;
        } while ($exists);

        return $code;
    }
}
