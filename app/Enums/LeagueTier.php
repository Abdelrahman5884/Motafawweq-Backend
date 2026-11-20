<?php

namespace App\Enums;

enum LeagueTier: string
{
    case DIAMOND = 'Diamond';
    case GOLD = 'Gold';
    case SILVER = 'Silver';
    case BRONZE = 'Bronze';

    public static function resolveFromXp(int $xp): self
    {
        return match (true) {
            $xp >= 251 => self::DIAMOND,
            $xp >= 151 => self::GOLD,
            $xp >= 66 => self::SILVER,
            default => self::BRONZE,
        };
    }

    public function minXp(): int
    {
        return match ($this) {
            self::DIAMOND => 251,
            self::GOLD => 151,
            self::SILVER => 66,
            self::BRONZE => 0,
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::DIAMOND => 'دوري الماسي',
            self::GOLD => 'دوري ذهبي',
            self::SILVER => 'دوري فضي',
            self::BRONZE => 'دوري برونزي',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
