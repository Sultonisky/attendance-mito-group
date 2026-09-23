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
     * Next outsource login code: sequential 3-digit numeric (001, 002, …).
     * Pads to at least 3 digits; grows past 999 as 1000, 1001, …
     */
    public static function generateNextCode(): string
    {
        $max = static::withTrashed()
            ->pluck('outsource_code')
            ->filter(fn (mixed $code): bool => is_string($code) && ctype_digit($code))
            ->map(fn (string $code): int => (int) $code)
            ->max();

        $next = ((int) ($max ?? 0)) + 1;

        do {
            $code = str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $exists = static::withTrashed()->where('outsource_code', $code)->exists();
            $next++;
        } while ($exists);

        return $code;
    }
}
