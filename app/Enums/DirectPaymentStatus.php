<?php

namespace App\Enums;

enum DirectPaymentStatus: string
{
    case PENDING   = 'pending';
    case CONFIRMED = 'confirmed';
    case REJECTED  = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'قيد المراجعة',
            self::CONFIRMED => 'مؤكد ومستلم',
            self::REJECTED  => 'مرفوض',
        };
    }
}

