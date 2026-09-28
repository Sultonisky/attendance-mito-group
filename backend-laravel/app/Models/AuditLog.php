<?php

namespace App\Models;

use App\Support\AttendanceDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'actor_id',
    'action',
    'auditable_type',
    'auditable_id',
    'old_values',
    'new_values',
    'ip_address',
    'user_agent',
    'metadata',
])]
#[WithoutTimestamps]
class AuditLog extends Model
{
    /**
     * The user who performed the action (may be null for system actions).
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The polymorphic entity the audit entry refers to.
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Case-insensitive search over every field shown in the audit log table:
     * id, action, resource, IP, user agent, user actor, outsource actor
     * (stored in metadata) and "System" for actor-less entries.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        $like = "%{$term}%";

        return $query->where(function (Builder $q) use ($term, $like) {
            $q->whereLike('action', $like)
                ->orWhereLike('auditable_type', $like)
                ->orWhereLike('ip_address', $like)
                ->orWhereLike('user_agent', $like)
                ->orWhereLike('metadata->actor_kind', $like)
                ->orWhereLike('metadata->outsource_name', $like)
                ->orWhereLike('metadata->outsource_code', $like)
                ->orWhereHas('actor', function (Builder $actor) use ($like) {
                    $actor->whereLike('name', $like)
                        ->orWhereLike('email', $like);
                });

            if (ctype_digit($term) && strlen($term) <= 18) {
                $q->orWhere('id', (int) $term)
                    ->orWhere('auditable_id', (int) $term);
            }

            if (str_contains('system', strtolower($term))) {
                $q->orWhere(function (Builder $system) {
                    $system->whereNull('actor_id')
                        ->whereNull('metadata->actor_kind');
                });
            }
        });
    }

    /**
     * Who performed the action: an authenticated user, an outsource person
     * (identity lives in metadata, actor_id stays null) or the system.
     */
    public function scopeActorKind(Builder $query, string $kind): Builder
    {
        return match ($kind) {
            'user' => $query->whereNotNull('actor_id'),
            'outsource' => $query->whereNull('actor_id')
                ->where('metadata->actor_kind', 'outsource'),
            'system' => $query->whereNull('actor_id')
                ->whereNull('metadata->actor_kind'),
            default => $query,
        };
    }

    /**
     * Filter by business calendar dates (attendance timezone). Compares the raw
     * created_at column against UTC instants so the created_at index is usable.
     */
    public function scopeCreatedBetweenDates(Builder $query, ?string $from, ?string $to): Builder
    {
        $timezone = AttendanceDateTime::timezone();

        if ($from) {
            $query->where(
                'created_at',
                '>=',
                CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
            );
        }

        if ($to) {
            $query->where(
                'created_at',
                '<',
                CarbonImmutable::parse($to, $timezone)->startOfDay()->addDay()->utc(),
            );
        }

        return $query;
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
