<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case INSTAPAY = 'instapay';
    case VODAFONE = 'vodafone';
    case CIB      = 'cib';
    case FAWRY    = 'fawry';
    case CARD     = 'card';

    public function label(): string
    {
        return match ($this) {
            self::INSTAPAY => 'إنستاباي (InstaPay)',
            self::VODAFONE => 'فودافون كاش ومحافظ المحمول',
            self::CIB      => 'تحويل بنكي CIB',
            self::FAWRY    => 'فوري باي (Fawry)',
            self::CARD     => 'بطاقة بنكية',
        };
    }
}

