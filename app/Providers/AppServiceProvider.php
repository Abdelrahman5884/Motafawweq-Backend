<?php

namespace App\Providers;

use App\Mail\Transports\BrevoApiTransport;
use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Extended token model (access/refresh type, session pairing, device info)
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Register Brevo HTTP API mail transport
        Mail::extend('brevo', function () {
            return new BrevoApiTransport((string) config('services.brevo.key'));
        });

        $this->configurePasswordPolicy();
        $this->configureRateLimiting();
    }

    /**
     * Platform-wide password policy used by every Password::defaults() rule.
     */
    protected function configurePasswordPolicy(): void
    {
        Password::defaults(function () {
            $rule = Password::min(10)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();

            // In production also reject passwords found in known data breaches (HaveIBeenPwned k-anonymity API)
            return $this->app->environment('production') ? $rule->uncompromised(3) : $rule;
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        $tooMany = fn (string $translationKey) => fn () => response()->json([
            'success' => false,
            'message' => __($translationKey),
        ], 429);

        // General API rate limiter (60 requests per minute per authenticated user / IP)
        RateLimiter::for('api', function (Request $request) use ($tooMany) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Login / register: 5 per minute per (email + IP), and 20 per minute per IP overall
        RateLimiter::for('auth', function (Request $request) use ($tooMany) {
            $email = strtolower((string) $request->input('email'));
            $response = $tooMany('messages.rate_limit_auth');

            return [
                Limit::perMinute(5)->by('auth:'.$email.'|'.$request->ip())->response($response),
                Limit::perMinute(20)->by('auth-ip:'.$request->ip())->response($response),
            ];
        });

        // OTP sending / verification: 15 per minute, 100 per hour per user (or IP)
        RateLimiter::for('otp', function (Request $request) use ($tooMany) {
            $key = $request->user()?->id ?: strtolower((string) $request->input('email')).'|'.$request->ip();
            $response = $tooMany('messages.rate_limit_otp');

            return [
                Limit::perMinute(15)->by('otp-m:'.$key)->response($response),
                Limit::perHour(100)->by('otp-h:'.$key)->response($response),
            ];
        });

        // Token refresh: 10 per minute per IP
        RateLimiter::for('refresh', function (Request $request) use ($tooMany) {
            return Limit::perMinute(10)->by('refresh:'.$request->ip())
                ->response($tooMany('messages.rate_limit_refresh'));
        });

        // Generic strict limiter for other sensitive operations
        RateLimiter::for('strict', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        // Teacher media & file uploads: 15 uploads per minute per teacher
        RateLimiter::for('teacher-uploads', function (Request $request) use ($tooMany) {
            return Limit::perMinute(15)
                ->by('teacher-upload:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_teacher_uploads'));
        });

        // Classroom QR / Barcode attendance scanning: 45 scans per minute per teacher
        RateLimiter::for('qr-scan', function (Request $request) use ($tooMany) {
            return Limit::perMinute(45)
                ->by('qr-scan:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_qr_scan'));
        });

        // Sensitive financial & payout mutations: 10 per minute per teacher
        RateLimiter::for('financial-mutation', function (Request $request) use ($tooMany) {
            return Limit::perMinute(10)
                ->by('fin-mut:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_financial'));
        });

        // Group join code attempts: 8 per minute per user/IP (anti-brute-force)
        RateLimiter::for('join-code', function (Request $request) use ($tooMany) {
            return Limit::perMinute(8)
                ->by('join-code:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_join_code'));
        });

        // Exam submission attempts: 10 per minute per student/IP
        RateLimiter::for('exam-submit', function (Request $request) use ($tooMany) {
            return Limit::perMinute(10)
                ->by('exam-sub:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_exam_submit'));
        });

        // Student read endpoints: 120 per minute per student
        RateLimiter::for('student-read', function (Request $request) use ($tooMany) {
            return Limit::perMinute(120)
                ->by('std-read:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Student write mutations: 30 per minute per student
        RateLimiter::for('student-write', function (Request $request) use ($tooMany) {
            return Limit::perMinute(30)
                ->by('std-write:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Student lesson progress heartbeat: 12 per minute per student
        RateLimiter::for('progress-heartbeat', function (Request $request) use ($tooMany) {
            return Limit::perMinute(12)
                ->by('prog-hb:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Student daily platform activity & streak heartbeat: 15 per minute per student
        RateLimiter::for('activity-heartbeat', function (Request $request) use ($tooMany) {
            return Limit::perMinute(15)
                ->by('act-hb:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Student homework/receipt uploads: 10 per minute per student
        RateLimiter::for('student-uploads', function (Request $request) use ($tooMany) {
            return Limit::perMinute(10)
                ->by('std-upload:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_teacher_uploads'));
        });

        // Student checkout & coupon verification: 8 per minute per student
        RateLimiter::for('student-checkout', function (Request $request) use ($tooMany) {
            return Limit::perMinute(8)
                ->by('std-checkout:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_financial'));
        });

        // Center read endpoints: 120 per minute per center
        RateLimiter::for('center-read', function (Request $request) use ($tooMany) {
            return Limit::perMinute(120)
                ->by('ctr-read:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Center write mutations: 45 per minute per center
        RateLimiter::for('center-write', function (Request $request) use ($tooMany) {
            return Limit::perMinute(45)
                ->by('ctr-write:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // High-speed Center QR / Barcode entrance gate scanner: 90 scans per minute
        RateLimiter::for('center-qr-scan', function (Request $request) use ($tooMany) {
            return Limit::perMinute(90)
                ->by('ctr-qr:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_qr_scan'));
        });

        // Center financial mutations, settlements & subscription checkout: 15 per minute
        RateLimiter::for('center-financial', function (Request $request) use ($tooMany) {
            return Limit::perMinute(15)
                ->by('ctr-fin:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_financial'));
        });

        // Center bulk data imports & cloud backups: 6 per minute
        RateLimiter::for('center-import', function (Request $request) use ($tooMany) {
            return Limit::perMinute(6)
                ->by('ctr-import:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Center parent absence/payment alerts: 20 per minute
        RateLimiter::for('center-alerts', function (Request $request) use ($tooMany) {
            return Limit::perMinute(20)
                ->by('ctr-alerts:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Admin read endpoints: 120 per minute per admin
        RateLimiter::for('admin-read', function (Request $request) use ($tooMany) {
            return Limit::perMinute(120)
                ->by('adm-read:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Admin standard write mutations: 45 per minute per admin
        RateLimiter::for('admin-write', function (Request $request) use ($tooMany) {
            return Limit::perMinute(45)
                ->by('adm-write:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Admin financial operations (payouts, pricing plans, coupons): 15 per minute
        RateLimiter::for('admin-financial', function (Request $request) use ($tooMany) {
            return Limit::perMinute(15)
                ->by('adm-fin:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_financial'));
        });

        // Admin high-sensitivity security actions (deletes, resets, maintenance, cache flush, exports): 10 per minute
        RateLimiter::for('admin-sensitive', function (Request $request) use ($tooMany) {
            return Limit::perMinute(10)
                ->by('adm-sens:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Public cached catalog endpoints (champions, announcements, pricing): 120 per minute per IP
        RateLimiter::for('public-catalog', function (Request $request) use ($tooMany) {
            return Limit::perMinute(120)
                ->by('pub-cat:'.$request->ip())
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Parent portal read endpoints: 120 per minute per parent
        RateLimiter::for('parent-read', function (Request $request) use ($tooMany) {
            return Limit::perMinute(120)
                ->by('par-read:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Parent portal write mutations (settings, inquiries, unlink): 30 per minute per parent
        RateLimiter::for('parent-write', function (Request $request) use ($tooMany) {
            return Limit::perMinute(30)
                ->by('par-write:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_api'));
        });

        // Parent child-code linking: 10 per minute per parent (anti-enumeration)
        RateLimiter::for('parent-link', function (Request $request) use ($tooMany) {
            return Limit::perMinute(10)
                ->by('par-link:'.($request->user()?->id ?: $request->ip()))
                ->response($tooMany('messages.rate_limit_join_code'));
        });
    }
}
