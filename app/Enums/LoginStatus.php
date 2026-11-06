<?php

namespace App\Enums;

enum LoginStatus: string
{
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case LOCKED = 'locked';
    case BLOCKED = 'blocked';
}

