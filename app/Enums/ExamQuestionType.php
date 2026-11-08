<?php

namespace App\Enums;

enum ExamQuestionType: string
{
    case MCQ        = 'mcq';
    case TRUE_FALSE = 'true_false';
    case ESSAY      = 'essay';

    public function labelAr(): string
    {
        return match ($this) {
            self::MCQ        => 'اختيار من متعدد',
            self::TRUE_FALSE => 'صح وخطأ',
            self::ESSAY      => 'مقالي',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

