<?php

namespace App\Services\V1\Student;

use App\Enums\StageType;
use App\Models\StudentDailyActivity;
use App\Models\StudentLessonProgress;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\BaseService;
use App\Services\V1\LeagueRulesService;
use Carbon\Carbon;
use DateTimeZone;

/**
 * Tracks server-validated daily active platform time and strict consecutive calendar-day streaks.
 *
 * Rules:
 * - Opening the platform on consecutive calendar days keeps the streak incrementing (+1 per new day).
 * - Multiple opens on the same calendar day keep the streak unchanged while continuing to accumulate active time.
 * - Missing a full calendar day breaks the streak immediately:
 *   - While absent after missing yesterday, effective streak is strictly 0 everywhere (Student, Parent, Teacher, Leagues).
 *   - When the student returns on a new day, the streak starts fresh from Day 1 (streakWasReset = true),
 *     while preserving the all-time longest_streak_days record.
 */
class StudentActivityService extends BaseService
{
    public function __construct(
        protected LeagueRulesService $leagueRulesService
    ) {}

    public const DEFAULT_TIMEZONE = 'Africa/Cairo';

    public const MAX_SECONDS_PER_DAY = 86400;

    public const MAX_HEARTBEAT_DELTA_SECONDS = 90;

    public const VISIT_GAP_SECONDS = 1800;

    /**
     * Authoritative helper for any read path (Profile, Parent, Teacher, Leagues):
     * returns 0 immediately if the student missed yesterday (last_active_date < yesterday in their timezone).
     */
    public static function resolveEffectiveStreak(?StudentProfile $profile, ?Carbon $now = null): int
    {
        if (! $profile) {
            return 0;
        }

        $stored = max(0, (int) ($profile->current_streak_days ?? 0));
        if ($stored === 0) {
            return 0;
        }

        $lastActive = $profile->last_active_date?->toDateString();
        if ($lastActive === null) {
            return 0;
        }

        $tz = ($profile->timezone && in_array($profile->timezone, DateTimeZone::listIdentifiers(), true))
            ? $profile->timezone
            : self::DEFAULT_TIMEZONE;

        $refNow = $now ? $now->copy()->setTimezone($tz) : Carbon::now($tz);
        $yesterdayDate = $refNow->copy()->startOfDay()->subDay()->toDateString();

        return $lastActive >= $yesterdayDate ? $stored : 0;
    }

    /**
     * Batch-expire all stale streaks in the database for students who missed a full calendar day
     * and synchronize their active league participations down to 0.
     */
    public function expireStaleStreaks(): int
    {
        $expiredCount = 0;

        StudentProfile::query()
            ->where('current_streak_days', '>', 0)
            ->chunkById(200, function ($profiles) use (&$expiredCount): void {
                foreach ($profiles as $profile) {
                    if (self::resolveEffectiveStreak($profile) === 0) {
                        $profile->current_streak_days = 0;
                        $profile->save();

                        $this->leagueRulesService->syncStudentStreakToActiveLeagues(
                            (int) $profile->student_id,
                            0
                        );

                        $expiredCount++;
                    }
                }
            });

        return $expiredCount;
    }

    /**
     * Resolve a valid IANA timezone for the student.
     */
    public function resolveTimezone(?StudentProfile $profile = null, ?string $requestedTimezone = null): string
    {
        if ($requestedTimezone && $this->isValidTimezone($requestedTimezone)) {
            return $requestedTimezone;
        }

        if ($profile?->timezone && $this->isValidTimezone($profile->timezone)) {
            return $profile->timezone;
        }

        return self::DEFAULT_TIMEZONE;
    }

