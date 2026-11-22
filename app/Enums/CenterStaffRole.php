<?php

namespace App\Enums;

enum CenterStaffRole: string
{
    case MANAGER = 'manager';
    case RECEPTIONIST = 'receptionist';
    case ACCOUNTANT = 'accountant';
    case SUPERVISOR = 'supervisor';
    case COORDINATOR = 'coordinator';

    public function labelAr(): string
    {
        return match ($this) {
            self::MANAGER => 'مدير الفرع',
            self::RECEPTIONIST => 'الاستقبال والباركود',
            self::ACCOUNTANT => 'المحاسب المالي',
            self::SUPERVISOR => 'مشرف التشغيل',
            self::COORDINATOR => 'منسق شؤون الطلاب',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::MANAGER => 'Branch Manager',
            self::RECEPTIONIST => 'Reception & QR Gate',
            self::ACCOUNTANT => 'Financial Accountant',
            self::SUPERVISOR => 'Operations Supervisor',
            self::COORDINATOR => 'Student Affairs Coordinator',
        };
    }

    /**
     * @return list<string>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::MANAGER => ['إدارة الفرع', 'تعيين الموظفين', 'التقارير الإدارية'],
            self::RECEPTIONIST => ['إدارة الحضور بالباركود', 'تسجيل الطلاب', 'تحصيل الرسوم'],
            self::ACCOUNTANT => ['التحصيل المالي', 'التقارير المالية', 'إدارة الفواتير'],
            self::SUPERVISOR => ['إدارة القاعات', 'متابعة الجدول اليومي', 'التنسيق مع المدرسين'],
            self::COORDINATOR => ['تسجيل الطلاب', 'التواصل مع أولياء الأمور', 'متابعة الغياب'],
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
