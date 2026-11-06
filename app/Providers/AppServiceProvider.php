<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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

        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // General API rate limiter (60 requests per minute per IP / authenticated user)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            )->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'تم تجاوز معدل الطلبات المسموح به (60 طلب في الدقيقة). يرجى الانتظار قليلاً.',
                ], 429);
            });
        });

        // Strict rate limiter for Authentication endpoints to prevent brute-force attacks
        // 5 requests per minute per IP + email
        RateLimiter::for('auth', function (Request $request) {
            $email = (string) $request->input('email');
            $key = 'auth:' . $email . '|' . $request->ip();

            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'محاولات دخول كثيرة جداً غير صحيحة. تم حظر الطلبات مؤقتاً لحماية الحساب. يرجى الانتظار دقيقة والمحاولة مجدداً.',
                ], 429);
            });
        });

        // Strict rate limiter for sensitive operations (password reset, OTP, etc.)
        RateLimiter::for('strict', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
