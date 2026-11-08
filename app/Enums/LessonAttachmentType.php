<?php

namespace App\Enums;

enum LessonAttachmentType: string
{
    case PDF              = 'pdf';
    case WHITEBOARD_IMAGE = 'whiteboard_image';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

