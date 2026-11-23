<?php

namespace App\Enums;

enum CenterPlanTier: string
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
        return __('messages.center_plans.'.$this->value);
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::FREE => 'باقة السنتر المجانية',
            self::PLUS => 'باقة السنتر بلس (Plus)',
            self::PRO => 'باقة السنتر برو (Pro)',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::FREE => 'Center Free',
            self::PLUS => 'Center Plus',
            self::PRO => 'Center Pro',
        };
    }

    /**
     * Maximum active users (enrolled students + active center teachers + staff), null = unlimited.
     */
    public function maxUsers(): ?int
    {
        return match ($this) {
            self::FREE => 20,
            self::PLUS => 500,
            self::PRO => null,
        };
    }

    /**
     * Maximum active study groups / cohorts across the center, null = unlimited.
     */
    public function maxGroups(): ?int
    {
        return match ($this) {
            self::FREE => 2,
            self::PLUS => 20,
            self::PRO => null,
        };
    }

    /**
     * Maximum active teachers affiliated with the center, null = unlimited.
     */
    public function maxTeachers(): ?int
    {
        return match ($this) {
            self::FREE => 2,
            self::PLUS => 12,
            self::PRO => null,
        };
    }

    /**
     * Maximum classrooms / halls across the center, null = unlimited.
     */
    public function maxRooms(): ?int
    {
        return match ($this) {
            self::FREE => 1,
            self::PLUS => 8,
            self::PRO => null,
        };
    }

    /**
     * Maximum center branches, null = unlimited.
     */
    public function maxBranches(): ?int
    {
        return match ($this) {
            self::FREE => 1,
            self::PLUS => 5,
            self::PRO => null,
        };
    }

    /**
     * Maximum administrative staff accounts, null = unlimited.
     */
    public function maxStaff(): ?int
    {
        return match ($this) {
            self::FREE => 0,
            self::PLUS => 15,
            self::PRO => null,
        };
    }

    public function allowsQrScanner(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsMasterSchedule(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsStaffManagement(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsFinancials(): bool
    {
        return $this->meets(self::PLUS);
    }

    public function allowsAuditLogs(): bool
    {
        return $this->meets(self::PRO);
    }

    public function allowsExecutiveReports(): bool
    {
        return $this->meets(self::PRO);
    }

    public function allowsWhatsappAlerts(): bool
    {
        return $this->meets(self::PRO);
    }

    public function allowsWhiteLabel(): bool
    {
        return $this->meets(self::PRO);
    }

    public function allowsCloudBackup(): bool
    {
        return $this->meets(self::PRO);
    }

    public static function requiredTierForFeature(string $feature): self
    {
        return match ($feature) {
            'qr_scanner', 'master_schedule', 'staff_management', 'financials', 'settlements', 'expenses' => self::PLUS,
            'audit_logs', 'executive_reports', 'whatsapp_alerts', 'white_label', 'cloud_backup' => self::PRO,
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
            'qr_scanner' => $this->allowsQrScanner(),
            'master_schedule' => $this->allowsMasterSchedule(),
            'staff_management' => $this->allowsStaffManagement(),
            'financials' => $this->allowsFinancials(),
            'settlements' => $this->allowsFinancials(),
            'expenses' => $this->allowsFinancials(),
            'audit_logs' => $this->allowsAuditLogs(),
            'executive_reports' => $this->allowsExecutiveReports(),
            'whatsapp_alerts' => $this->allowsWhatsappAlerts(),
            'white_label' => $this->allowsWhiteLabel(),
            'cloud_backup' => $this->allowsCloudBackup(),
        ];
    }

    /**
     * @return array<string, int|null>
     */
    public function quotas(): array
    {
        return [
            'maxUsers' => $this->maxUsers(),
            'maxGroups' => $this->maxGroups(),
            'maxTeachers' => $this->maxTeachers(),
            'maxRooms' => $this->maxRooms(),
            'maxBranches' => $this->maxBranches(),
            'maxStaff' => $this->maxStaff(),
        ];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
