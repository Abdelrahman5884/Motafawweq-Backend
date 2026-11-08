<?php

namespace App\Enums;

enum ScheduleNotePriority: string
{
    case HIGH   = 'high';
    case MEDIUM = 'medium';
    case NORMAL = 'normal';

    public function labelAr(): string
    {
        return match ($this) {
            self::HIGH   => 'عالية الأهمية',
            self::MEDIUM => 'متوسطة',
            self::NORMAL => 'عادية',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

