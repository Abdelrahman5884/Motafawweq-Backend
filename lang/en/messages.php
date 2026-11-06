<?php

return [
    // General API responses
    'operation_success'   => 'Operation completed successfully.',
    'resource_created'    => 'Resource created successfully.',
    'general_error'       => 'An error occurred.',
    'validation_failed'   => 'The given data was invalid. Please review the errors.',
    'unauthenticated'     => 'Unauthenticated. Please sign in first to access this resource.',
    'forbidden'           => 'You do not have permission to perform this action.',
    'not_found'           => 'The requested resource was not found.',
    'data_retrieved'      => 'Data retrieved successfully.',
    'too_many_requests'   => 'Too many requests. Please wait a moment and try again.',
    'server_error'        => 'An unexpected server error occurred. Please try again later.',
    'invalid_token_type'  => 'Invalid token type for this request.',

    // Rate limiters
    'rate_limit_api'      => 'Rate limit exceeded. Please wait a moment.',
    'rate_limit_auth'     => 'Too many attempts. Requests are temporarily blocked to protect your account. Please try again in a minute.',
    'rate_limit_otp'      => 'Too many verification attempts. Please wait before trying again.',
    'rate_limit_refresh'  => 'Too many token refresh requests. Please try again later.',

    // Auth Security Exceptions
    'invalid_credentials'   => 'Invalid email address or password.',
    'account_locked'        => 'Account is temporarily locked due to repeated failed login attempts. Try again in :minutes minute(s).',
    'account_suspended'     => 'This account has been suspended. Please contact platform support.',
    'account_pending'       => 'Your account is pending administrator review. You will be notified once activated.',
    'email_not_verified'    => 'Please verify your email address first to access this section.',
    'invalid_refresh_token' => 'Your session is invalid or has expired. Please sign in again.',

    // Auth Controller & Services
    'register_success'        => 'Account created successfully. A verification code has been sent to your email.',
    'login_success'           => 'Signed in successfully.',
    'refresh_success'         => 'Session refreshed successfully.',
    'profile_fetched'         => 'Profile retrieved successfully.',
    'profile_updated'         => 'Profile updated successfully.',
    'password_changed'        => 'Password changed successfully and all other devices have been signed out.',
    'current_password_wrong'  => 'The current password is incorrect.',
    'logout_success'          => 'Signed out successfully.',
    'logout_all_success'      => 'Signed out from all devices successfully.',
    'email_verified_success'  => 'Email address verified successfully.',
    'email_already_verified'  => 'Email address is already verified.',
    'email_otp_resent'        => 'A new verification code has been sent to your email.',
    'password_forgot_sent'    => 'If this email address is registered with us, you will receive a password reset code shortly.',
    'password_reset_success'  => 'Your password has been reset successfully. Please sign in.',
    'sessions_fetched'        => 'Active sessions retrieved.',
    'session_not_found'       => 'Session not found.',
    'session_revoked'         => 'Device signed out successfully.',
    'user_deleted'            => 'User deleted successfully.',
    'role_not_allowed'        => 'The selected role is not available for registration.',
    'invalid_student_code'    => 'Invalid student code. No student was found with this code.',

    // OTP Service
    'otp_cooldown'      => 'Please wait :seconds seconds before requesting a new code.',
    'otp_invalid'       => 'The verification code is invalid or expired. Please request a new code.',
    'otp_max_attempts'  => 'Maximum verification attempts exceeded for this code. Please request a new code.',
    'otp_wrong'         => 'Incorrect verification code. Remaining attempts: :remaining.',

    // Role & Status Labels
    'roles' => [
        'admin'   => 'System Administrator',
        'teacher' => 'Teacher',
        'student' => 'Student',
        'parent'  => 'Parent',
        'center'  => 'Learning Center Admin',
    ],
    'statuses' => [
        'active'    => 'Active',
        'suspended' => 'Suspended',
        'pending'   => 'Pending Approval',
    ],
];
