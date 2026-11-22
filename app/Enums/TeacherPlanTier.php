<?php

namespace App\Enums;

enum TeacherPlanTier: string
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

    public function label(): string
    {
        return __('messages.teacher_plans.'.$this->value);
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::FREE => 'الباقة المجانية للمعلم',
            self::PLUS => 'باقة المعلم بلس (Plus)',
            self::PRO => 'باقة المعلم برو (Pro)',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::FREE => 'Teacher Free',
            self::PLUS => 'Teacher Plus',
            self::PRO => 'Teacher Pro',
        };
    }

    /**
     * Maximum active courses allowed (null = unlimited).
     */
    public function maxCourses(): ?int
    {
        return match ($this) {
            self::FREE => 1,
            self::PLUS, self::PRO => null,
        };
    }

    /**
     * Maximum recorded/uploaded lessons allowed (null = unlimited).
     */
    public function maxLessons(): ?int
    {
        return match ($this) {
            self::FREE => 3,
            self::PLUS, self::PRO => null,
        };
    }

    /**
     * Maximum study groups allowed (null = unlimited).
     */
    public function maxGroups(): ?int
    {
        return match ($this) {
            self::FREE => 1,
            self::PLUS => 12,
            self::PRO => null,
        };
    }

    /**
     * Maximum students allowed inside a single study group (null = unlimited).
     */
    public function maxStudentsPerGroup(): ?int
    {
        return match ($this) {
            self::FREE => 50,
            self::PLUS, self::PRO => null,
        };
    }

    /**
     * Maximum enrolled students capacity across all groups (null = unlimited).
     */
    public function maxStudents(): ?int
    {
        return match ($this) {
            self::FREE => 50,
            self::PLUS, self::PRO => null,
        };
    }

    /**
     * Maximum questions per manual bank on Free tier (null = unlimited).
     */
    public function maxQuestionsBank(): ?int
    {
        return match ($this) {
            self::FREE => 50,
            self::PLUS, self::PRO => null,
        };
    }

    public function allowsSchedule(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsExams(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsHomework(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsCertificates(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsCourseLeagues(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsFinancials(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsDynamicWatermark(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsWhatsappParentAlerts(): bool
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
            'schedule', 'exams', 'homework', 'leagues', 'certificates', 'financials', 'watermark', 'whatsapp_alerts' => self::PLUS,
            'ai', 'ai_processing', 'ai_studio', 'ai_transcript', 'ai_mindmap' => self::PRO,
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
            'schedule' => $this->allowsSchedule(),
            'exams' => $this->allowsExams(),
            'homework' => $this->allowsHomework(),
            'leagues' => $this->allowsCourseLeagues(),
            'certificates' => $this->allowsCertificates(),
            'financials' => $this->allowsFinancials(),
            'watermark' => $this->allowsDynamicWatermark(),
            'whatsapp_alerts' => $this->allowsWhatsappParentAlerts(),
            'ai' => $this->allowsAiFeatures(),
            'ai_processing' => $this->allowsAiFeatures(),
            'ai_studio' => $this->allowsAiFeatures(),
            'ai_transcript' => $this->allowsAiFeatures(),
            'ai_mindmap' => $this->allowsAiFeatures(),
        ];
    }

    /**
     * @return array<string, int|bool|null>
     */
    public function quotas(): array
    {
        return [
            'maxCourses' => $this->maxCourses(),
            'maxLessons' => $this->maxLessons(),
            'maxGroups' => $this->maxGroups(),
            'maxStudentsPerGroup' => $this->maxStudentsPerGroup(),
            'maxStudents' => $this->maxStudents(),
            'allowsAi' => $this->allowsAiFeatures(),
        ];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
