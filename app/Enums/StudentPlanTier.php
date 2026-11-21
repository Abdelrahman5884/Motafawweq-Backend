<?php

namespace App\Enums;

enum StudentPlanTier: string
{
    case FREE = 'free';
    case PLUS = 'plus';
    case PRO = 'pro';

    public function labelAr(): string
    {
        return match ($this) {
            self::FREE => 'باقة الطالب المجانية',
            self::PLUS => 'باقة الطالب بلس (Plus)',
            self::PRO => 'باقة الطالب برو (Pro)',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::FREE => 'Student Free',
            self::PLUS => 'Student Plus',
            self::PRO => 'Student Pro',
        };
    }

    public function allowsAiFeatures(): bool
    {
        return $this === self::PRO;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
