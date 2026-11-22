<?php

namespace App\Enums;

enum CenterRefundPolicy: string
{
    case CREDIT = 'credit';
    case REPLACEMENT = 'replacement';
    case CASH = 'cash';

    public function labelAr(): string
    {
        return match ($this) {
            self::CREDIT => 'إضافة رصيد تلقائي لمحفظة الطالب بالسنتر',
            self::REPLACEMENT => 'حضور حصة بديلة في نفس الأسبوع مجاناً',
            self::CASH => 'استرداد نقدي من الخزينة',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::CREDIT => 'Credit to Student Center Wallet',
            self::REPLACEMENT => 'Free Replacement Class in Same Week',
            self::CASH => 'Cash Refund at Reception Desk',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
