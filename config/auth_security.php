<?php

/*
|--------------------------------------------------------------------------
| Motafawweq Authentication Security Configuration
|--------------------------------------------------------------------------
| Central place for all auth hardening knobs. Override any value via .env.
*/

return [

    // Short-lived access token + long-lived rotating refresh token
    'tokens' => [
        'access_ttl_minutes'  => (int) env('AUTH_ACCESS_TTL', 60),
        'refresh_ttl_minutes' => (int) env('AUTH_REFRESH_TTL', 60 * 24 * 30), // 30 days
        'max_sessions'        => (int) env('AUTH_MAX_SESSIONS', 5),            // concurrent devices per user
    ],

    // Account lockout after repeated failed logins
    'lockout' => [
        'max_attempts'  => (int) env('AUTH_LOCKOUT_ATTEMPTS', 5),
        'decay_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
    ],

    // One-time codes (email verification / password reset)
    'otp' => [
        'length'                 => 6,
        'ttl_minutes'            => (int) env('AUTH_OTP_TTL', 10),
        'max_attempts'           => (int) env('AUTH_OTP_ATTEMPTS', 5),
        'resend_cooldown_seconds'=> (int) env('AUTH_OTP_COOLDOWN', 60),
    ],

    // Roles a visitor may choose during self-registration (admin is NEVER self-assignable)
    'self_registration_roles' => ['student', 'parent', 'teacher'],

    // Roles that require admin approval before they can log in
    'roles_requiring_approval' => ['teacher'],
];

