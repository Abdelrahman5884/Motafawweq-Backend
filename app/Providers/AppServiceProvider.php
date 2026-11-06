<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        $tooMany = fn (string $message) => fn () => response()->json([
            'success' => false,
            'message' => $message,
        ], 429);

        // General API rate limiter (60 requests per minute per authenticated user / IP)
        RateLimiter::for('api', function (Request $request) use ($tooMany) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response($tooMany('تم تجاوز معدل الطلبات المسموح به. يرجى الانتظار قليلاً.'));
        });

        // Login / register: 5 per minute per (email + IP), and 20 per minute per IP overall
        RateLimiter::for('auth', function (Request $request) use ($tooMany) {
            $email = strtolower((string) $request->input('email'));
            $response = $tooMany('محاولات كثيرة جداً. تم حظر الطلبات مؤقتاً لحماية الحساب. يرجى المحاولة بعد دقيقة.');

            return [
                Limit::perMinute(5)->by('auth:' . $email . '|' . $request->ip())->response($response),
                Limit::perMinute(20)->by('auth-ip:' . $request->ip())->response($response),
            ];
        });

        // OTP sending / verification: 5 per minute, 20 per hour per user (or IP)
        RateLimiter::for('otp', function (Request $request) use ($tooMany) {
            $key = $request->user()?->id ?: strtolower((string) $request->input('email')) . '|' . $request->ip();
            $response = $tooMany('محاولات كثيرة جداً على رمز التحقق. يرجى الانتظار ثم المحاولة مجدداً.');

            return [
                Limit::perMinute(5)->by('otp-m:' . $key)->response($response),
                Limit::perHour(20)->by('otp-h:' . $key)->response($response),
            ];
        });

        // Token refresh: 10 per minute per IP
        RateLimiter::for('refresh', function (Request $request) use ($tooMany) {
            return Limit::perMinute(10)->by('refresh:' . $request->ip())
                ->response($tooMany('طلبات تجديد كثيرة جداً. يرجى المحاولة لاحقاً.'));
        });

        // Generic strict limiter for other sensitive operations
        RateLimiter::for('strict', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });
    }
}
