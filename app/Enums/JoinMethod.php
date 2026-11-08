<?php

namespace App\Enums;

enum JoinMethod: string
{
    case QR_SCAN           = 'qr_scan';
    case JOIN_CODE          = 'join_code';
    case TEACHER_INVITE     = 'teacher_invite';
    case DIRECT_ENROLLMENT  = 'direct_enrollment';

    public function labelAr(): string
    {
        return match ($this) {
            self::QR_SCAN          => 'مسح الباركود (QR Code)',
            self::JOIN_CODE        => 'كود الانضمام',
            self::TEACHER_INVITE   => 'دعوة مباشرة من المعلم',
            self::DIRECT_ENROLLMENT => 'تسجيل مباشر',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

