<?php

namespace App\Enums;

enum AnnouncementPriority: string
{
    case NORMAL = 'normal';
    case IMPORTANT = 'important';
    case URGENT = 'urgent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function weight(): int
    {
        return match ($this) {
            self::URGENT => 3,
            self::IMPORTANT => 2,
            self::NORMAL => 1,
        };
    }
}
