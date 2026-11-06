<?php

namespace App\Services\V1\Auth;

use App\Enums\VerificationCodeType;
use App\Models\User;
use App\Repositories\Contracts\VerificationCodeRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;

/**
 * One-time-password engine.
 *
 * - Cryptographically secure codes (random_int)
 * - Only an HMAC-SHA256 (keyed with APP_KEY and bound to user+purpose) is stored
 * - Constant-time comparison, attempt limit, expiry, single use, resend cooldown
 */
class OtpService extends BaseService
{
    public function __construct(
        protected VerificationCodeRepositoryInterface $codes
    ) {}

    /**
     * Generate a fresh code (invalidating previous ones) and return the plain value.
     *
     * @throws ThrottleRequestsException when called again inside the cooldown window
     */
    public function generate(User $user, VerificationCodeType $type, ?string $ip = null): string
    {
        $cooldown = (int) config('auth_security.otp.resend_cooldown_seconds');
        $latest = $this->codes->findLatest($user, $type);

        if ($latest && $latest->created_at->diffInSeconds(now()) < $cooldown) {
            $wait = $cooldown - (int) $latest->created_at->diffInSeconds(now());
            throw new ThrottleRequestsException("يرجى الانتظار {$wait} ثانية قبل طلب رمز جديد.", null, ['Retry-After' => $wait]);
        }

        $this->codes->invalidateAll($user, $type);

        $length = (int) config('auth_security.otp.length');
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        $this->codes->create([
            'user_id'    => $user->id,
            'type'       => $type->value,
            'code_hash'  => $this->hash($user, $type, $code),
            'expires_at' => now()->addMinutes((int) config('auth_security.otp.ttl_minutes')),
            'ip_address' => $ip,
        ]);

        return $code;
    }

    /**
     * Verify and consume a code.
     *
     * @throws ValidationException
     */
    public function verify(User $user, VerificationCodeType $type, string $code): void
    {
        $record = $this->codes->findActive($user, $type);
        $maxAttempts = (int) config('auth_security.otp.max_attempts');

        if (!$record) {
            $this->fail('الرمز غير صالح أو منتهي الصلاحية. اطلب رمزاً جديداً.');
        }

        if ($record->attempts >= $maxAttempts) {
            $record->forceFill(['used_at' => now()])->save();
            $this->fail('تم تجاوز عدد المحاولات المسموح بها لهذا الرمز. اطلب رمزاً جديداً.');
        }

        if (!hash_equals($record->code_hash, $this->hash($user, $type, $code))) {
            $record->increment('attempts');
            $remaining = max(0, $maxAttempts - $record->attempts);

            $this->logSecurityEvent('OTP_VERIFICATION_FAILED', [
                'user_id'   => $user->id,
                'type'      => $type->value,
                'remaining' => $remaining,
            ], 'warning');

            if ($remaining === 0) {
                $record->forceFill(['used_at' => now()])->save();
                $this->fail('تم تجاوز عدد المحاولات المسموح بها لهذا الرمز. اطلب رمزاً جديداً.');
            }

            $this->fail("الرمز غير صحيح. المحاولات المتبقية: {$remaining}.");
        }

        $record->forceFill(['used_at' => now()])->save();
    }

    protected function hash(User $user, VerificationCodeType $type, string $code): string
    {
        return hash_hmac('sha256', "{$user->id}|{$type->value}|{$code}", (string) config('app.key'));
    }

    /**
     * @return never
     */
    protected function fail(string $message): void
    {
        throw ValidationException::withMessages(['code' => [$message]]);
    }
}

