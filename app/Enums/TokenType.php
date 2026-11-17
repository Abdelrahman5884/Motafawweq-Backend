<?php

namespace App\Enums;

enum TokenType: string
{
    case ACCESS = 'access';
    case REFRESH = 'refresh';
}