    /**
     * Record a student's daily presence and accumulate wall-clock-verified active seconds.
     */
    public function recordHeartbeat(
        User $student,
        int $deltaSeconds = 0,
        ?string $requestedTimezone = null,
        bool $isCheckIn = false
    ): array {
        // Ensure a profile row exists before acquiring the row lock inside the transaction.
        StudentProfile::firstOrCreate(
            ['student_id' => $student->id],
            [
                'stage' => StageType::THANAWYA->value,
                'grade_ar' => 'الصف الثالث الثانوي',
                'track_ar' => 'علمي علوم',
                'daily_study_goal_minutes' => 120,
                'weekly_exams_goal' => 3,
                'target_score_percent' => 90,
                'show_on_leaderboard' => true,
                'display_name_mode' => 'full',
                'timezone' => self::DEFAULT_TIMEZONE,
            ]
        );

        return $this->transaction(function () use ($student, $deltaSeconds, $requestedTimezone, $isCheckIn) {
            /** @var StudentProfile $profile */
            $profile = StudentProfile::where('student_id', $student->id)
                ->lockForUpdate()
                ->firstOrFail();

            $timezone = $this->resolveTimezone($profile, $requestedTimezone);
            if ($profile->timezone !== $timezone) {
                $profile->timezone = $timezone;
            }

            $now = Carbon::now();
            $todayDate = $now->copy()->setTimezone($timezone)->toDateString();

            /** @var StudentDailyActivity|null $activity */
            $activity = StudentDailyActivity::where('student_id', $student->id)
                ->whereDate('activity_date', $todayDate)
                ->first();

            $reportedDelta = max(0, min(self::MAX_HEARTBEAT_DELTA_SECONDS, $deltaSeconds));

            if (! $activity) {
                $initialDelta = min($reportedDelta, 60);
                $activity = StudentDailyActivity::create([
                    'student_id' => $student->id,
                    'activity_date' => $todayDate,
                    'active_seconds' => $initialDelta,
                    'visits_count' => 1,
                    'first_seen_at' => $now,
                    'last_heartbeat_at' => $now,
                ]);
            } else {
                $wallMs = $activity->last_heartbeat_at
                    ? max(0, $activity->last_heartbeat_at->diffInMilliseconds($now, false))
                    : 0;
                $wallElapsed = (int) round($wallMs / 1000);

                $updates = [];

                if ($reportedDelta > 0) {
                    $safeDelta = min($reportedDelta, $wallElapsed, self::MAX_HEARTBEAT_DELTA_SECONDS);
                    if ($safeDelta > 0) {
                        $updates['active_seconds'] = min(
                            self::MAX_SECONDS_PER_DAY,
                            (int) $activity->active_seconds + $safeDelta
                        );
                        $updates['last_heartbeat_at'] = $now;
                    }
                } elseif ($isCheckIn) {
                    // Only bump visits or reset baseline after genuine idle gap so concurrent tabs never lose seconds.
                    if ($wallElapsed >= self::VISIT_GAP_SECONDS) {
                        $updates['visits_count'] = (int) $activity->visits_count + 1;
                        $updates['last_heartbeat_at'] = $now;
                    } elseif ($activity->last_heartbeat_at === null || $wallElapsed > self::MAX_HEARTBEAT_DELTA_SECONDS) {
                        $updates['last_heartbeat_at'] = $now;
                    }
                }

                if (! empty($updates)) {
                    $activity->update($updates);
                }
            }

            $streakState = $this->syncStreakFromActivities($student, $profile, $todayDate);

            return [
                'todayDate' => $todayDate,
                'timezone' => $timezone,
                'activeSecondsToday' => (int) $activity->active_seconds,
                'activeMinutesToday' => intdiv((int) $activity->active_seconds, 60),
                'visitsToday' => (int) $activity->visits_count,
                'streakDays' => $streakState['currentStreakDays'],
                'longestStreakDays' => $streakState['longestStreakDays'],
                'isActiveToday' => $streakState['isActiveToday'],
                'streakWasReset' => $streakState['streakWasReset'],
                'previousStreakDays' => $streakState['previousStreakDays'],
                'lastActiveDate' => $streakState['lastActiveDate'],
                'dailyGoalMinutes' => (int) ($profile->daily_study_goal_minutes ?: 120),
            ];
        });
    }

    /**
     * Read-only snapshot of the student's current streak and today's active time (does NOT record a visit).
     * Automatically evaluates to 0 streak (and clears stale profile/league streak) if the student missed yesterday.
     */
    public function getActivitySnapshot(User $student, ?string $requestedTimezone = null): array
    {
        $profile = StudentProfile::where('student_id', $student->id)->first();
        $timezone = $this->resolveTimezone($profile, $requestedTimezone);
        $todayDate = Carbon::now($timezone)->toDateString();

        $dates = $this->getDistinctActivityDatesAsc($student->id, $todayDate);
        $computed = $this->computeStreakFromDates($dates, $todayDate);

        // Immediately clear stale persisted streak in DB if the student missed yesterday
        if ($profile && (int) $profile->current_streak_days !== $computed['currentStreakDays']) {
            $profile->current_streak_days = $computed['currentStreakDays'];
            $profile->longest_streak_days = max((int) $profile->longest_streak_days, $computed['longestStreakDays']);
            if ($computed['lastActiveDate'] !== null) {
                $profile->last_active_date = $computed['lastActiveDate'];
            }
            $profile->save();

            $this->leagueRulesService->syncStudentStreakToActiveLeagues(
                (int) $student->id,
                $computed['currentStreakDays']
            );
        }

        $todayRecord = StudentDailyActivity::forStudent($student->id)
            ->whereDate('activity_date', $todayDate)
            ->first();

        $activeSeconds = (int) ($todayRecord?->active_seconds ?? 0);
        $longestStreak = max((int) ($profile?->longest_streak_days ?? 0), $computed['longestStreakDays']);

        return [
            'todayDate' => $todayDate,
            'timezone' => $timezone,
            'activeSecondsToday' => $activeSeconds,
            'activeMinutesToday' => intdiv($activeSeconds, 60),
            'visitsToday' => (int) ($todayRecord?->visits_count ?? 0),
            'streakDays' => $computed['currentStreakDays'],
            'longestStreakDays' => $longestStreak,
            'isActiveToday' => $computed['isActiveToday'],
            'streakWasReset' => $computed['streakWasReset'],
            'previousStreakDays' => $computed['previousStreakDays'],
            'lastActiveDate' => $computed['lastActiveDate'],
            'dailyGoalMinutes' => (int) ($profile?->daily_study_goal_minutes ?? 120),
        ];
    }

