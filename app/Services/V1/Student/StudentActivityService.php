<?php

namespace App\Services\V1\Student;

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
 * - Missing a full calendar day breaks the streak (0 while absent; starts fresh at 1 upon returning on a new day,
 *   while preserving the all-time longest_streak_days record).
 * - Active platform seconds are bounded by real wall-clock elapsed time since the previous heartbeat
 *   inside a serialized transaction to prevent multi-tab double-counting or client tampering.
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
                'stage' => 'high',
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
            $yesterdayDate = Carbon::parse($todayDate, $timezone)->subDay()->toDateString();

            $previousStreak = (int) $profile->current_streak_days;
            $previousLastActiveDate = $profile->last_active_date?->toDateString();

            /** @var StudentDailyActivity|null $activity */
            $activity = StudentDailyActivity::where('student_id', $student->id)
                ->whereDate('activity_date', $todayDate)
                ->first();

            $reportedDelta = max(0, min(self::MAX_HEARTBEAT_DELTA_SECONDS, $deltaSeconds));
            $isFirstOpenToday = $activity === null;

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

            $streakWasReset = $isFirstOpenToday
                && $previousStreak > 0
                && $previousLastActiveDate !== null
                && $previousLastActiveDate < $yesterdayDate;

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
                'streakWasReset' => $streakWasReset,
                'previousStreakDays' => $streakWasReset ? $previousStreak : $streakState['currentStreakDays'],
                'lastActiveDate' => $streakState['lastActiveDate'],
                'dailyGoalMinutes' => (int) ($profile->daily_study_goal_minutes ?: 120),
            ];
        });
    }

    /**
     * Read-only snapshot of the student's current streak and today's active time (does NOT record a visit).
     * Automatically evaluates to 0 streak if the student missed yesterday and hasn't opened today.
     */
    public function getActivitySnapshot(User $student, ?string $requestedTimezone = null): array
    {
        $profile = StudentProfile::where('student_id', $student->id)->first();
        $timezone = $this->resolveTimezone($profile, $requestedTimezone);
        $todayDate = Carbon::now($timezone)->toDateString();

        $dates = $this->getDistinctActivityDatesAsc($student->id, $todayDate);
        $computed = $this->computeStreakFromDates($dates, $todayDate);

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
            'streakWasReset' => false,
            'previousStreakDays' => $computed['currentStreakDays'],
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
     * @return array{currentStreakDays: int, longestStreakDays: int, isActiveToday: bool, lastActiveDate: ?string}
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
     * @return array{currentStreakDays: int, longestStreakDays: int, isActiveToday: bool, lastActiveDate: ?string}
     */
    public function computeStreakFromDates(array $sortedDatesAsc, string $todayDate): array
    {
        if (empty($sortedDatesAsc)) {
            return [
                'currentStreakDays' => 0,
                'longestStreakDays' => 0,
                'isActiveToday' => false,
                'lastActiveDate' => null,
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
        $current = 0;

        if ($isActiveToday) {
            $cursor = $todayCarbon->copy();
            while (isset($dateSet[$cursor->toDateString()])) {
                $current++;
                $cursor->subDay();
            }
        } elseif (isset($dateSet[$yesterdayDate])) {
            $cursor = $todayCarbon->copy()->subDay();
            while (isset($dateSet[$cursor->toDateString()])) {
                $current++;
                $cursor->subDay();
            }
        }
        // Otherwise neither today nor yesterday was active -> current streak is strictly 0 (broken).

        $lastActiveDate = $sortedDatesAsc[count($sortedDatesAsc) - 1];

        return [
            'currentStreakDays' => $current,
            'longestStreakDays' => max($longest, $current),
            'isActiveToday' => $isActiveToday,
            'lastActiveDate' => $lastActiveDate,
        ];
    }

    protected function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }
}
