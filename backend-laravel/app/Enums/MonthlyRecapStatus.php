<?php

namespace App\Enums;

/**
 * Monthly recap lifecycle: DRAFT -> REVIEW -> FINALIZED -> EXPORTED.
 *
 * FINALIZED / EXPORTED recaps are business-locked; corrections require an
 * explicit reopen (back to REVIEW), then regenerate if numbers changed.
 */
enum MonthlyRecapStatus: string
{
    case Draft = 'draft';

    case Review = 'review';

    case Finalized = 'finalized';

    case Exported = 'exported';

    /**
     * Canonicalize legacy aliases (e.g. seed data used "reviewed").
     */
    public static function normalize(?string $status): string
    {
        $value = strtolower(trim((string) $status));

        return match ($value) {
            'reviewed' => self::Review->value,
            default => $value,
        };
    }

    public static function tryNormalize(?string $status): ?self
    {
        return self::tryFrom(self::normalize($status));
    }

    public function equals(?string $status): bool
    {
        return $this->value === self::normalize($status);
    }
}
