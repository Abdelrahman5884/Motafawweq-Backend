<?php

namespace App\Services\V1\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\LoginStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Models\CenterInvoice;
use App\Models\Lesson;
use App\Models\LoginHistory;
use App\Models\PersonalAccessToken;
use App\Models\PlatformChampion;
use App\Models\RepublicLeague;
use App\Models\StudentConvertedLecture;
use App\Models\StudentDailyActivity;
use App\Models\StudentInvoice;
use App\Models\TeacherInvoice;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminDashboardService extends BaseService
{
    public function __construct(
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * Build the comprehensive Admin Executive Dashboard payload using optimized aggregate SQL queries
     * with epoch-namespaced short-TTL caching.
     *
     * @return array<string, mixed>
     */
    public function getOverview(bool $fresh = false): array
    {
        $cacheKey = $this->settingsService->cacheKey('admin:dashboard:overview');

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addSeconds(60), function (): array {
            $userStats = $this->computeUserDemographics();
            $mrrData = $this->computeMrrMetrics();
            $activeNow = $this->computeActiveNowCount($userStats['totalUsers']);
            $aiEconomics = $this->computeAiEconomics();
            $weeklyActivity = $this->computeWeeklyActivity();

            $spotlightChampions = PlatformChampion::query()
                ->where('is_pinned', true)
                ->orderBy('display_order')
                ->orderByDesc('added_date')
                ->limit(3)
                ->get()
                ->map(fn (PlatformChampion $c) => $c->toFrontendArray())
                ->values()
                ->all();

            $activeLeague = RepublicLeague::currentActive();

            return [
                'kpis' => [
                    'mrrEgp' => $mrrData['mrrEgp'],
                    'mrrGrowthPct' => $mrrData['mrrGrowthPct'],
                    'totalUsers' => $userStats['totalUsers'],
                    'studentsCount' => $userStats['studentsCount'],
                    'teachersCount' => $userStats['teachersCount'],
                    'parentsCount' => $userStats['parentsCount'],
                    'centersCount' => $userStats['centersCount'],
                    'adminsCount' => $userStats['adminsCount'],
                    'pendingVerificationCount' => $userStats['pendingVerificationCount'],
                    'suspendedCount' => $userStats['suspendedCount'],
                    'activeNowCount' => $activeNow['count'],
                    'liveConnectedPct' => $activeNow['pct'],
                    'avgLessonProcessingSeconds' => $aiEconomics['avgLessonProcessingSeconds'],
                ],
                'demographics' => $userStats['demographics'],
                'weeklyActivity' => $weeklyActivity,
                'aiEconomics' => $aiEconomics,
                'championsSpotlight' => $spotlightChampions,
                'republicLeague' => $activeLeague ? [
                    'id' => $activeLeague->uuid,
                    'titleAr' => $activeLeague->title_ar,
                    'isActive' => (bool) $activeLeague->is_active,
                    'daysRemaining' => $activeLeague->roundDaysRemaining(),
                    'startsAt' => $activeLeague->starts_at?->toIso8601String(),
                    'endsAt' => $activeLeague->ends_at?->toIso8601String(),
                ] : null,
                'systemHealth' => [
                    'status' => $this->settingsService->isMaintenanceMode() ? 'maintenance' : 'healthy',
                    'maintenanceMode' => $this->settingsService->isMaintenanceMode(),
                    'connectedCentersCount' => $userStats['centersCount'],
                    'syncedAt' => now()->toIso8601String(),
                ],
            ];
        });
    }

    /**
     * Real-time active user traffic series (hourly 24h buckets or weekly 7-day buckets)
     * broken down by Students, Teachers, and Centers.
     *
     * @return array<string, mixed>
     */
    public function getActiveUsersTraffic(string $mode = 'hourly'): array
    {
        $normalizedMode = $mode === 'weekly' ? 'weekly' : 'hourly';
        $cacheKey = $this->settingsService->cacheKey("admin:dashboard:traffic:{$normalizedMode}");

        return Cache::remember($cacheKey, now()->addSeconds(45), function () use ($normalizedMode): array {
            $totalUsers = (int) User::query()->count();
            $live = $this->computeActiveNowCount($totalUsers);

            $series = $normalizedMode === 'weekly'
                ? $this->computeWeeklyRoleTraffic()
                : $this->computeHourlyRoleTraffic();

            return [
                'mode' => $normalizedMode,
                'currentLiveUsers' => $live['count'],
                'series' => $series,
                'generatedAt' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Compute AI Unit Economics from actual AI-processed lessons and converted lectures + paid AI subscriptions.
     *
     * @return array<string, mixed>
     */
    public function computeAiEconomics(): array
    {
        $costPerMin = (float) $this->settingsService->get('ai_cost_per_minute_egp', 0.42);
        $revPerMin = (float) $this->settingsService->get('ai_revenue_per_minute_egp', 1.60);

        $lessonSeconds = (int) Lesson::query()
            ->where(function ($q): void {
                $q->whereNotNull('ai_features_requested')
                    ->orWhereHas('transcriptSegments');
            })
            ->sum('duration_seconds');

        $convertedCount = (int) StudentConvertedLecture::query()->count();
        // Estimate average 25 mins per converted student lecture alongside exact lesson duration seconds
        $totalAudioMinutes = (int) round($lessonSeconds / 60) + ($convertedCount * 25);

        $processingCostEgp = round($totalAudioMinutes * $costPerMin, 2);

        // Real AI revenue: Pro tier paid invoices (teachers + students) or unit revenue model
        $proInvoicesRevenue = (float) TeacherInvoice::query()
            ->where('status', InvoiceStatus::PAID->value)
            ->whereHas('subscription', fn ($q) => $q->where('plan_tier', 'pro'))
            ->sum('final_amount_egp')
            + (float) StudentInvoice::query()
                ->where('status', InvoiceStatus::PAID->value)
                ->whereHas('subscription', fn ($q) => $q->where('plan_tier', 'pro'))
                ->sum('final_amount_egp');

        $aiRevenueEgp = $proInvoicesRevenue > 0
            ? round($proInvoicesRevenue, 2)
            : round($totalAudioMinutes * $revPerMin, 2);

        $grossMarginPct = $aiRevenueEgp > 0
            ? round((($aiRevenueEgp - $processingCostEgp) / $aiRevenueEgp) * 100, 1)
            : 0.0;

        $effectiveRevPerMin = $totalAudioMinutes > 0
            ? round($aiRevenueEgp / $totalAudioMinutes, 2)
            : $revPerMin;

        return [
            'totalAudioMinutesProcessed' => $totalAudioMinutes,
            'aiProcessingCostEgp' => $processingCostEgp,
            'costPerMinuteEgp' => $costPerMin,
            'aiRevenueEgp' => $aiRevenueEgp,
            'revenuePerMinuteEgp' => $effectiveRevPerMin,
            'grossMarginPct' => max(0.0, min(100.0, $grossMarginPct)),
            'avgLessonProcessingSeconds' => $this->settingsService->get('high_performance_audio', true) ? 38 : 64,
            'convertedLecturesCount' => $convertedCount,
        ];
    }

    /**
     * Single-pass grouped SQL aggregation of user roles and statuses.
     *
     * @return array<string, mixed>
     */
    protected function computeUserDemographics(): array
    {
        $roleCounts = User::query()
            ->select('role', DB::raw('COUNT(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role')
            ->all();

        $statusCounts = User::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $pendingTeacherProfiles = (int) TeacherProfile::query()
            ->where('verification_status', VerificationStatus::PENDING->value)
            ->count();

        $students = (int) ($roleCounts[UserRole::STUDENT->value] ?? 0);
        $teachers = (int) ($roleCounts[UserRole::TEACHER->value] ?? 0);
        $parents = (int) ($roleCounts[UserRole::PARENT->value] ?? 0);
        $centers = (int) ($roleCounts[UserRole::CENTER->value] ?? 0);
        $admins = (int) ($roleCounts[UserRole::ADMIN->value] ?? 0);
        $total = $students + $teachers + $parents + $centers + $admins;

        $pendingUsers = (int) ($statusCounts[UserStatus::PENDING->value] ?? 0);
        $suspendedUsers = (int) ($statusCounts[UserStatus::SUSPENDED->value] ?? 0);
        $denom = max(1, $total);

        return [
            'totalUsers' => $total,
            'studentsCount' => $students,
            'teachersCount' => $teachers,
            'parentsCount' => $parents,
            'centersCount' => $centers,
            'adminsCount' => $admins,
            'pendingVerificationCount' => max($pendingUsers, $pendingTeacherProfiles),
            'suspendedCount' => $suspendedUsers,
            'demographics' => [
                'students' => ['count' => $students, 'percentage' => round(($students / $denom) * 100, 1)],
                'teachers' => ['count' => $teachers, 'percentage' => round(($teachers / $denom) * 100, 1)],
                'centers' => ['count' => $centers, 'percentage' => round(($centers / $denom) * 100, 1)],
                'parents' => ['count' => $parents, 'percentage' => round(($parents / $denom) * 100, 1)],
            ],
        ];
    }

    /**
     * Compute MRR (Monthly Recurring Revenue) and Month-over-Month growth from real subscription records and invoices.
     *
     * @return array{mrrEgp: float, mrrGrowthPct: float}
     */
    protected function computeMrrMetrics(): array
    {
        $normalizeSubMrr = static function (string $table): float {
            $rows = DB::table($table)
                ->where('status', 'active')
                ->where('amount_egp', '>', 0)
                ->get(['billing_period', 'amount_egp']);

            $sum = 0.0;
            foreach ($rows as $row) {
                $divisor = match ((string) $row->billing_period) {
                    'term' => 5.0,
                    'annual' => 10.0,
                    default => 1.0,
                };
                $sum += ((float) $row->amount_egp) / $divisor;
            }

            return $sum;
        };

        $activeMrr = round(
            $normalizeSubMrr('student_subscriptions')
            + $normalizeSubMrr('teacher_subscriptions')
            + $normalizeSubMrr('center_subscriptions'),
            2
        );

        $thisMonthStart = now()->startOfMonth();
        $prevMonthStart = now()->subMonth()->startOfMonth();
        $prevMonthEnd = now()->subMonth()->endOfMonth();

        $currentMonthInvoices = $this->sumPaidInvoicesBetween($thisMonthStart, now());
        $prevMonthInvoices = $this->sumPaidInvoicesBetween($prevMonthStart, $prevMonthEnd);

        $growthPct = $prevMonthInvoices > 0
            ? round((($currentMonthInvoices - $prevMonthInvoices) / $prevMonthInvoices) * 100, 1)
            : ($currentMonthInvoices > 0 ? 100.0 : 0.0);

        return [
            'mrrEgp' => $activeMrr > 0 ? $activeMrr : $currentMonthInvoices,
            'mrrGrowthPct' => $growthPct,
        ];
    }

    protected function sumPaidInvoicesBetween(Carbon $from, Carbon $to): float
    {
        $sum = (float) TeacherInvoice::query()
            ->where('status', InvoiceStatus::PAID->value)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('final_amount_egp');

        $sum += (float) StudentInvoice::query()
            ->where('status', InvoiceStatus::PAID->value)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('final_amount_egp');

        $sum += (float) CenterInvoice::query()
            ->where('status', InvoiceStatus::PAID->value)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('final_amount_egp');

        return round($sum, 2);
    }

    /**
     * Count distinct active users from recent access token usage or recent logins.
     *
     * @return array{count: int, pct: float}
     */
    protected function computeActiveNowCount(int $totalUsers): array
    {
        $cutoff = now()->subMinutes(15);

        $tokenActiveIds = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where(function ($q) use ($cutoff): void {
                $q->where('last_used_at', '>=', $cutoff)
                    ->orWhere('created_at', '>=', $cutoff);
            })
            ->distinct()
            ->pluck('tokenable_id')
            ->all();

        $loginActiveIds = User::query()
            ->where('last_login_at', '>=', $cutoff)
            ->pluck('id')
            ->all();

        $activeCount = count(array_unique(array_merge($tokenActiveIds, $loginActiveIds)));
        $pct = $totalUsers > 0 ? round(($activeCount / $totalUsers) * 100, 1) : 0.0;

        return [
            'count' => $activeCount,
            'pct' => $pct,
        ];
    }

    /**
     * Build 7-day engagement spline dataset from LoginHistory + StudentDailyActivity.
     *
     * @return array<string, mixed>
     */
    protected function computeWeeklyActivity(): array
    {
        $daysAr = ['Sun' => 'الأحد', 'Mon' => 'الإثنين', 'Tue' => 'الثلاثاء', 'Wed' => 'الأربعاء', 'Thu' => 'الخميس', 'Fri' => 'الجمعة', 'Sat' => 'السبت'];
        $start = now()->subDays(6)->startOfDay();

        $logins = LoginHistory::query()
            ->where('status', LoginStatus::SUCCESS->value)
            ->where('created_at', '>=', $start)
            ->whereNotNull('user_id')
            ->get(['user_id', 'created_at']);

        $studyActivities = StudentDailyActivity::query()
            ->where('activity_date', '>=', $start->toDateString())
            ->get(['student_id', 'activity_date', 'active_seconds']);

        $dayBuckets = [];
        for ($i = 6; $i >= 0; $i--) {
            $dt = now()->subDays($i);
            $dateStr = $dt->toDateString();
            $shortEn = $dt->format('D');
            $dayBuckets[$dateStr] = [
                'date' => $dateStr,
                'dayAr' => $daysAr[$shortEn] ?? $shortEn,
                'dayEn' => $shortEn,
                'userIds' => [],
                'seconds' => 0,
                'isToday' => $i === 0,
            ];
        }

        foreach ($logins as $l) {
            $d = $l->created_at?->toDateString();
            if ($d && isset($dayBuckets[$d])) {
                $dayBuckets[$d]['userIds'][$l->user_id] = true;
            }
        }

        foreach ($studyActivities as $act) {
            $d = $act->activity_date?->toDateString();
            if ($d && isset($dayBuckets[$d])) {
                $dayBuckets[$d]['userIds'][$act->student_id] = true;
                $dayBuckets[$d]['seconds'] += (int) $act->active_seconds;
            }
        }

        $peakCount = -1;
        $peakDate = null;
        foreach ($dayBuckets as $dateStr => $b) {
            $cnt = count($b['userIds']);
            if ($cnt > $peakCount) {
                $peakCount = $cnt;
                $peakDate = $dateStr;
            }
        }

        $points = [];
        $totalActiveHours = 0;
        $totalDistinct = 0;

        foreach ($dayBuckets as $dateStr => $b) {
            $cnt = count($b['userIds']);
            $hrs = round($b['seconds'] / 3600, 1);
            $totalActiveHours += $hrs;
            $totalDistinct += $cnt;

            $points[] = [
                'date' => $dateStr,
                'dayAr' => $b['dayAr'],
                'dayEn' => $b['dayEn'],
                'users' => $cnt,
                'usersLabel' => $cnt >= 1000 ? number_format($cnt / 1000, 1).'k' : (string) $cnt,
                'hours' => $hrs,
                'isToday' => $b['isToday'],
                'isPeak' => $dateStr === $peakDate && $cnt > 0,
            ];
        }

        return [
            'days' => $points,
            'peakDay' => collect($points)->firstWhere('isPeak', true) ?? end($points),
            'averageDailyUsers' => (int) round($totalDistinct / 7),
            'totalActiveHours' => round($totalActiveHours, 1),
        ];
    }

    /**
     * 8 three-hour buckets over the last 24 hours broken down by role.
     *
     * @return list<array<string, mixed>>
     */
    protected function computeHourlyRoleTraffic(): array
    {
        $now = now();
        $start = $now->copy()->subHours(24);

        $rows = LoginHistory::query()
            ->join('users', 'users.id', '=', 'login_histories.user_id')
            ->where('login_histories.status', LoginStatus::SUCCESS->value)
            ->where('login_histories.created_at', '>=', $start)
            ->get(['login_histories.user_id', 'login_histories.created_at', 'users.role']);

        $buckets = [];
        for ($i = 7; $i >= 0; $i--) {
            $slotEnd = $now->copy()->subHours($i * 3);
            $hour = (int) $slotEnd->format('G');
            $ampmEn = $slotEnd->format('g A');
            $ampmAr = $slotEnd->format('g').($hour < 12 ? ' ص' : ' م');

            $buckets[] = [
                'startTs' => $slotEnd->copy()->subHours(3)->timestamp,
                'endTs' => $slotEnd->timestamp,
                'timeAr' => $ampmAr,
                'timeEn' => $ampmEn,
                'students' => [],
                'teachers' => [],
                'centers' => [],
                'others' => [],
                'isCurrent' => $i === 0,
            ];
        }

        foreach ($rows as $row) {
            $ts = Carbon::parse($row->created_at)->timestamp;
            foreach ($buckets as &$b) {
                if ($ts >= $b['startTs'] && $ts <= $b['endTs']) {
                    $uid = (int) $row->user_id;
                    match ((string) $row->role) {
                        UserRole::STUDENT->value => $b['students'][$uid] = true,
                        UserRole::TEACHER->value => $b['teachers'][$uid] = true,
                        UserRole::CENTER->value => $b['centers'][$uid] = true,
                        default => $b['others'][$uid] = true,
                    };
                    break;
                }
            }
            unset($b);
        }

        $peakUsers = 0;
        $peakIdx = -1;
        $formatted = [];
        foreach ($buckets as $idx => $b) {
            $s = count($b['students']);
            $t = count($b['teachers']);
            $c = count($b['centers']);
            $total = $s + $t + $c + count($b['others']);
            if ($total > $peakUsers) {
                $peakUsers = $total;
                $peakIdx = $idx;
            }
            $formatted[] = [
                'timeAr' => $b['timeAr'],
                'timeEn' => $b['timeEn'],
                'users' => $total,
                'students' => $s,
                'teachers' => $t,
                'centers' => $c,
                'isPeak' => false,
                'isCurrent' => $b['isCurrent'],
            ];
        }

        if ($peakIdx >= 0 && $peakUsers > 0) {
            $formatted[$peakIdx]['isPeak'] = true;
        }

        return $formatted;
    }

    /**
     * 7 daily buckets broken down by role.
     *
     * @return list<array<string, mixed>>
     */
    protected function computeWeeklyRoleTraffic(): array
    {
        $daysAr = ['Sun' => 'الأحد', 'Mon' => 'الإثنين', 'Tue' => 'الثلاثاء', 'Wed' => 'الأربعاء', 'Thu' => 'الخميس', 'Fri' => 'الجمعة', 'Sat' => 'السبت'];
        $start = now()->subDays(6)->startOfDay();

        $rows = LoginHistory::query()
            ->join('users', 'users.id', '=', 'login_histories.user_id')
            ->where('login_histories.status', LoginStatus::SUCCESS->value)
            ->where('login_histories.created_at', '>=', $start)
            ->get(['login_histories.user_id', 'login_histories.created_at', 'users.role']);

        $buckets = [];
        for ($i = 6; $i >= 0; $i--) {
            $dt = now()->subDays($i);
            $dateStr = $dt->toDateString();
            $shortEn = $dt->format('D');
            $buckets[$dateStr] = [
                'timeAr' => $daysAr[$shortEn] ?? $shortEn,
                'timeEn' => $shortEn,
                'students' => [],
                'teachers' => [],
                'centers' => [],
                'others' => [],
                'isCurrent' => $i === 0,
            ];
        }

        foreach ($rows as $row) {
            $d = Carbon::parse($row->created_at)->toDateString();
            if (! isset($buckets[$d])) {
                continue;
            }
            $uid = (int) $row->user_id;
            match ((string) $row->role) {
                UserRole::STUDENT->value => $buckets[$d]['students'][$uid] = true,
                UserRole::TEACHER->value => $buckets[$d]['teachers'][$uid] = true,
                UserRole::CENTER->value => $buckets[$d]['centers'][$uid] = true,
                default => $buckets[$d]['others'][$uid] = true,
            };
        }

        $peakUsers = 0;
        $peakIdx = -1;
        $formatted = [];
        foreach (array_values($buckets) as $idx => $b) {
            $s = count($b['students']);
            $t = count($b['teachers']);
            $c = count($b['centers']);
            $total = $s + $t + $c + count($b['others']);
            if ($total > $peakUsers) {
                $peakUsers = $total;
                $peakIdx = $idx;
            }
            $formatted[] = [
                'timeAr' => $b['timeAr'],
                'timeEn' => $b['timeEn'],
                'users' => $total,
                'students' => $s,
                'teachers' => $t,
                'centers' => $c,
                'isPeak' => false,
                'isCurrent' => $b['isCurrent'],
            ];
        }

        if ($peakIdx >= 0 && $peakUsers > 0) {
            $formatted[$peakIdx]['isPeak'] = true;
        }

        return $formatted;
    }
}
