<?php

namespace App\Enums;

/**
 * Employee work area: branch (cabang), factory (pabrik), head office.
 */
enum WorkAreaType: string
{
    case Branch = 'branch';
    case Factory = 'factory';
    case HeadOffice = 'head_office';

    public function label(): string
    {
        return match ($this) {
            self::Branch => 'Cabang',
            self::Factory => 'Pabrik',
            self::HeadOffice => 'Head Office',
        };
    }
}
