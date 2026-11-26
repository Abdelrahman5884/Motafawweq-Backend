<?php

use App\Repositories\Contracts\VerificationCodeRepositoryInterface;
use App\Services\V1\Student\StudentActivityService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('auth:prune-codes', function (VerificationCodeRepositoryInterface $codes) {
    $deleted = $codes->pruneStale(24);
    $this->info("Pruned {$deleted} stale verification codes.");
})->purpose('Delete expired/used OTP verification codes');

Artisan::command('students:expire-streaks', function (StudentActivityService $activityService) {
    $expired = $activityService->expireStaleStreaks();
    $this->info("Expired {$expired} broken student streaks.");
})->purpose('Reset current_streak_days to 0 for students who missed a full calendar day');

// Security & activity housekeeping (run `php artisan schedule:work` locally or a cron in production)
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:prune-codes')->hourly();
Schedule::command('students:expire-streaks')->hourly();
