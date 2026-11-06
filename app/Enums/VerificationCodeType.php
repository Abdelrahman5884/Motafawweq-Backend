<?php

namespace App\Enums;

enum VerificationCodeType: string
{
    case EMAIL_VERIFICATION = 'email_verification';
    case PASSWORD_RESET = 'password_reset';
}

