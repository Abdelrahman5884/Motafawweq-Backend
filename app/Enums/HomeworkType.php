<?php

namespace App\Enums;

enum HomeworkType: string
{
    case FILE  = 'file';
    case TEXT  = 'text';
    case IMAGE = 'image';

    public function labelAr(): string
    {
        return match ($this) {
            self::FILE  => 'ملف PDF أو مستند',
            self::TEXT  => 'نص مباشر ومسائل',
            self::IMAGE => 'صورة أو رسم توضيحي',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

