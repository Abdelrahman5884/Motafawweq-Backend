<?php

namespace App\Enums;

enum AnnouncementAudience: string
{
    case ALL = 'all';
    case STUDENTS = 'students';
    case TEACHERS = 'teachers';
    case PARENTS = 'parents';
    case CENTERS = 'centers';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::ALL => 'جميع مستخدمي المنصة',
            self::STUDENTS => 'الطلاب فقط',
            self::TEACHERS => 'المعلمون فقط',
            self::PARENTS => 'أولياء الأمور فقط',
            self::CENTERS => 'السناتر التعليمية فقط',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::ALL => 'All Users',
            self::STUDENTS => 'Students Only',
            self::TEACHERS => 'Teachers Only',
            self::PARENTS => 'Parents Only',
            self::CENTERS => 'Centers Only',
        };
    }
}
