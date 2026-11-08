<?php

namespace App\Enums;

enum HomeworkSubmissionStatus: string
{
    case PENDING  = 'pending';
    case GRADED   = 'graded';
    case REJECTED = 'rejected';

    public function labelAr(): string
    {
        return match ($this) {
            self::PENDING  => 'قيد المراجعة والتصحيح',
            self::GRADED   => 'تم التصحيح ورصد الدرجة',
            self::REJECTED => 'مرفوض وبانتظار إعادة الرفع',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

