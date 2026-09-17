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
    'status',
])]
class Outsource extends Model implements AttendanceSubject
{
    /** @use HasFactory<OutsourceFactory> */
    use HasFactory, SoftDeletes;

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
}
