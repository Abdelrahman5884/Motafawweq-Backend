<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case PRESENT = 'present';
    case ABSENT  = 'absent';
    case LATE    = 'late';
    case EXCUSED = 'excused';

    public function labelAr(): string
    {
        return match ($this) {
            self::PRESENT => 'حاضر',
            self::ABSENT  => 'غائب',
            self::LATE    => 'متأخر',
            self::EXCUSED => 'معتذر بعذر مقبول',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
