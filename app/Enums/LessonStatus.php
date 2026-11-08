<?php

namespace App\Enums;

enum LessonStatus: string
{
    case DRAFT     = 'draft';
    case READY     = 'ready';
    case PUBLISHED = 'published';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

