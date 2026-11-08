<?php

namespace App\Enums;

enum RiskSignalStatus: string
{
    case DETECTED = 'detected';
    case NOTIFIED = 'notified';
    case RESOLVED = 'resolved';

    public function labelAr(): string
    {
        return match ($this) {
            self::DETECTED => 'تم رصد التعثر حديثاً',
            self::NOTIFIED => 'تم إرسال تنبيه للطالب/ولي الأمر',
            self::RESOLVED => 'تمت معالجة المشكلة وتحسن الأداء',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

