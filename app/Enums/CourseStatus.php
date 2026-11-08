<?php

namespace App\Enums;

enum CourseStatus: string
{
    case DRAFT     = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED  = 'archived';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

