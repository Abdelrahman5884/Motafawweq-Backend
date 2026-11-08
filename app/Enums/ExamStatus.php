<?php

namespace App\Enums;

enum ExamStatus: string
{
    case ACTIVE    = 'active';
    case CLOSED    = 'closed';
    case SCHEDULED = 'scheduled';

    public function labelAr(): string
    {
        return match ($this) {
            self::ACTIVE    => 'نشط ومتاح للطلاب',
            self::CLOSED    => 'مغلق',
            self::SCHEDULED => 'مجدول بميعاد لاحق',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

