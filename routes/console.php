<?php

use App\Repositories\Contracts\VerificationCodeRepositoryInterface;
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

// Security housekeeping (run `php artisan schedule:work` locally or a cron in production)
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:prune-codes')->hourly();
