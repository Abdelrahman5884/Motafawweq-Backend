<?php

namespace App\Enums;

enum TeacherPlanTier: string
{
    case FREE = 'free';
    case PLUS = 'plus';
    case PRO  = 'pro';

    public function label(): string
    {
        return __('messages.teacher_plans.' . $this->value);
    }

    /**
     * Maximum active courses allowed (null = unlimited).
     */
    public function maxCourses(): ?int
    {
        return match ($this) {
            self::FREE => 1,
            self::PLUS => 10,
            self::PRO  => null,
        };
    }

    /**
     * Maximum enrolled students capacity across groups/courses (null = unlimited).
     */
    public function maxStudents(): ?int
    {
        return match ($this) {
            self::FREE => 50,
            self::PLUS => 500,
            self::PRO  => null,
        };
    }

    /**
     * Maximum questions per manual bank on Free tier (null = unlimited).
     */
    public function maxQuestionsBank(): ?int
    {
        return match ($this) {
            self::FREE => 50,
            self::PLUS => null,
            self::PRO  => null,
        };
    }

    public function allowsCertificates(): bool
    {
        return match ($this) {
            self::FREE => false,
            self::PLUS, self::PRO => true,
        };
    }

    public function allowsCourseLeagues(): bool
    {
        return match ($this) {
            self::FREE => false,
            self::PLUS, self::PRO => true,
        };
    }

    public function allowsDynamicWatermark(): bool
    {
        return match ($this) {
            self::FREE => false,
            self::PLUS, self::PRO => true,
        };
    }

    public function allowsWhatsappParentAlerts(): bool
    {
        return match ($this) {
            self::FREE, self::PLUS => false,
            self::PRO => true,
        };
    }

    public function allowsAiFeatures(): bool
    {
        return match ($this) {
            self::PRO => true,
            default   => false,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

