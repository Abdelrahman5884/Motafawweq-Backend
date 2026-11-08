<?php

namespace App\Enums;

enum AcademicStatus: string
{
    case TOP_1_PERCENT  = 'Top 1%';
    case EXCELLENCE     = 'Excellence';
    case GOOD           = 'Good';
    case NEEDS_SUPPORT  = 'Needs Support';

    public function labelAr(): string
    {
        return match ($this) {
            self::TOP_1_PERCENT => 'أوائل دفعة (Top 1%)',
            self::EXCELLENCE    => 'متفوق (امتياز)',
            self::GOOD          => 'جيد ومستقر',
            self::NEEDS_SUPPORT => 'يحتاج لدعم إضافي',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