    /**
     * Build the exact last 7 calendar days of platform activity (oldest -> today) with zero cross-week collisions.
     *
     * @return array<int, array{date: string, dayAr: string, dayEn: string, minutes: int, activeSeconds: int, lessons: int, isToday: bool}>
     */
    public function getLast7DaysActivity(User $student, string $timezone): array
    {
        $todayCarbon = Carbon::now($timezone)->startOfDay();
        $startCarbon = $todayCarbon->copy()->subDays(6);

        $todayStr = $todayCarbon->toDateString();
        $startStr = $startCarbon->toDateString();

        $activitiesByDate = StudentDailyActivity::forStudent($student->id)
            ->whereDate('activity_date', '>=', $startStr)
            ->whereDate('activity_date', '<=', $todayStr)
            ->get(['activity_date', 'active_seconds'])
            ->keyBy(fn (StudentDailyActivity $row) => $row->activity_date->toDateString());

        $utcRangeStart = $startCarbon->copy()->setTimezone('UTC')->subDay();
        $completedProgress = StudentLessonProgress::forStudent($student->id)
            ->where('is_completed', true)
            ->where(function ($q) use ($utcRangeStart) {
                $q->where('completed_at', '>=', $utcRangeStart)
                    ->orWhere(function ($q2) use ($utcRangeStart) {
                        $q2->whereNull('completed_at')
                            ->where('updated_at', '>=', $utcRangeStart);
                    });
            })
            ->get(['completed_at', 'updated_at']);

        $lessonsByDate = [];
        foreach ($completedProgress as $prog) {
            $ts = $prog->completed_at ?: $prog->updated_at;
            if (! $ts) {
                continue;
            }
            $localDay = $ts->copy()->setTimezone($timezone)->toDateString();
            $lessonsByDate[$localDay] = ($lessonsByDate[$localDay] ?? 0) + 1;
        }

        $dayNamesAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $dayNamesEn = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        $weekly = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $cursor = $startCarbon->copy()->addDays($offset);
            $dateStr = $cursor->toDateString();
            $dow = $cursor->dayOfWeek;

            /** @var StudentDailyActivity|null $row */
            $row = $activitiesByDate->get($dateStr);
            $activeSec = (int) ($row?->active_seconds ?? 0);

            $weekly[] = [
                'date' => $dateStr,
                'dayAr' => $dayNamesAr[$dow],
                'dayEn' => $dayNamesEn[$dow],
                'minutes' => intdiv($activeSec, 60),
                'activeSeconds' => $activeSec,
                'lessons' => (int) ($lessonsByDate[$dateStr] ?? 0),
                'isToday' => $dateStr === $todayStr,
            ];
        }

