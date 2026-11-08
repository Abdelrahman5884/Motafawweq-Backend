<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case PAID    = 'paid';
    case PENDING = 'pending';
    case FAILED  = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PAID    => 'مدفوع',
            self::PENDING => 'معلق',
            self::FAILED  => 'غير ناجح',
        };
    }
}

