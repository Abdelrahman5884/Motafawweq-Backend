<?php

namespace App\Enums;

enum CertificateStatus: string
{
    case VERIFIED = 'verified';
    case REVOKED  = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::VERIFIED => 'معتمدة وموثقة',
            self::REVOKED  => 'ملغاة / مسحوبة',
        };
    }
}