        return $weekly;
    }

    /**
     * Recompute streak authoritatively from student_daily_activities and sync profile + leagues.
     *
     * @return array{currentStreakDays: int, longestStreakDays: int, isActiveToday: bool, lastActiveDate: ?string, streakWasReset: bool, previousStreakDays: int}
     */
    protected function syncStreakFromActivities(User $student, StudentProfile $profile, string $todayDate): array
    {
        $dates = $this->getDistinctActivityDatesAsc($student->id, $todayDate);
        $computed = $this->computeStreakFromDates($dates, $todayDate);

        $longestStreak = max((int) $profile->longest_streak_days, $computed['longestStreakDays']);
        $previousStreak = (int) $profile->current_streak_days;

        $profile->current_streak_days = $computed['currentStreakDays'];
        $profile->longest_streak_days = $longestStreak;
        $profile->last_active_date = $computed['lastActiveDate'];

        if ($profile->isDirty()) {
            $profile->save();
        }

        // Keep league participant streak & +3 daily streak league points synchronized when the streak changes
        if ($previousStreak !== $computed['currentStreakDays']) {
            $this->leagueRulesService->syncStudentStreakToActiveLeagues(
                (int) $student->id,
                (int) $computed['currentStreakDays']
            );
        }

        return [
            'currentStreakDays' => $computed['currentStreakDays'],
            'longestStreakDays' => $longestStreak,
            'isActiveToday' => $computed['isActiveToday'],
            'lastActiveDate' => $computed['lastActiveDate'],
            'streakWasReset' => $computed['streakWasReset'],
            'previousStreakDays' => $computed['previousStreakDays'],
        ];
    }

    /**
     * Fetch sorted unique YYYY-MM-DD activity dates for a student up to $todayDate.
     *
     * @return array<int, string>
     */
    protected function getDistinctActivityDatesAsc(int $studentId, string $todayDate): array
    {
        $dates = StudentDailyActivity::forStudent($studentId)
            ->whereDate('activity_date', '<=', $todayDate)
            ->orderBy('activity_date')
            ->pluck('activity_date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : substr((string) $d, 0, 10))
            ->filter(fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1)
            ->unique()
            ->values()
            ->all();

        sort($dates);

        return $dates;
    }

    /**
     * Pure calendar-day streak calculator.
     *
     * @param  array<int, string>  $sortedDatesAsc
     * @return array{currentStreakDays: int, longestStreakDays: int, isActiveToday: bool, lastActiveDate: ?string, streakWasReset: bool, previousStreakDays: int}
     */
    public function computeStreakFromDates(array $sortedDatesAsc, string $todayDate): array
    {
        if (empty($sortedDatesAsc)) {
            return [
                'currentStreakDays' => 0,
                'longestStreakDays' => 0,
                'isActiveToday' => false,
                'lastActiveDate' => null,
                'streakWasReset' => false,
                'previousStreakDays' => 0,
            ];
        }

        // 1. Compute all-time longest consecutive run across history (UTC calendar math avoids DST edge cases)
        $longest = 1;
        $run = 1;
        $prevDate = Carbon::createFromFormat('Y-m-d', $sortedDatesAsc[0], 'UTC')->startOfDay();

        for ($i = 1, $count = count($sortedDatesAsc); $i < $count; $i++) {
            $currDate = Carbon::createFromFormat('Y-m-d', $sortedDatesAsc[$i], 'UTC')->startOfDay();
            $diffDays = (int) $prevDate->diffInDays($currDate);

            if ($diffDays === 1) {
                $run++;
                if ($run > $longest) {
                    $longest = $run;
                }
            } elseif ($diffDays > 1) {
                $run = 1;
            }

            $prevDate = $currDate;
        }

        // 2. Compute current active consecutive streak relative to today/yesterday
        $dateSet = array_flip($sortedDatesAsc);
        $todayCarbon = Carbon::createFromFormat('Y-m-d', $todayDate, 'UTC')->startOfDay();
        $yesterdayDate = $todayCarbon->copy()->subDay()->toDateString();

        $isActiveToday = isset($dateSet[$todayDate]);
        $isActiveYesterday = isset($dateSet[$yesterdayDate]);
        $current = 0;

        if ($isActiveToday) {
            $cursor = $todayCarbon->copy();
            while (isset($dateSet[$cursor->toDateString()])) {
                $current++;
                $cursor->subDay();
            }
        } elseif ($isActiveYesterday) {
            $cursor = $todayCarbon->copy()->subDay();
            while (isset($dateSet[$cursor->toDateString()])) {
                $current++;
                $cursor->subDay();
            }
        }
        // Otherwise neither today nor yesterday was active -> current streak is strictly 0 (broken).

        // 3. Determine whether a prior streak was broken because the student missed yesterday (or more days)
        $priorDatesBeforeYesterday = array_values(array_filter(
            $sortedDatesAsc,
            fn (string $d) => $d < $yesterdayDate
        ));

        $streakWasReset = false;
        $previousStreakDays = $current;

        if (! $isActiveYesterday && ! empty($priorDatesBeforeYesterday)) {
            $streakWasReset = true;
            $priorLastStr = $priorDatesBeforeYesterday[count($priorDatesBeforeYesterday) - 1];
            $priorCursor = Carbon::createFromFormat('Y-m-d', $priorLastStr, 'UTC')->startOfDay();
            $priorRun = 0;
            while (isset($dateSet[$priorCursor->toDateString()])) {
                $priorRun++;
                $priorCursor->subDay();
            }
            $previousStreakDays = $priorRun;
        }

        $lastActiveDate = $sortedDatesAsc[count($sortedDatesAsc) - 1];

        return [
            'currentStreakDays' => $current,
            'longestStreakDays' => max($longest, $current),
            'isActiveToday' => $isActiveToday,
            'lastActiveDate' => $lastActiveDate,
            'streakWasReset' => $streakWasReset,
            'previousStreakDays' => $previousStreakDays,
        ];
    }

    protected function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }
}
