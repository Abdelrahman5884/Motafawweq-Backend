<?php

namespace App\Enums;

enum DayOfWeek: string
{
    case SATURDAY  = 'saturday';
    case SUNDAY    = 'sunday';
    case MONDAY    = 'monday';
    case TUESDAY   = 'tuesday';
    case WEDNESDAY = 'wednesday';
    case THURSDAY  = 'thursday';
    case FRIDAY    = 'friday';

    public function labelAr(): string
    {
        return match ($this) {
            self::SATURDAY  => 'السبت',
            self::SUNDAY    => 'الأحد',
            self::MONDAY    => 'الاثنين',
            self::TUESDAY   => 'الثلاثاء',
            self::WEDNESDAY => 'الأربعاء',
            self::THURSDAY  => 'الخميس',
            self::FRIDAY    => 'الجمعة',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::SATURDAY  => 'Saturday',
            self::SUNDAY    => 'Sunday',
            self::MONDAY    => 'Monday',
            self::TUESDAY   => 'Tuesday',
            self::WEDNESDAY => 'Wednesday',
            self::THURSDAY  => 'Thursday',
            self::FRIDAY    => 'Friday',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

