<?php

namespace App\Enums;

enum LeagueDuration: string
{
    case WEEKLY    = 'weekly';
    case MONTHLY   = 'monthly';
    case HALF_YEAR = 'half_year';
    case FULL_YEAR = 'full_year';

    public function durationDays(): int
    {
        return match ($this) {
            self::WEEKLY    => 7,
            self::MONTHLY   => 30,
            self::HALF_YEAR => 90,
            self::FULL_YEAR => 180,
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::WEEKLY    => 'دوري أسبوعي',
            self::MONTHLY   => 'دوري شهري',
            self::HALF_YEAR => 'دوري نصف سنوي',
            self::FULL_YEAR => 'دوري سنوي',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

