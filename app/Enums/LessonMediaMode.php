<?php

namespace App\Enums;

enum LessonMediaMode: string
{
    case MIC   = 'mic';
    case AUDIO = 'audio';
    case VIDEO = 'video';
    case EMBED = 'embed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

