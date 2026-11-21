<?php

namespace App\Enums;

enum StudentPlanTier: string
{
    case FREE = 'free';
    case PLUS = 'plus';
    case PRO = 'pro';

    public function rank(): int
    {
        return match ($this) {
            self::FREE => 0,
            self::PLUS => 1,
            self::PRO => 2,
        };
    }

    public function meets(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

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

    public function allowsLeagues(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsGamification(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsCertificates(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsAnalytics(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsAiFeatures(): bool
    {
        return $this->meets(self::PRO);
    }

    public static function requiredTierForFeature(string $feature): self
    {
        return match ($feature) {
            'leagues', 'gamification', 'certificates', 'analytics', 'quizzes' => self::PLUS,
            'smart_lecture', 'converted_lectures', 'revision', 'weak_areas', 'ai' => self::PRO,
            default => self::FREE,
        };
    }

    public function allowsFeature(string $feature): bool
    {
        return $this->meets(self::requiredTierForFeature($feature));
    }

    /**
     * @return array<string, bool>
     */
    public function unlockedFeatures(): array
    {
        return [
            'leagues' => $this->allowsLeagues(),
            'gamification' => $this->allowsGamification(),
            'certificates' => $this->allowsCertificates(),
            'analytics' => $this->allowsAnalytics(),
            'quizzes' => $this->meets(self::PLUS),
            'smart_lecture' => $this->allowsAiFeatures(),
            'converted_lectures' => $this->allowsAiFeatures(),
            'revision' => $this->allowsAiFeatures(),
            'weak_areas' => $this->allowsAiFeatures(),
        ];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
