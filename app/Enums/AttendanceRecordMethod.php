<?php

namespace App\Enums;

enum AttendanceRecordMethod: string
{
    case QR_SCAN        = 'qr_scan';
    case BARCODE_INPUT  = 'barcode_input';
    case MANUAL_TOGGLE  = 'manual_toggle';
    case PASSCODE       = 'passcode';

    public function labelAr(): string
    {
        return match ($this) {
            self::QR_SCAN       => 'مسح رمز QR بالكاميرا',
            self::BARCODE_INPUT => 'إدخال الباركود يدوياً أو بقارئ الباركود',
            self::MANUAL_TOGGLE => 'تحديد مباشر من القائمة',
            self::PASSCODE      => 'كود المرور السري (PIN)',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

