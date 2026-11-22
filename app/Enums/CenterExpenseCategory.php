<?php

namespace App\Enums;

enum CenterExpenseCategory: string
{
    case RENT = 'rent';
    case UTILITIES = 'utilities';
    case SALARIES = 'salaries';
    case MARKETING = 'marketing';
    case MAINTENANCE = 'maintenance';
    case OTHER = 'other';

    public function labelAr(): string
    {
        return match ($this) {
            self::RENT => 'إيجار المقرات',
            self::UTILITIES => 'المرافق والخدمات',
            self::SALARIES => 'رواتب الموظفين',
            self::MARKETING => 'التسويق والإعلانات',
            self::MAINTENANCE => 'الصيانة والتجهيزات',
            self::OTHER => 'مصروفات عامة',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::RENT => 'Branch Rent',
            self::UTILITIES => 'Utilities & Services',
            self::SALARIES => 'Staff Salaries',
            self::MARKETING => 'Marketing & Ads',
            self::MAINTENANCE => 'Maintenance & Equipment',
            self::OTHER => 'General Expenses',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
