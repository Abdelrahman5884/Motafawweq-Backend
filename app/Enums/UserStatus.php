<?php

namespace App\Enums;

enum UserStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case PENDING = 'pending';

    /**
     * Get all status values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get human-readable label in Arabic.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'نشط',
            self::SUSPENDED => 'معلق / محظور',
            self::PENDING => 'قيد المراجعة',
        };
    }
}

