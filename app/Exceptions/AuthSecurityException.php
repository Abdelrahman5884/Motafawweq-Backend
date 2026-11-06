<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain exception for authentication/security failures.
 * Renders itself as the platform's standard JSON error envelope.
 */
class AuthSecurityException extends Exception
{
    public function __construct(
        string $message,
        protected int $statusCode = Response::HTTP_UNAUTHORIZED,
        protected ?string $errorCode = null,
        protected array $extra = []
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('البريد الإلكتروني أو كلمة المرور غير صحيحة.', Response::HTTP_UNAUTHORIZED, 'INVALID_CREDENTIALS');
    }

    public static function accountLocked(int $secondsRemaining): self
    {
        $minutes = max(1, (int) ceil($secondsRemaining / 60));

        return new self(
            "تم قفل الحساب مؤقتاً بسبب محاولات دخول فاشلة متكررة. حاول مرة أخرى بعد {$minutes} دقيقة.",
            Response::HTTP_LOCKED,
            'ACCOUNT_LOCKED',
            ['retry_after_seconds' => $secondsRemaining]
        );
    }

    public static function accountSuspended(): self
    {
        return new self('تم تعليق هذا الحساب. يرجى التواصل مع إدارة المنصة.', Response::HTTP_FORBIDDEN, 'ACCOUNT_SUSPENDED');
    }

    public static function accountPending(): self
    {
        return new self('حسابك قيد المراجعة من إدارة المنصة. سيتم إشعارك عند التفعيل.', Response::HTTP_FORBIDDEN, 'ACCOUNT_PENDING');
    }

    public static function emailNotVerified(): self
    {
        return new self('يجب تأكيد البريد الإلكتروني أولاً للوصول إلى هذا القسم.', Response::HTTP_FORBIDDEN, 'EMAIL_NOT_VERIFIED');
    }

    public static function invalidRefreshToken(): self
    {
        return new self('جلسة الدخول غير صالحة أو منتهية. يرجى تسجيل الدخول مجدداً.', Response::HTTP_UNAUTHORIZED, 'INVALID_REFRESH_TOKEN');
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'success'    => false,
            'message'    => $this->getMessage(),
            'error_code' => $this->errorCode,
            'meta'       => $this->extra ?: null,
        ], fn ($v) => $v !== null), $this->statusCode);
    }
}

