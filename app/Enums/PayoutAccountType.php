<?php

namespace App\Enums;

enum PayoutAccountType: string
{
    case INSTAPAY = 'instapay';
    case VODAFONE = 'vodafone';
    case CIB      = 'cib';
    case CARD     = 'card';

    public function defaultLabelAr(): string
    {
        return match ($this) {
            self::INSTAPAY => 'عنوان إنستاباي الفوري (InstaPay)',
            self::VODAFONE => 'محفظة فودافون كاش ومحافظ المحمول',
            self::CIB      => 'حساب بنكي (CIB / IBAN)',
            self::CARD     => 'بطاقة بنكية / فيزا للاستلام',
        };
    }

    public function defaultLabelEn(): string
    {
        return match ($this) {
            self::INSTAPAY => 'InstaPay Direct IPA',
            self::VODAFONE => 'Vodafone Cash & Mobile Wallet',
            self::CIB      => 'Bank Account (CIB / IBAN)',
            self::CARD     => 'Bank / Debit Card',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

