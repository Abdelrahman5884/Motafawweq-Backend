<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case TEACHER = 'teacher';
    case STUDENT = 'student';
    case PARENT = 'parent';
    case CENTER = 'center';

    /**
     * Get all role values as an array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get human-readable label in Arabic.
     */
    public function label(): string
    {
        return match ($this) {
            self::ADMIN   => 'مدير النظام',
            self::TEACHER => 'معلم',
            self::STUDENT => 'طالب',
            self::PARENT  => 'ولي أمر',
            self::CENTER  => 'إدارة سنتر تعليمي',
        };
    }
}
