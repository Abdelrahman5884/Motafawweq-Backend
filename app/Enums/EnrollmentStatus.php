<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case PENDING  = 'pending';
    case ENROLLED = 'enrolled';
    case REJECTED = 'rejected';
    case REMOVED  = 'removed';

    public function labelAr(): string
    {
        return match ($this) {
            self::PENDING  => 'قيد المراجعة',
            self::ENROLLED => 'مقيد',
            self::REJECTED => 'مرفوض',
            self::REMOVED  => 'ملغي القيد',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

