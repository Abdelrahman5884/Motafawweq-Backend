<?php

namespace App\Enums;

enum ExamSubmissionStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case SUBMITTED   = 'submitted';
    case GRADED      = 'graded';

    public function labelAr(): string
    {
        return match ($this) {
            self::IN_PROGRESS => 'قيد الحل',
            self::SUBMITTED   => 'تم التسليم بانتظار تصحيح المقالي',
            self::GRADED      => 'تم التصحيح واكتمال الدرجة',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
