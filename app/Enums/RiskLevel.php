<?php

namespace App\Enums;

enum RiskLevel: string
{
    case HIGH   = 'high';
    case MEDIUM = 'medium';
    case LOW    = 'low';

    public function labelAr(): string
    {
        return match ($this) {
            self::HIGH   => 'خطر مرتفع (انقطاع/تعثر وشيك)',
            self::MEDIUM => 'خطر متوسط (تراجع ملحوظ)',
            self::LOW    => 'خطر طفيف (ملاحظة عابرة)',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

