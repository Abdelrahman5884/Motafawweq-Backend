<?php

namespace App\Enums;

enum BillingPeriod: string
{
    case MONTHLY = 'monthly';
    case TERM    = 'term';
    case ANNUAL  = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::MONTHLY => 'شهرياً',
            self::TERM    => 'نصف سنوي (ترم دراسي)',
            self::ANNUAL  => 'سنة دراسية كاملة',
        };
    }

    public function durationDays(): int
    {
        return match ($this) {
            self::MONTHLY => 30,
            self::TERM    => 150,
            self::ANNUAL  => 365,
        };
    }
}

