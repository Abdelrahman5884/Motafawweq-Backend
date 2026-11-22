<?php

namespace App\Services\V1\Student;

use App\Enums\CertificateStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Enums\LessonStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseCertificate;
use App\Models\CourseLeague;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\HomeworkSubmission;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
use App\Models\RepublicLeague;
use App\Models\StudentDailyActivity;
use App\Models\StudentLessonProgress;
use App\Models\StudentProfile;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use App\Services\V1\LeagueRulesService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentGamificationService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService,
        protected StudentActivityService $activityService,
        protected LeagueRulesService $leagueRulesService
    ) {}

    /**
     * Single optimized payload for the /student/gamification workspace.
     * Avoids redundant profile/activity/league queries and ensures completed weekly
     * challenge XP is reflected immediately in totalXp, level, badges, and recentLedger.
     */
    public function getGamificationWorkspace(User $student, ?string $requestedTimezone = null): array
    {
        $profile = StudentProfile::where('student_id', $student->id)->first();
        $timezone = $this->activityService->resolveTimezone($profile, $requestedTimezone);
        $activity = $this->activityService->getActivitySnapshot($student, $timezone);

        // Evaluate weekly challenges first so newly completed challenge XP is immediately awarded & reflected in summary
        $challenges = $this->getWeeklyChallenges($student, $timezone, $profile);
        $leagues = $this->listLeagues($student);
        $pastLeagues = $this->getPastLeagues($student, true);
        $heatmap = $this->getActivityHeatmap($student, $timezone, $profile, $activity);
        $summary = $this->getGamificationSummary($student, $timezone, $activity);

        return [
            'summary' => $summary,
            'leagues' => $leagues,
            'pastLeagues' => $pastLeagues,
            'heatmap' => $heatmap,
            'challenges' => $challenges,
        ];
    }

    /**
     * Retrieve student's total server-verified XP, level, progression, streak, real badges,
     * and recent immutable XP ledger entries.
     */
    public function getGamificationSummary(
        User $student,
        ?string $requestedTimezone = null,
        ?array $activitySnapshot = null
    ): array {
        $activity = $activitySnapshot ?? $this->activityService->getActivitySnapshot($student, $requestedTimezone);
        $totalXp = (int) StudentXpLedger::forStudent($student->id)->sum('points');
        $level = max(1, (int) floor($totalXp / 100) + 1);
        $levelMinXp = ($level - 1) * 100;
        $nextLevelXp = $level * 100;
        $xpInCurrentLevel = max(0, $totalXp - $levelMinXp);
        $xpRemainingForNextLevel = max(0, $nextLevelXp - $totalXp);
        $levelProgressPercent = (int) min(100, max(0, round(($xpInCurrentLevel / 100) * 100)));
        [$levelTitleAr, $levelTitleEn] = $this->resolveLevelTitles($level);

        $badges = $this->computeAchievementBadges($student, $activity);
        $unlockedBadgesCount = count(array_filter($badges, fn (array $b) => $b['unlocked']));

        $recentLedger = StudentXpLedger::forStudent($student->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (StudentXpLedger $entry) => [
                'id' => $entry->uuid,
                'sourceType' => $entry->source_type,
                'points' => (int) $entry->points,
                'reasonAr' => $entry->reason_ar,
                'createdAt' => $entry->created_at?->toIso8601String(),
            ])->values()->all();

        return [
            'totalXp' => $totalXp,
            'level' => $level,
            'levelTitleAr' => $levelTitleAr,
            'levelTitleEn' => $levelTitleEn,
            'levelMinXp' => $levelMinXp,
            'nextLevelXp' => $nextLevelXp,
            'xpInCurrentLevel' => $xpInCurrentLevel,
            'xpRemainingForNextLevel' => $xpRemainingForNextLevel,
            'levelProgressPercent' => $levelProgressPercent,
            'streakDays' => $activity['streakDays'],
            'longestStreakDays' => $activity['longestStreakDays'],
            'todayActiveSeconds' => $activity['activeSecondsToday'],
            'todayActiveMinutes' => $activity['activeMinutesToday'],
            'timezone' => $activity['timezone'],
            'badges' => $badges,
            'unlockedBadgesCount' => $unlockedBadgesCount,
            'totalBadgesCount' => count($badges),
            'recentLedger' => $recentLedger,
        ];
    }

    /**
     * Resolve progressive bilingual academic rank title for a given XP level.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveLevelTitles(int $level): array
    {
        return match (true) {
            $level >= 12 => ['أسطورة التفوق', 'Master Scholar'],
            $level >= 8 => ['نجم الأكاديمية', 'Academy Star'],
            $level >= 5 => ['منافس متفوق', 'Top Achiever'],
            $level >= 3 => ['طالب مجتهد', 'Dedicated Scholar'],
            default => ['مبتدئ طموح', 'Rising Learner'],
        };
    }

    /**
     * Compute real server-verified achievement badges strictly from database activity.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function computeAchievementBadges(User $student, array $activity): array
    {
        $lessonsCompleted = StudentLessonProgress::forStudent($student->id)
            ->where('is_completed', true)
            ->count();

        $firstLessonDate = $lessonsCompleted > 0
            ? StudentLessonProgress::forStudent($student->id)
                ->where('is_completed', true)
                ->orderByRaw('COALESCE(completed_at, updated_at) ASC')
                ->value(DB::raw('COALESCE(completed_at, updated_at)'))
            : null;

        $tenthLessonDate = $lessonsCompleted >= 10
            ? StudentLessonProgress::forStudent($student->id)
                ->where('is_completed', true)
                ->orderByRaw('COALESCE(completed_at, updated_at) ASC')
                ->skip(9)
                ->take(1)
                ->value(DB::raw('COALESCE(completed_at, updated_at)'))
            : null;

        $examsSubmitted = ExamSubmission::forStudent($student->id)
            ->whereNotNull('submitted_at')
            ->count();

        $firstExamDate = $examsSubmitted > 0
            ? ExamSubmission::forStudent($student->id)
                ->whereNotNull('submitted_at')
                ->orderBy('submitted_at')
                ->value('submitted_at')
            : null;

        $perfectExamsQuery = ExamSubmission::forStudent($student->id)
            ->whereNotNull('submitted_at')
            ->where('status', ExamSubmissionStatus::GRADED)
            ->where(function ($q) {
                $q->where('is_full_mark', true)->orWhere('percentage', '>=', 100);
            });

        $perfectExamsCount = (clone $perfectExamsQuery)->count();
        $firstPerfectDate = $perfectExamsCount > 0
            ? (clone $perfectExamsQuery)->orderBy('submitted_at')->value('submitted_at')
            : null;

        $longestStreak = (int) ($activity['longestStreakDays'] ?? 0);

        $podiumParticipant = LeagueParticipant::where('student_id', $student->id)
            ->where('rank', '>', 0)
            ->where('rank', '<=', 3)
            ->where('xp_points', '>', 0)
            ->orderBy('rank')
            ->first(['rank', 'updated_at']);

        $formatDate = static function (mixed $raw): ?string {
            if (! $raw) {
                return null;
            }

            try {
                return Carbon::parse((string) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        };

        $defs = [
            [
                'id' => 'first-lesson',
                'icon' => 'BookOpen',
                'titleAr' => 'أول خطوة نحو التفوق',
                'titleEn' => 'First Step',
                'descAr' => 'إتمام أول حصة دراسية بالكامل على المنصة',
                'descEn' => 'Complete your first lesson on the platform',
                'current' => min(1, $lessonsCompleted),
                'target' => 1,
                'unlockedAt' => $formatDate($firstLessonDate),
            ],
            [
                'id' => 'lesson-explorer',
                'icon' => 'Award',
                'titleAr' => 'مثابر الحصص',
                'titleEn' => 'Lesson Explorer',
                'descAr' => 'إتمام 10 حصص دراسية بنجاح',
                'descEn' => 'Complete 10 lessons successfully',
                'current' => min(10, $lessonsCompleted),
                'target' => 10,
                'unlockedAt' => $formatDate($tenthLessonDate),
            ],
            [
                'id' => 'first-exam',
                'icon' => 'Target',
                'titleAr' => 'بطل الاختبارات',
                'titleEn' => 'Exam Challenger',
                'descAr' => 'تسليم أول اختبار إلكتروني على المنصة',
                'descEn' => 'Submit your first online exam',
                'current' => min(1, $examsSubmitted),
                'target' => 1,
                'unlockedAt' => $formatDate($firstExamDate),
            ],
            [
                'id' => 'perfect-score',
                'icon' => 'Shield',
                'titleAr' => 'التقفيل الكامل 100%',
                'titleEn' => 'Perfect 100%',
                'descAr' => 'الحصول على الدرجة النهائية في اختبار مُصحّح',
                'descEn' => 'Achieve a 100% full mark on a graded exam',
                'current' => min(1, $perfectExamsCount),
                'target' => 1,
                'unlockedAt' => $formatDate($firstPerfectDate),
            ],
            [
                'id' => 'streak-7',
                'icon' => 'Flame',
                'titleAr' => 'شعلة الالتزام (7 أيام)',
                'titleEn' => '7-Day Streak',
                'descAr' => 'المذاكرة لمدة 7 أيام متتالية دون انقطاع',
                'descEn' => 'Maintain a 7-day consecutive study streak',
                'current' => min(7, $longestStreak),
                'target' => 7,
                'unlockedAt' => $longestStreak >= 7 ? ($activity['lastActiveDate'] ?? null) : null,
            ],
            [
                'id' => 'league-podium',
                'icon' => 'Trophy',
                'titleAr' => 'منصة التتويج',
                'titleEn' => 'League Podium',
                'descAr' => 'التواجد ضمن المراكز الثلاثة الأولى في دوري كورس بنقاط محققة',
                'descEn' => 'Reach Top 3 in a course league with earned points',
                'current' => $podiumParticipant ? 1 : 0,
                'target' => 1,
                'unlockedAt' => $podiumParticipant ? $formatDate($podiumParticipant->updated_at) : null,
            ],
        ];

        return array_map(function (array $b): array {
            $unlocked = $b['current'] >= $b['target'];

            return [
                ...$b,
                'unlocked' => $unlocked,
                'progressPercent' => (int) round(($b['current'] / max(1, $b['target'])) * 100),
                'date' => $unlocked ? $b['unlockedAt'] : null,
            ];
        }, $defs);
    }

    /**
     * List the live General Republic League plus all accessible Teacher Course Leagues for the student.
     */
    public function listLeagues(User $student): array
    {
        $result = [];

        // 1. Live General Republic League (all active students ranked by official platform XP rules)
        $generalSummary = $this->buildGeneralLeagueSummary($student);
        $result[] = $generalSummary;

        // 2. Accessible Teacher Course Leagues
        $entitlements = $this->accessService->getEntitlements($student);
        if (empty($entitlements['teacher_ids']) && empty($entitlements['course_ids'])) {
            return $result;
        }

        $leagues = CourseLeague::query()
            ->where(function ($q) use ($entitlements) {
                if (! empty($entitlements['teacher_ids'])) {
                    $q->whereIn('teacher_id', $entitlements['teacher_ids']);
                }
                if (! empty($entitlements['course_ids'])) {
                    $q->orWhereIn('course_id', $entitlements['course_ids']);
                }
            })
            ->latest()
            ->get();

        if ($leagues->isEmpty()) {
            return $result;
        }

        foreach ($leagues as $league) {
            $this->leagueRulesService->syncCourseLeague($league);
        }

        $leagues->loadCount('participants');

        $myParticipations = LeagueParticipant::where('student_id', $student->id)
            ->whereIn('course_league_id', $leagues->pluck('id'))
            ->get()
            ->keyBy('course_league_id');

        $teacherNames = User::whereIn('id', $leagues->pluck('teacher_id')->filter()->unique())
            ->pluck('name', 'id');

        foreach ($leagues as $l) {
            /** @var LeagueParticipant|null $me */
            $me = $myParticipations->get($l->id);
            $myXp = (int) ($me?->xp_points ?? 0);
            $tierEnum = LeagueTier::resolveFromXp($myXp);
            $durationEnum = $l->duration_type instanceof LeagueDuration
                ? $l->duration_type
                : (LeagueDuration::tryFrom((string) $l->duration_type) ?? LeagueDuration::MONTHLY);

            $result[] = [
                'id' => $l->uuid,
                'category' => 'teachers',
                'isRepublicLeague' => false,
                'hasStarted' => true,
                'titleAr' => $l->title_ar,
                'courseTitleAr' => $l->course_name_ar,
                'groupNameAr' => $l->group_name_ar,
                'teacherName' => $teacherNames[$l->teacher_id] ?? null,
                'durationType' => $durationEnum->value,
                'durationLabelAr' => $durationEnum->labelAr(),
                'tier' => $me ? $tierEnum->value : null,
                'tierLabelAr' => $me ? $tierEnum->labelAr() : null,
                'participantsCount' => (int) $l->participants_count,
                'isActive' => (bool) $l->is_active,
                'startsAt' => $l->starts_at?->format('Y-m-d'),
                'endsAt' => $l->ends_at?->format('Y-m-d'),
                'daysRemaining' => $l->is_active ? $l->roundDaysRemaining() : 0,
                'myRank' => $me && $me->rank > 0 ? (int) $me->rank : null,
                'myXp' => $myXp,
                'myStreak' => (int) ($me?->streak_days ?? 0),
                'myBadgeAr' => $me?->badge_title_ar,
            ];
        }

        return $result;
    }

    /**
     * 52-week Sunday-aligned daily activity heatmap computed from daily platform presence,
     * lesson completions, exam submissions, homework submissions, and XP ledger.
     */
    public function getActivityHeatmap(
        User $student,
        ?string $requestedTimezone = null,
        ?StudentProfile $profile = null,
        ?array $activitySnapshot = null
    ): array {
        $profile ??= StudentProfile::where('student_id', $student->id)->first();
        $timezone = $this->activityService->resolveTimezone($profile, $requestedTimezone);
        $activity = $activitySnapshot ?? $this->activityService->getActivitySnapshot($student, $timezone);

        $todayLocal = Carbon::now($timezone);
        $todayKey = $todayLocal->toDateString();
        // Use UTC calendar math for 52-week day iteration so DST transitions (e.g. Africa/Cairo midnight DST) never drift hours
        $todayUtcDate = Carbon::createFromFormat('Y-m-d', $todayKey, 'UTC')->startOfDay();
        $currentWeekStart = $todayUtcDate->copy()->startOfWeek(Carbon::SUNDAY)->startOfDay();
        $gridStart = $currentWeekStart->copy()->subWeeks(51); // Exact 52 columns (Sunday -> Saturday)
        $utcRangeStart = Carbon::createFromFormat('Y-m-d', $gridStart->toDateString(), $timezone)
            ->startOfDay()
            ->setTimezone('UTC')
            ->subDay();

        $dailyActivities = StudentDailyActivity::forStudent($student->id)
            ->whereDate('activity_date', '>=', $gridStart->toDateString())
            ->whereDate('activity_date', '<=', $todayKey)
            ->get(['activity_date', 'active_seconds'])
            ->keyBy(fn (StudentDailyActivity $row) => $row->activity_date->toDateString());

        $xpByDay = StudentXpLedger::forStudent($student->id)
            ->where('created_at', '>=', $utcRangeStart)
            ->get(['points', 'created_at'])
            ->groupBy(fn (StudentXpLedger $e) => $e->created_at->copy()->setTimezone($timezone)->format('Y-m-d'))
            ->map(fn ($g) => (int) $g->sum('points'));

        $lessonRows = StudentLessonProgress::forStudent($student->id)
            ->where(function ($q) use ($utcRangeStart) {
                $q->where('updated_at', '>=', $utcRangeStart)
                    ->orWhere('completed_at', '>=', $utcRangeStart);
            })
            ->get(['watched_seconds', 'is_completed', 'completed_at', 'updated_at']);

        $lessonMinutesByDay = [];
        $lessonsCompletedByDay = [];
        foreach ($lessonRows as $p) {
            if ($p->updated_at) {
                $uKey = $p->updated_at->copy()->setTimezone($timezone)->format('Y-m-d');
                $lessonMinutesByDay[$uKey] = ($lessonMinutesByDay[$uKey] ?? 0) + (int) round(((int) $p->watched_seconds) / 60);
            }
            if ($p->is_completed) {
                $ts = $p->completed_at ?: $p->updated_at;
                if ($ts) {
                    $cKey = $ts->copy()->setTimezone($timezone)->format('Y-m-d');
                    $lessonsCompletedByDay[$cKey] = ($lessonsCompletedByDay[$cKey] ?? 0) + 1;
                }
            }
        }

        $examsByDay = ExamSubmission::forStudent($student->id)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $utcRangeStart)
            ->get(['submitted_at'])
            ->groupBy(fn (ExamSubmission $s) => $s->submitted_at->copy()->setTimezone($timezone)->format('Y-m-d'))
            ->map->count();

        $homeworksByDay = HomeworkSubmission::forStudent($student->id)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $utcRangeStart)
            ->get(['submitted_at'])
            ->groupBy(fn (HomeworkSubmission $h) => $h->submitted_at->copy()->setTimezone($timezone)->format('Y-m-d'))
            ->map->count();

        $dayNamesAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $dayNamesEn = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $monthNamesAr = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        $monthNamesEn = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $weeks = [];
        $monthMarkers = [];
        $lastMarkerMonth = null;
        $cursor = $gridStart->copy();

        $activeDays = 0;
        $totalMinutes = 0;
        $totalQuizzes = 0;
        $totalHomeworks = 0;
        $totalLessons = 0;
        $totalXp = 0;

        for ($w = 0; $w < 52; $w++) {
            $week = [];
            $weekMonth = (int) $cursor->month;
            $weekYear = (int) $cursor->year;

            if ($weekMonth !== $lastMarkerMonth) {
                // Avoid crowding a tiny 1-column stub at week 0 when week 1 already transitions months
                if (! empty($monthMarkers) && ($w - $monthMarkers[count($monthMarkers) - 1]['weekIndex']) < 3) {
                    array_pop($monthMarkers);
                }
                $monthMarkers[] = [
                    'weekIndex' => $w,
                    'monthAr' => $monthNamesAr[$weekMonth],
                    'monthEn' => $monthNamesEn[$weekMonth],
                    'year' => $weekYear,
                ];
                $lastMarkerMonth = $weekMonth;
            }

            for ($d = 0; $d < 7; $d++) {
                $key = $cursor->format('Y-m-d');
                $isFuture = $key > $todayKey;
                $isToday = $key === $todayKey;

                if ($isFuture) {
                    $week[] = [
                        'date' => $key,
                        'dayName' => $dayNamesAr[$d],
                        'dayNameAr' => $dayNamesAr[$d],
                        'dayNameEn' => $dayNamesEn[$d],
                        'minutes' => 0,
                        'quizzes' => 0,
                        'homeworks' => 0,
                        'lessons' => 0,
                        'xp' => 0,
                        'hasVisit' => false,
                        'level' => 0,
                        'isToday' => false,
                        'isFuture' => true,
                    ];
                } else {
                    /** @var StudentDailyActivity|null $dailyRow */
                    $dailyRow = $dailyActivities->get($key);
                    $platformMinutes = $dailyRow ? intdiv((int) $dailyRow->active_seconds, 60) : 0;
                    $lessonMinutes = min(300, (int) ($lessonMinutesByDay[$key] ?? 0));
                    $minutes = max($platformMinutes, $lessonMinutes);
                    $quizzes = (int) ($examsByDay[$key] ?? 0);
                    $homeworks = (int) ($homeworksByDay[$key] ?? 0);
                    $lessons = (int) ($lessonsCompletedByDay[$key] ?? 0);
                    $xp = (int) ($xpByDay[$key] ?? 0);
                    $hasVisit = $dailyRow !== null;

                    $score = $minutes
                        + ($quizzes * 15)
                        + ($homeworks * 10)
                        + ($lessons * 10)
                        + (int) round($xp / 3)
                        + ($hasVisit ? 1 : 0);

                    $level = $score <= 0
                        ? 0
                        : ($score < 15 ? 1 : ($score < 40 ? 2 : ($score < 80 ? 3 : 4)));

                    if ($level > 0) {
                        $activeDays++;
                    }
                    $totalMinutes += $minutes;
                    $totalQuizzes += $quizzes;
                    $totalHomeworks += $homeworks;
                    $totalLessons += $lessons;
                    $totalXp += $xp;

                    $week[] = [
                        'date' => $key,
                        'dayName' => $dayNamesAr[$d],
                        'dayNameAr' => $dayNamesAr[$d],
                        'dayNameEn' => $dayNamesEn[$d],
                        'minutes' => $minutes,
                        'quizzes' => $quizzes,
                        'homeworks' => $homeworks,
                        'lessons' => $lessons,
                        'xp' => $xp,
                        'hasVisit' => $hasVisit,
                        'level' => $level,
                        'isToday' => $isToday,
                        'isFuture' => false,
                    ];
                }

                $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return [
            'timezone' => $timezone,
            'weeks' => $weeks,
            'monthMarkers' => $monthMarkers,
            'activeDays' => $activeDays,
            'totalMinutes' => $totalMinutes,
            'totalQuizzes' => $totalQuizzes,
            'totalHomeworks' => $totalHomeworks,
            'totalLessons' => $totalLessons,
            'totalXp' => $totalXp,
            'currentStreakDays' => $activity['streakDays'],
            'longestStreakDays' => $activity['longestStreakDays'],
        ];
    }

    /**
     * Weekly challenges with real progress computed from this week's activity (Saturday -> Friday).
     * Automatically awards immutable XP once per completed challenge per week (never pollutes league points).
     */
    public function getWeeklyChallenges(
        User $student,
        ?string $requestedTimezone = null,
        ?StudentProfile $profile = null
    ): array {
        $profile ??= StudentProfile::where('student_id', $student->id)->first();
        $timezone = $this->activityService->resolveTimezone($profile, $requestedTimezone);
        $nowLocal = Carbon::now($timezone);
        $weekStartLocal = $nowLocal->copy()->startOfWeek(Carbon::SATURDAY)->startOfDay();
        $weekEndLocal = $weekStartLocal->copy()->addDays(7);
        $weekStartUtc = $weekStartLocal->copy()->setTimezone('UTC');

        $lessonsDone = StudentLessonProgress::forStudent($student->id)
            ->where('is_completed', true)
            ->where(function ($q) use ($weekStartUtc) {
                $q->where('completed_at', '>=', $weekStartUtc)
                    ->orWhere(function ($q2) use ($weekStartUtc) {
                        $q2->whereNull('completed_at')
                            ->where('updated_at', '>=', $weekStartUtc);
                    });
            })
            ->count();

        $examsDone = ExamSubmission::forStudent($student->id)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $weekStartUtc)
            ->count();

        $perfectExams = ExamSubmission::forStudent($student->id)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $weekStartUtc)
            ->where('status', ExamSubmissionStatus::GRADED)
            ->where(function ($q) {
                $q->where('is_full_mark', true)->orWhere('percentage', '>=', 100);
            })
            ->count();

        $homeworksDone = HomeworkSubmission::forStudent($student->id)
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $weekStartUtc)
            ->count();

        $weeklyExamsGoal = max(1, (int) ($profile?->weekly_exams_goal ?? 3));

        $defs = [
            [
                'slot' => 1,
                'id' => 'wc-lessons',
                'titleAr' => 'أكمل 5 حصص هذا الأسبوع',
                'titleEn' => 'Complete 5 lessons this week',
                'descAr' => 'شاهد وأتمم 5 حصص دراسية من مقرراتك خلال الأسبوع الحالي',
                'descEn' => 'Watch and complete 5 lessons across your courses this week',
                'target' => 5,
                'current' => $lessonsDone,
                'xp' => 50,
                'type' => 'lessons',
                'link' => '/student/courses',
            ],
            [
                'slot' => 2,
                'id' => 'wc-exams',
                'titleAr' => "حل {$weeklyExamsGoal} اختبارات هذا الأسبوع",
                'titleEn' => "Solve {$weeklyExamsGoal} exams this week",
                'descAr' => 'التزم بحل اختباراتك الدورية لرفع مستواك وتثبيت المعلومات',
                'descEn' => 'Stay sharp by completing your scheduled exams this week',
                'target' => $weeklyExamsGoal,
                'current' => $examsDone,
                'xp' => 40,
                'type' => 'exams',
                'link' => '/student/exam',
            ],
            [
                'slot' => 3,
                'id' => 'wc-perfect',
                'titleAr' => 'احصل على الدرجة النهائية 100% في اختبار',
                'titleEn' => 'Score 100% in a graded exam',
                'descAr' => 'أجب بدقة وحقق التقفيل الكامل في أي اختبار مُصحّح هذا الأسبوع',
                'descEn' => 'Achieve a perfect 100% score on any graded exam this week',
                'target' => 1,
                'current' => $perfectExams,
                'xp' => 35,
                'type' => 'perfect',
                'link' => '/student/exam',
            ],
            [
                'slot' => 4,
                'id' => 'wc-homework',
                'titleAr' => 'سلّم واجبين هذا الأسبوع',
                'titleEn' => 'Submit 2 homeworks this week',
                'descAr' => 'ارفع حلول واجباتك الدراسية في موعدها لتقوية مستواك العملي',
                'descEn' => 'Submit 2 course assignments on time this week',
                'target' => 2,
                'current' => $homeworksDone,
                'xp' => 30,
                'type' => 'homework',
                'link' => '/student/homework',
            ],
        ];

        $hoursLeft = $nowLocal->diffInHours($weekEndLocal, false);
        $daysLeft = $hoursLeft > 0 ? (int) ceil($hoursLeft / 24) : 0;
        $weekKeyPrefix = $weekStartLocal->format('Ymd');

        return array_map(function (array $c) use ($student, $daysLeft, $weekKeyPrefix, $weekStartLocal, $weekEndLocal): array {
            $current = min($c['target'], (int) $c['current']);
            $isCompleted = $current >= $c['target'];
            $xpReward = (int) $c['xp'];

            if ($isCompleted) {
                $sourceId = (int) ($weekKeyPrefix.$c['slot']);
                StudentXpLedger::awardOnce(
                    (int) $student->id,
                    'weekly_challenge',
                    $sourceId,
                    $xpReward,
                    "مكافأة تحدي الأسبوع: {$c['titleAr']}"
                );
            }

            return [
                'id' => $c['id'],
                'titleAr' => $c['titleAr'],
                'titleEn' => $c['titleEn'],
                'descAr' => $c['descAr'],
                'descEn' => $c['descEn'],
                'target' => $c['target'],
                'current' => $current,
                'progressPercent' => (int) round(($current / max(1, $c['target'])) * 100),
                'isCompleted' => $isCompleted,
                'completed' => $isCompleted,
                'xp' => $xpReward,
                'xpReward' => $xpReward,
                'type' => $c['type'],
                'link' => $c['link'],
                'daysLeft' => $daysLeft,
                'resetDayAr' => 'السبت',
                'resetDayEn' => 'Saturday',
                'weekStart' => $weekStartLocal->toDateString(),
                'weekEndsAt' => $weekEndLocal->copy()->subSecond()->toDateString(),
            ];
        }, $defs);
    }

    /**
     * Archive of finished teacher course leagues & finished Republic League rounds the student competed in.
     */
    public function getPastLeagues(User $student, bool $skipExpireSync = false): array
    {
        if (! $skipExpireSync) {
            // Auto-close expired Republic League round if past ends_at
            RepublicLeague::currentActive();

            // Auto-close any expired active CourseLeague this student is participating in
            $expiredLeagues = CourseLeague::query()
                ->where('is_active', true)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', Carbon::now())
                ->whereHas('participants', fn ($q) => $q->where('student_id', $student->id))
                ->get();

            foreach ($expiredLeagues as $expiredLeague) {
                $this->leagueRulesService->syncCourseLeague($expiredLeague);
            }
        }

        $entries = [];

        // 1. Finished Teacher Course Leagues
        $pastCourseParticipations = LeagueParticipant::where('student_id', $student->id)
            ->whereHas('league', fn ($q) => $q->where('is_active', false))
            ->with(['league' => fn ($q) => $q->select('id', 'uuid', 'teacher_id', 'title_ar', 'course_name_ar', 'ends_at', 'total_competitors')->withCount('participants')])
            ->latest('updated_at')
            ->limit(30)
            ->get();

        $teacherIds = $pastCourseParticipations
            ->pluck('league.teacher_id')
            ->filter()
            ->unique()
            ->values();

        $teacherNames = $teacherIds->isNotEmpty()
            ? User::whereIn('id', $teacherIds)->pluck('name', 'id')
            : collect();

        foreach ($pastCourseParticipations as $p) {
            $league = $p->league;
            if (! $league) {
                continue;
            }
            $xp = (int) $p->xp_points;
            $rank = (int) ($p->rank ?: 0);
            $tierEnum = LeagueTier::resolveFromXp($xp);
            $totalCompetitors = max((int) ($league->total_competitors ?? 0), (int) ($league->participants_count ?? 0));

            $entries[] = [
                'id' => $p->uuid,
                'leagueId' => $league->uuid,
                'isRepublicLeague' => false,
                'titleAr' => $league->title_ar,
                'courseTitleAr' => $league->course_name_ar,
                'seasonAr' => $league->course_name_ar,
                'teacherName' => $teacherNames[$league->teacher_id] ?? null,
                'dateAr' => $league->ends_at?->format('Y-m-d') ?? $p->updated_at?->format('Y-m-d'),
                'endedAt' => ($league->ends_at ?? $p->updated_at)?->toIso8601String(),
                'rank' => $rank,
                'points' => $xp,
                'tier' => $tierEnum->value,
                'tierAr' => $tierEnum->labelAr(),
                'badgeAr' => $p->badge_title_ar ?: ($rank > 0 ? $this->leagueRulesService->resolveRankBadgeAr($rank, false) : 'منافس نشط'),
                'totalCompetitors' => $totalCompetitors,
            ];
        }

        // 2. Finished General Republic League rounds where this student earned points
        $pastRepublicLeagues = RepublicLeague::query()
            ->where('is_active', false)
            ->latest('ends_at')
            ->limit(10)
            ->get();

        foreach ($pastRepublicLeagues as $rep) {
            $ranked = $this->computeGeneralRankedStudents($student, $rep);
            $myRow = collect($ranked)->firstWhere('studentId', (int) $student->id);
            $myXp = (int) ($myRow['xpPoints'] ?? 0);
            if (! $myRow || $myXp <= 0) {
                continue;
            }
            $rank = (int) ($myRow['rank'] ?? 0);
            $tierEnum = LeagueTier::resolveFromXp($myXp);

            $entries[] = [
                'id' => 'rep-'.$rep->uuid,
                'leagueId' => 'general',
                'isRepublicLeague' => true,
                'titleAr' => $rep->title_ar ?: 'دوري الجمهورية العام',
                'courseTitleAr' => 'المجموع التراكمي لجميع المواد',
                'seasonAr' => 'دوري الجمهورية العام',
                'teacherName' => null,
                'dateAr' => $rep->ends_at?->format('Y-m-d') ?? $rep->updated_at?->format('Y-m-d'),
                'endedAt' => ($rep->ends_at ?? $rep->updated_at)?->toIso8601String(),
                'rank' => $rank,
                'points' => $myXp,
                'tier' => $tierEnum->value,
                'tierAr' => $tierEnum->labelAr(),
                'badgeAr' => $rank > 0 ? $this->leagueRulesService->resolveRankBadgeAr($rank, true) : 'منافس نشط',
                'totalCompetitors' => count($ranked),
            ];
        }

        usort($entries, fn (array $a, array $b) => strcmp((string) ($b['endedAt'] ?? ''), (string) ($a['endedAt'] ?? '')));

        return array_slice($entries, 0, 30);
    }

    /**
     * Single optimized payload for /student/certificates workspace.
     */
    public function getCertificatesWorkspace(User $student): array
    {
        $certModels = $this->fetchStudentCertificateModels($student);
        $certificates = $this->mapStudentCertificateModels($student, $certModels);
        $eligibility = $this->getCertificateEligibility($student, $certModels);

        return [
            'certificates' => $certificates,
            'eligibility' => $eligibility,
            'issuedCount' => count($certificates),
        ];
    }

    /**
     * Real eligibility status across all 3 certificate tracks, including live progress and claimable items.
     */
    public function getCertificateEligibility(User $student, mixed $existingCertModels = null): array
    {
        $entitlements = $this->accessService->getEntitlements($student);
        $courseIds = array_values(array_unique(array_map('intval', $entitlements['course_ids'] ?? [])));

        $certModels = $existingCertModels ?? $this->fetchStudentCertificateModels($student);

        $issuedCourseLookup = [];
        $issuedExamLookup = [];
        $issuedLeagueLookup = [];

        foreach ($certModels as $cm) {
            $type = $cm->issuer_type ?: 'teacher';
            $meta = is_array($cm->metadata) ? $cm->metadata : [];
            if ($type === 'teacher' && $cm->course_id) {
                $issuedCourseLookup[(int) $cm->course_id] = true;
            }
            if ($type === 'ministry' && ! empty($meta['exam_uuid'])) {
                $issuedExamLookup[(string) $meta['exam_uuid']] = true;
            }
            if ($type === 'league' && ! empty($meta['league_key'])) {
                $issuedLeagueLookup[(string) $meta['league_key']] = true;
            }
        }

        // ── Track 01: Enrolled Teacher Courses ──
        $completedCourses = 0;
        $totalCoursesWithLessons = 0;
        $coursesProgress = [];
        $claimableCourses = [];

        if (! empty($courseIds)) {
            $publishedLessonsByCourse = Lesson::query()
                ->whereIn('course_id', $courseIds)
                ->where('status', LessonStatus::PUBLISHED)
                ->select(['course_id', DB::raw('COUNT(*) as total_lessons')])
                ->groupBy('course_id')
                ->pluck('total_lessons', 'course_id');

            $completedLessonsByCourse = StudentLessonProgress::forStudent($student->id)
                ->whereIn('course_id', $courseIds)
                ->where('is_completed', true)
                ->whereHas('lesson', fn ($q) => $q->where('status', LessonStatus::PUBLISHED))
                ->select(['course_id', DB::raw('COUNT(DISTINCT lesson_id) as done_lessons')])
                ->groupBy('course_id')
                ->pluck('done_lessons', 'course_id');

            $coursesById = Course::query()
                ->whereIn('id', $courseIds)
                ->with('teacher:id,name')
                ->get(['id', 'uuid', 'teacher_id', 'title_ar', 'title_en', 'grade_ar'])
                ->keyBy('id');

            foreach ($courseIds as $courseId) {
                $total = (int) ($publishedLessonsByCourse[$courseId] ?? 0);
                if ($total === 0 || ! $coursesById->has($courseId)) {
                    continue;
                }
                /** @var Course $c */
                $c = $coursesById->get($courseId);
                $totalCoursesWithLessons++;
                $done = min($total, (int) ($completedLessonsByCourse[$courseId] ?? 0));
                $percent = (int) round(($done / $total) * 100);
                $isIssued = isset($issuedCourseLookup[$courseId]);

                $coursesProgress[] = [
                    'courseId' => $c->uuid,
                    'titleAr' => $c->title_ar,
                    'titleEn' => $c->title_en ?: $c->title_ar,
                    'teacherName' => $c->teacher?->name ?: 'معلم معتمد',
                    'completedLessons' => $done,
                    'totalLessons' => $total,
                    'progressPercent' => $percent,
                    'isIssued' => $isIssued,
                ];

                if ($done >= $total) {
                    $completedCourses++;
                    if (! $isIssued) {
                        $claimableCourses[] = [
                            'track' => 'teacher',
                            'targetId' => $c->uuid,
                            'courseId' => $c->uuid,
                            'titleAr' => $c->title_ar,
                            'titleEn' => $c->title_en ?: $c->title_ar,
                            'subtitleAr' => ($c->teacher?->name ?: 'معلم معتمد')." • أتممت {$done} من {$total} حصة (100%)",
                            'subtitleEn' => ($c->teacher?->name ?: 'Instructor')." • {$done}/{$total} lessons completed (100%)",
                            'teacherName' => $c->teacher?->name ?: 'معلم معتمد',
                            'completedLessons' => $done,
                            'totalLessons' => $total,
                        ];
                    }
                }
            }
        }

        // ── Track 02: Graded Exams with 80%+ Score ──
        $highExamSubs = ExamSubmission::forStudent($student->id)
            ->whereNotNull('submitted_at')
            ->where('status', ExamSubmissionStatus::GRADED)
            ->where('percentage', '>=', 80)
            ->with(['exam:id,uuid,teacher_id,course_id,title_ar,title_en', 'exam.teacher:id,name'])
            ->orderByDesc('percentage')
            ->orderByDesc('submitted_at')
            ->get();

        $highExams = $highExamSubs->pluck('exam_id')->unique()->count();
        $claimableExams = [];
        $seenExamIds = [];

        foreach ($highExamSubs as $sub) {
            if (! $sub->exam || isset($seenExamIds[$sub->exam_id])) {
                continue;
            }
            $seenExamIds[$sub->exam_id] = true;
            $examUuid = (string) $sub->exam->uuid;
            if (isset($issuedExamLookup[$examUuid])) {
                continue;
            }
            $pct = round((float) $sub->percentage, 1);
            $pctLabel = fmod($pct, 1.0) === 0.0 ? sprintf('%d%%', (int) $pct) : sprintf('%.1f%%', $pct);
            $claimableExams[] = [
                'track' => 'ministry',
                'targetId' => $examUuid,
                'titleAr' => $sub->exam->title_ar,
                'titleEn' => $sub->exam->title_en ?: $sub->exam->title_ar,
                'subtitleAr' => "درجة مُصحّحة {$pctLabel} • بإشراف ".($sub->exam->teacher?->name ?: 'معلم معتمد'),
                'subtitleEn' => "Graded score {$pctLabel} • ".($sub->exam->teacher?->name ?: 'Instructor'),
                'scorePercent' => $pct,
            ];
        }

        // ── Track 03: Top 10 League Standings — ONLY AFTER League Ends or Teacher Crowns It ──
        CourseLeague::query()
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now())
            ->whereHas('participants', fn ($q) => $q->where('student_id', $student->id))
            ->update(['is_active' => false]);

        $podiumParts = LeagueParticipant::where('student_id', $student->id)
            ->where('rank', '>', 0)
            ->where('xp_points', '>', 0)
            ->with('league:id,uuid,teacher_id,title_ar,course_name_ar,is_active,ends_at,crowned_at')
            ->orderBy('rank')
            ->get();

        $bestConcludedRank = null;
        $claimableLeagues = [];

        foreach ($podiumParts as $part) {
            if (! $part->league || ! $part->league->isConcluded() || (int) $part->rank > 10) {
                continue;
            }
            $rankNum = (int) $part->rank;
            $bestConcludedRank = $bestConcludedRank === null ? $rankNum : min($bestConcludedRank, $rankNum);

            $leagueKey = 'course:'.$part->league->uuid;
            if (isset($issuedLeagueLookup[$leagueKey])) {
                continue;
            }
            $xpNum = (int) $part->xp_points;
            $claimableLeagues[] = [
                'track' => 'league',
                'targetId' => (string) $part->league->uuid,
                'titleAr' => $part->league->title_ar,
                'titleEn' => $part->league->title_ar,
                'subtitleAr' => "المركز #{$rankNum} • {$xpNum} نقطة في {$part->league->course_name_ar}",
                'subtitleEn' => "Rank #{$rankNum} • {$xpNum} pts in {$part->league->course_name_ar}",
                'rank' => $rankNum,
                'points' => $xpNum,
            ];
        }

        // Auto-expire any past-due Republic League round, then ONLY evaluate concluded rounds
        RepublicLeague::currentActive();
        $concludedRepublicLeagues = RepublicLeague::query()
            ->where('is_active', false)
            ->latest('ends_at')
            ->limit(5)
            ->get();

        foreach ($concludedRepublicLeagues as $rep) {
            $ranked = $this->computeGeneralRankedStudents($student, $rep);
            $myRow = collect($ranked)->firstWhere('studentId', (int) $student->id);
            if ($myRow && (int) ($myRow['xpPoints'] ?? 0) > 0 && (int) ($myRow['rank'] ?? 0) > 0) {
                $repRank = (int) $myRow['rank'];
                $repXp = (int) $myRow['xpPoints'];
                if ($repRank <= 10) {
                    $bestConcludedRank = $bestConcludedRank === null ? $repRank : min($bestConcludedRank, $repRank);
                    $repKey = 'republic:'.$rep->uuid;
                    if (! isset($issuedLeagueLookup[$repKey])) {
                        $claimableLeagues[] = [
                            'track' => 'league',
                            'targetId' => 'republic:'.$rep->uuid,
                            'titleAr' => $rep->title_ar ?: 'دوري الجمهورية العام',
                            'titleEn' => 'General Republic League',
                            'subtitleAr' => "المركز #{$repRank} على الجمهورية • {$repXp} نقطة",
                            'subtitleEn' => "National Rank #{$repRank} • {$repXp} pts",
                            'rank' => $repRank,
                            'points' => $repXp,
                        ];
                    }
                }
            }
        }

        return [
            'teacher' => [
                'eligible' => $completedCourses > 0,
                'progressValue' => $completedCourses,
                'totalCourses' => $totalCoursesWithLessons,
                'coursesProgress' => $coursesProgress,
                'claimableCourses' => $claimableCourses,
            ],
            'ministry' => [
                'eligible' => $highExams > 0,
                'progressValue' => $highExams,
                'claimableExams' => $claimableExams,
            ],
            'league' => [
                'eligible' => $bestConcludedRank !== null && $bestConcludedRank <= 10,
                'progressValue' => $bestConcludedRank,
                'claimableLeagues' => $claimableLeagues,
            ],
        ];
    }

    /**
     * Dispatch server-verified 1-click certificate issuance for any of the 3 official tracks.
     */
    public function claimCertificate(User $student, string $track, string $targetId): array
    {
        return match ($track) {
            'ministry' => $this->claimExamCertificate($student, $targetId),
            'league' => $this->claimLeagueCertificate($student, $targetId),
            default => $this->claimCourseCertificate($student, $targetId),
        };
    }

    /**
     * Server-verified 1-click claim of an official Course Completion Certificate for a 100%-completed enrolled course.
     */
    public function claimCourseCertificate(User $student, string $courseUuid): array
    {
        /** @var Course|null $course */
        $course = Course::where('uuid', $courseUuid)
            ->with('teacher.teacherProfile')
            ->first();

        if (! $course) {
            throw (new ModelNotFoundException)->setModel(Course::class, [$courseUuid]);
        }

        $this->accessService->assertCanAccess($student, $course, (int) $course->teacher_id, (int) $course->id);

        $totalPublished = Lesson::query()
            ->where('course_id', $course->id)
            ->where('status', LessonStatus::PUBLISHED)
            ->count();

        $completedLessons = StudentLessonProgress::forStudent($student->id)
            ->where('course_id', $course->id)
            ->where('is_completed', true)
            ->whereHas('lesson', fn ($q) => $q->where('status', LessonStatus::PUBLISHED))
            ->distinct('lesson_id')
            ->count('lesson_id');

        if ($totalPublished === 0 || $completedLessons < $totalPublished) {
            throw ValidationException::withMessages([
                'course_id' => ['يجب إتمام 100% من حصص المقرر أولاً لاستحقاق شهادة الإتمام المعتمدة.'],
            ]);
        }

        /** @var CourseCertificate|null $existing */
        $existing = CourseCertificate::query()
            ->where('student_id', $student->id)
            ->where('course_id', $course->id)
            ->where('status', CertificateStatus::VERIFIED)
            ->where(function ($q) {
                $q->where('issuer_type', 'teacher')->orWhereNull('issuer_type');
            })
            ->with(['teacher.teacherProfile', 'course:id,uuid,title_ar,title_en'])
            ->first();

        if ($existing) {
            return $this->formatStudentCertificate($existing, 100);
        }

        // Compute real average graded exam score in this course (defaults to 100.0 if course has no graded exams yet)
        $avgExamPercent = ExamSubmission::query()
            ->join('exams', 'exams.id', '=', 'exam_submissions.exam_id')
            ->where('exam_submissions.student_id', $student->id)
            ->where('exam_submissions.status', ExamSubmissionStatus::GRADED->value)
            ->where('exams.course_id', $course->id)
            ->avg('exam_submissions.percentage');

        $gradePercent = $avgExamPercent !== null ? round((float) $avgExamPercent, 1) : 100.0;
        $honorsAr = $gradePercent >= 95
            ? 'شهادة إتمام وتفوق بامتياز مع مرتبة الشرف'
            : ($gradePercent >= 85 ? 'شهادة إتمام وتفوق بتقدير ممتاز' : 'شهادة إتمام مقرر دراسي معتمد');
        $honorsEn = $gradePercent >= 95
            ? 'Certificate of Course Completion with High Honors'
            : ($gradePercent >= 85 ? 'Certificate of Excellence & Course Completion' : 'Certificate of Course Completion');

        $profile = StudentProfile::where('student_id', $student->id)->first();
        $certNumber = CourseCertificate::generateUniqueCertNumber('CRS');
        $issueDate = now()->toDateString();

        $signatureHash = CourseCertificate::computeSignatureHash(
            $certNumber,
            (int) $course->teacher_id,
            $student->name,
            $gradePercent,
            $issueDate
        );

        /** @var CourseCertificate $certificate */
        $certificate = CourseCertificate::create([
            'certificate_number' => $certNumber,
            'signature_hash' => $signatureHash,
            'teacher_id' => $course->teacher_id,
            'student_id' => $student->id,
            'course_id' => $course->id,
            'student_name_ar' => $student->name,
            'student_name_en' => $student->name,
            'student_email' => $student->email,
            'student_phone' => $student->phone,
            'course_name_ar' => $course->title_ar,
            'course_name_en' => $course->title_en ?: $course->title_ar,
            'grade_ar' => $course->grade_ar ?: ($profile?->grade_ar ?: 'الصف الثالث الثانوي'),
            'grade_percent' => $gradePercent,
            'honors_title_ar' => $honorsAr,
            'honors_title_en' => $honorsEn,
            'issue_date' => $issueDate,
            'status' => CertificateStatus::VERIFIED,
        ]);

        $certificate->load(['teacher.teacherProfile', 'course:id,uuid,title_ar,title_en']);

        return $this->formatStudentCertificate($certificate, 100);
    }

    /**
     * Server-verified issuance for Track 02: Graded Exam passed with >= 80%.
     */
    public function claimExamCertificate(User $student, string $examUuid): array
    {
        /** @var Exam|null $exam */
        $exam = Exam::where('uuid', $examUuid)
            ->with(['teacher.teacherProfile', 'course:id,uuid,title_ar,title_en,grade_ar'])
            ->first();

        if (! $exam) {
            throw (new ModelNotFoundException)->setModel(Exam::class, [$examUuid]);
        }

        $this->accessService->assertCanAccess(
            $student,
            $exam,
            (int) $exam->teacher_id,
            $exam->course_id ? (int) $exam->course_id : null
        );

        /** @var ExamSubmission|null $bestSub */
        $bestSub = ExamSubmission::forStudent($student->id)
            ->where('exam_id', $exam->id)
            ->where('status', ExamSubmissionStatus::GRADED)
            ->where('percentage', '>=', 80)
            ->orderByDesc('percentage')
            ->first();

        if (! $bestSub) {
            throw ValidationException::withMessages([
                'target_id' => ['يجب اجتياز هذا الاختبار بعد تصحيحه بنسبة 80% فأكثر لاستحقاق الشهادة.'],
            ]);
        }

        $existing = CourseCertificate::query()
            ->where('student_id', $student->id)
            ->where('status', CertificateStatus::VERIFIED)
            ->where('issuer_type', 'ministry')
            ->with(['teacher.teacherProfile', 'course:id,uuid,title_ar,title_en'])
            ->get()
            ->first(fn (CourseCertificate $c) => ($c->metadata['exam_uuid'] ?? null) === $exam->uuid);

        if ($existing) {
            return $this->formatStudentCertificate($existing, 100);
        }

        $gradePercent = round((float) $bestSub->percentage, 1);
        $honorsAr = $gradePercent >= 100
            ? 'شهادة تفوق وتميز بالدرجة النهائية (Full Mark 100%)'
            : ($gradePercent >= 90 ? 'شهادة تفوق أكاديمي بامتياز' : 'شهادة اجتياز وتفوق في الاختبار التقييمي');
        $honorsEn = $gradePercent >= 100
            ? 'Certificate of Excellence (Full Mark 100%)'
            : 'Certificate of Academic Exam Distinction';

        $profile = StudentProfile::where('student_id', $student->id)->first();
        $certNumber = CourseCertificate::generateUniqueCertNumber('EXM');
        $issueDate = now()->toDateString();

        $signatureHash = CourseCertificate::computeSignatureHash(
            $certNumber,
            (int) $exam->teacher_id,
            $student->name,
            $gradePercent,
            $issueDate
        );

        /** @var CourseCertificate $certificate */
        $certificate = CourseCertificate::create([
            'certificate_number' => $certNumber,
            'signature_hash' => $signatureHash,
            'teacher_id' => $exam->teacher_id,
            'student_id' => $student->id,
            'course_id' => $exam->course_id,
            'student_name_ar' => $student->name,
            'student_name_en' => $student->name,
            'student_email' => $student->email,
            'student_phone' => $student->phone,
            'course_name_ar' => $exam->title_ar,
            'course_name_en' => $exam->title_en ?: $exam->title_ar,
            'grade_ar' => $exam->course?->grade_ar ?: ($profile?->grade_ar ?: 'الصف الثالث الثانوي'),
            'grade_percent' => $gradePercent,
            'honors_title_ar' => $honorsAr,
            'honors_title_en' => $honorsEn,
            'issue_date' => $issueDate,
            'status' => CertificateStatus::VERIFIED,
            'issuer_type' => 'ministry',
            'metadata' => [
                'exam_id' => $exam->id,
                'exam_uuid' => $exam->uuid,
                'submission_id' => $bestSub->id,
            ],
        ]);

        $certificate->load(['teacher.teacherProfile', 'course:id,uuid,title_ar,title_en']);

        return $this->formatStudentCertificate($certificate, 100);
    }

    /**
     * Server-verified issuance for Track 03: Top 10 League Standing with earned points (> 0).
     */
    public function claimLeagueCertificate(User $student, string $leagueTarget): array
    {
        $isRepublic = $leagueTarget === 'general' || str_starts_with($leagueTarget, 'republic:');
        $profile = StudentProfile::where('student_id', $student->id)->first();

        if ($isRepublic) {
            // Auto-expire any past-due Republic League round first
            RepublicLeague::currentActive();

            $republic = str_starts_with($leagueTarget, 'republic:')
                ? RepublicLeague::where('uuid', substr($leagueTarget, strlen('republic:')))->first()
                : RepublicLeague::where('is_active', false)->latest('ends_at')->first();

            if (! $republic || $republic->is_active) {
                throw ValidationException::withMessages([
                    'target_id' => ['لا يمكن إصدار شهادة دوري الجمهورية قبل انتهاء الجولة وإعلان النتائج رسمياً.'],
                ]);
            }
            $ranked = $this->computeGeneralRankedStudents($student, $republic);
            $myRow = collect($ranked)->firstWhere('studentId', (int) $student->id);
            $rank = (int) ($myRow['rank'] ?? 0);
            $points = (int) ($myRow['xpPoints'] ?? 0);

            if ($rank <= 0 || $rank > 10 || $points <= 0) {
                throw ValidationException::withMessages([
                    'target_id' => ['يجب تحقيق نقاط فعلية والتواجد ضمن العشرة الأوائل (Top 10) لاستحقاق شهادة الدوري.'],
                ]);
            }

            $leagueKey = 'republic:'.$republic->uuid;
            $existing = CourseCertificate::query()
                ->where('student_id', $student->id)
                ->where('status', CertificateStatus::VERIFIED)
                ->where('issuer_type', 'league')
                ->with(['teacher.teacherProfile', 'course:id,uuid,title_ar,title_en'])
                ->get()
                ->first(fn (CourseCertificate $c) => ($c->metadata['league_key'] ?? null) === $leagueKey);

            if ($existing) {
                return $this->formatStudentCertificate($existing, 100);
            }

            $issuerUserId = (int) ($republic->started_by ?: $student->id);
            $leagueTitleAr = $republic->title_ar ?: 'دوري الجمهورية العام';
            $leagueTitleEn = 'General Republic League';
            $courseId = null;
        } else {
            /** @var CourseLeague|null $league */
            $league = CourseLeague::where('uuid', $leagueTarget)->first();
            if (! $league) {
                throw (new ModelNotFoundException)->setModel(CourseLeague::class, [$leagueTarget]);
            }

            $this->accessService->assertCanAccess(
                $student,
                $league,
                (int) $league->teacher_id,
                $league->course_id ? (int) $league->course_id : null
            );

            $this->leagueRulesService->syncCourseLeague($league);
            $league->refresh();

            if (! $league->isConcluded()) {
                throw ValidationException::withMessages([
                    'target_id' => ['لا يمكن إصدار شهادة الدوري قبل انتهاء موعده أو إعلان المعلم تتويج الدوري وإيقافه.'],
                ]);
            }

            /** @var LeagueParticipant|null $part */
            $part = LeagueParticipant::where('course_league_id', $league->id)
                ->where('student_id', $student->id)
                ->first();

            $rank = (int) ($part?->rank ?? 0);
            $points = (int) ($part?->xp_points ?? 0);

            if ($rank <= 0 || $rank > 10 || $points <= 0) {
                throw ValidationException::withMessages([
                    'target_id' => ['يجب تحقيق نقاط فعلية والتواجد ضمن العشرة الأوائل (Top 10) في الدوري لاستحقاق الشهادة.'],
                ]);
            }

            $leagueKey = 'course:'.$league->uuid;
            $existing = CourseCertificate::query()
                ->where('student_id', $student->id)
                ->where('status', CertificateStatus::VERIFIED)
                ->where('issuer_type', 'league')
                ->with(['teacher.teacherProfile', 'course:id,uuid,title_ar,title_en'])
                ->get()
                ->first(fn (CourseCertificate $c) => ($c->metadata['league_key'] ?? null) === $leagueKey);

            if ($existing) {
                return $this->formatStudentCertificate($existing, 100);
            }

            $issuerUserId = (int) $league->teacher_id;
            $leagueTitleAr = $league->title_ar;
            $leagueTitleEn = $league->course_name_ar ?: $league->title_ar;
            $courseId = $league->course_id;
        }

        $rankAr = match ($rank) {
            1 => 'المركز الأول 🥇',
            2 => 'المركز الثاني 🥈',
            3 => 'المركز الثالث 🥉',
            default => "المركز #{$rank}",
        };
        $rankEn = match ($rank) {
            1 => '1st Place 🥇',
            2 => '2nd Place 🥈',
            3 => '3rd Place 🥉',
            default => "Rank #{$rank}",
        };
        $pointsAr = number_format($points).' نقطة';
        $pointsEn = number_format($points).' pts';

        $certNumber = CourseCertificate::generateUniqueCertNumber('LGE');
        $issueDate = now()->toDateString();
        $gradePercent = 100.0;

        $signatureHash = CourseCertificate::computeSignatureHash(
            $certNumber,
            $issuerUserId,
            $student->name,
            $gradePercent,
            $issueDate
        );

        /** @var CourseCertificate $certificate */
        $certificate = CourseCertificate::create([
            'certificate_number' => $certNumber,
            'signature_hash' => $signatureHash,
            'teacher_id' => $issuerUserId,
            'student_id' => $student->id,
            'course_id' => $courseId,
            'student_name_ar' => $student->name,
            'student_name_en' => $student->name,
            'student_email' => $student->email,
            'student_phone' => $student->phone,
            'course_name_ar' => $leagueTitleAr,
            'course_name_en' => $leagueTitleEn,
            'grade_ar' => $profile?->grade_ar ?: 'الصف الثالث الثانوي',
            'grade_percent' => $gradePercent,
            'honors_title_ar' => "شهادة صدارة وتفوق في الدوري ({$rankAr})",
            'honors_title_en' => "Champions League Distinction ({$rankEn})",
            'issue_date' => $issueDate,
            'status' => CertificateStatus::VERIFIED,
            'issuer_type' => 'league',
            'metadata' => [
                'league_key' => $leagueKey,
                'is_republic' => $isRepublic,
                'rank' => $rank,
                'points' => $points,
                'rankAr' => $rankAr,
                'rankEn' => $rankEn,
                'pointsAr' => $pointsAr,
                'pointsEn' => $pointsEn,
            ],
        ]);

        $certificate->load(['teacher.teacherProfile', 'course:id,uuid,title_ar,title_en']);

        return $this->formatStudentCertificate($certificate, 100);
    }

    /**
     * Retrieve live General Republic League leaderboard (ONLY active once started by Platform Admin).
     * When Admin has not started a Republic League round yet, returns hasStarted = false with an empty leaderboard.
     */
    public function getGeneralLeaderboard(User $student): array
    {
        $republicLeague = RepublicLeague::currentActive();

        if (! $republicLeague) {
            return [
                'leagueId' => 'general',
                'category' => 'general',
                'isRepublicLeague' => true,
                'hasStarted' => false,
                'titleAr' => 'دوري الجمهورية العام',
                'courseTitleAr' => 'المجموع التراكمي لجميع المواد',
                'groupNameAr' => 'جميع طلاب المنصة',
                'teacherName' => null,
                'durationType' => LeagueDuration::MONTHLY->value,
                'durationLabelAr' => 'يُطلق من إدارة المنصة',
                'isActive' => false,
                'startsAt' => null,
                'endsAt' => null,
                'daysRemaining' => 0,
                'totalCompetitors' => 0,
                'myStanding' => null,
                'leaderboard' => [],
                'rules' => $this->leagueRulesService->getScoringRules(),
                'tiers' => $this->leagueRulesService->getTiersDefinition(),
                'prizes' => $this->leagueRulesService->getPrizesForLeague(LeagueDuration::MONTHLY->value, true),
            ];
        }

        $rankedRows = $this->computeGeneralRankedStudents($student, $republicLeague);
        $totalCompetitors = count($rankedRows);

        $topRows = array_slice($rankedRows, 0, 100);
        $topStudentIds = array_column($topRows, 'studentId');

        $profilesByStudent = ! empty($topStudentIds)
            ? StudentProfile::whereIn('student_id', $topStudentIds)->get()->keyBy('student_id')
            : collect();

        $metricsMap = $this->leagueRulesService->computeMetricsForStudents(
            $topStudentIds,
            null,
            null,
            $republicLeague->starts_at,
            $republicLeague->ends_at
        );

        $leaderboard = [];
        foreach ($topRows as $row) {
            $sid = (int) $row['studentId'];
            $isMe = $sid === (int) $student->id;
            /** @var StudentProfile|null $prof */
            $prof = $profilesByStudent->get($sid);
            $m = $metricsMap[$sid] ?? [];
            $displayName = $this->resolveMaskedDisplayName($row['name'], $prof, $isMe);
            $tierEnum = LeagueTier::resolveFromXp((int) $row['xpPoints']);
            $rank = (int) $row['rank'];

            $leaderboard[] = [
                'rank' => $rank,
                'previousRank' => null,
                'rankChange' => 0,
                'movement' => 'same',
                'displayName' => $displayName,
                'avatar' => (! $isMe && $prof && ! $prof->show_on_leaderboard) ? null : ($row['avatar'] ?: null),
                'xpPoints' => (int) $row['xpPoints'],
                'tier' => $tierEnum->value,
                'tierLabelAr' => $tierEnum->labelAr(),
                'badgeTitleAr' => $this->leagueRulesService->resolveRankBadgeAr($rank, true),
                'streakDays' => (int) $row['streakDays'],
                'schoolAr' => $m['schoolAr'] ?? '',
                'groupAr' => $m['groupAr'] ?? '',
                'examsSolved' => (int) ($m['examsSolved'] ?? 0),
                'perfectExams' => (int) ($m['perfectExams'] ?? 0),
                'lessonsStudied' => (int) ($m['lessonsStudied'] ?? 0),
                'homeworkRate' => (int) ($m['homeworkRate'] ?? 0),
                'attendanceRate' => (int) ($m['attendanceRate'] ?? 0),
                'isCurrentUser' => $isMe,
            ];
        }

        $firstXp = $rankedRows[0]['xpPoints'] ?? 0;
        $myRow = collect($rankedRows)->firstWhere('studentId', (int) $student->id);
        $myXp = (int) ($myRow['xpPoints'] ?? 0);
        $myRank = $myRow ? (int) $myRow['rank'] : null;
        $myStreak = (int) ($myRow['streakDays'] ?? 0);
        $tierProgress = $this->leagueRulesService->resolveTierProgress($myXp);

        $durationEnum = $republicLeague->duration_type instanceof LeagueDuration
            ? $republicLeague->duration_type
            : (LeagueDuration::tryFrom((string) $republicLeague->duration_type) ?? LeagueDuration::MONTHLY);

        return [
            'leagueId' => 'general',
            'category' => 'general',
            'isRepublicLeague' => true,
            'hasStarted' => true,
            'titleAr' => $republicLeague->title_ar ?: 'دوري الجمهورية العام',
            'courseTitleAr' => 'المجموع التراكمي لجميع المواد',
            'groupNameAr' => 'جميع طلاب المنصة',
            'teacherName' => null,
            'durationType' => $durationEnum->value,
            'durationLabelAr' => $durationEnum->labelAr(),
            'isActive' => true,
            'startsAt' => $republicLeague->starts_at?->format('Y-m-d'),
            'endsAt' => $republicLeague->ends_at?->format('Y-m-d'),
            'daysRemaining' => $republicLeague->roundDaysRemaining(),
            'totalCompetitors' => $totalCompetitors,
            'myStanding' => [
                'rank' => $myRank,
                'previousRank' => null,
                'rankChange' => 0,
                'movement' => 'same',
                'xpPoints' => $myXp,
                'streakDays' => $myStreak,
                'badgeTitleAr' => $myRank ? $this->leagueRulesService->resolveRankBadgeAr($myRank, true) : 'منافس نشط',
                'xpToFirst' => $myRank === 1 ? 0 : max(0, (int) $firstXp - $myXp),
                ...$tierProgress,
            ],
            'leaderboard' => $leaderboard,
            'rules' => $this->leagueRulesService->getScoringRules(),
            'tiers' => $this->leagueRulesService->getTiersDefinition(),
            'prizes' => $this->leagueRulesService->getPrizesForLeague($durationEnum->value, true),
        ];
    }

    /**
     * Retrieve privacy-preserving leaderboard for an accessible teacher course league.
     * Masks student names according to each student's privacy settings and never leaks email/phone.
     */
    public function getLeagueLeaderboard(User $student, string $leagueUuid): array
    {
        /** @var CourseLeague|null $league */
        $league = CourseLeague::where('uuid', $leagueUuid)->first();
        if (! $league) {
            throw (new ModelNotFoundException)->setModel(CourseLeague::class, [$leagueUuid]);
        }

        $this->accessService->assertCanAccess($student, $league, (int) $league->teacher_id, $league->course_id ? (int) $league->course_id : null);

        $this->leagueRulesService->syncCourseLeague($league);
        $league->refresh();

        $participants = LeagueParticipant::query()
            ->where('course_league_id', $league->id)
            ->with('student:id,name,avatar')
            ->orderByDesc('xp_points')
            ->orderByDesc('streak_days')
            ->orderBy('id')
            ->limit(100)
            ->get();

        $studentIds = $participants->pluck('student_id')->filter()->unique()->all();
        $profilesByStudent = ! empty($studentIds)
            ? StudentProfile::whereIn('student_id', $studentIds)->get()->keyBy('student_id')
            : collect();

        $metricsMap = $this->leagueRulesService->computeMetricsForStudents(
            $studentIds,
            (int) $league->teacher_id,
            $league->course_id ? (int) $league->course_id : null,
            $league->starts_at,
            $league->ends_at
        );

        $leaderboard = [];
        $rank = 1;

        foreach ($participants as $p) {
            $isMe = (int) $p->student_id === (int) $student->id;
            /** @var StudentProfile|null $prof */
            $prof = $p->student_id ? $profilesByStudent->get($p->student_id) : null;
            $m = $p->student_id ? ($metricsMap[(int) $p->student_id] ?? []) : [];
            $displayName = $this->resolveMaskedDisplayName($p->student?->name, $prof, $isMe);
            $xp = (int) $p->xp_points;
            $tierEnum = LeagueTier::resolveFromXp($xp);
            $prevRank = $p->previous_rank !== null && $p->previous_rank > 0 ? (int) $p->previous_rank : null;
            $rankChange = $prevRank !== null ? ($prevRank - $rank) : 0;

            $leaderboard[] = [
                'rank' => $rank,
                'previousRank' => $prevRank,
                'rankChange' => $rankChange,
                'movement' => $p->movement ?: ($rankChange > 0 ? 'up' : ($rankChange < 0 ? 'down' : 'same')),
                'displayName' => $displayName,
                'avatar' => (! $isMe && $prof && ! $prof->show_on_leaderboard) ? null : ($p->student?->avatar ?: null),
                'xpPoints' => $xp,
                'tier' => $tierEnum->value,
                'tierLabelAr' => $tierEnum->labelAr(),
                'badgeTitleAr' => $p->badge_title_ar ?: $this->leagueRulesService->resolveRankBadgeAr($rank, false),
                'streakDays' => (int) $p->streak_days,
                'schoolAr' => $m['schoolAr'] ?? ($p->school_ar && $p->school_ar !== 'مدرسة المتفوقين' ? $p->school_ar : ''),
                'groupAr' => $p->group_ar ?: ($m['groupAr'] ?? ($league->group_name_ar ?? '')),
                'examsSolved' => (int) ($m['examsSolved'] ?? $p->exams_solved_count),
                'perfectExams' => (int) ($m['perfectExams'] ?? $p->perfect_exams_count),
                'lessonsStudied' => (int) ($m['lessonsStudied'] ?? 0),
                'homeworkRate' => (int) ($m['homeworkRate'] ?? $p->homework_rate),
                'attendanceRate' => (int) ($m['attendanceRate'] ?? $p->attendance_rate),
                'isCurrentUser' => $isMe,
            ];

            $rank++;
        }

        $totalCompetitors = LeagueParticipant::where('course_league_id', $league->id)->count();
        $firstXp = (int) ($leaderboard[0]['xpPoints'] ?? 0);

        /** @var LeagueParticipant|null $myParticipant */
        $myParticipant = LeagueParticipant::where('course_league_id', $league->id)
            ->where('student_id', $student->id)
            ->first();

        $myXp = (int) ($myParticipant?->xp_points ?? 0);
        $myRank = $myParticipant && $myParticipant->rank > 0 ? (int) $myParticipant->rank : null;
        $myPrevRank = $myParticipant && $myParticipant->previous_rank > 0 ? (int) $myParticipant->previous_rank : null;
        $myRankChange = ($myRank !== null && $myPrevRank !== null) ? ($myPrevRank - $myRank) : 0;
        $tierProgress = $this->leagueRulesService->resolveTierProgress($myXp);

        $durationEnum = $league->duration_type instanceof LeagueDuration
            ? $league->duration_type
            : (LeagueDuration::tryFrom((string) $league->duration_type) ?? LeagueDuration::MONTHLY);

        $teacherName = User::where('id', $league->teacher_id)->value('name');

        return [
            'leagueId' => $league->uuid,
            'category' => 'teachers',
            'isRepublicLeague' => false,
            'hasStarted' => true,
            'titleAr' => $league->title_ar,
            'courseTitleAr' => $league->course_name_ar,
            'groupNameAr' => $league->group_name_ar,
            'teacherName' => $teacherName,
            'durationType' => $durationEnum->value,
            'durationLabelAr' => $durationEnum->labelAr(),
            'isActive' => (bool) $league->is_active,
            'startsAt' => $league->starts_at?->format('Y-m-d'),
            'endsAt' => $league->ends_at?->format('Y-m-d'),
            'daysRemaining' => $league->is_active ? $league->roundDaysRemaining() : 0,
            'totalCompetitors' => $totalCompetitors,
            'myStanding' => [
                'rank' => $myRank,
                'previousRank' => $myPrevRank,
                'rankChange' => $myRankChange,
                'movement' => $myParticipant?->movement ?: 'same',
                'xpPoints' => $myXp,
                'streakDays' => (int) ($myParticipant?->streak_days ?? 0),
                'badgeTitleAr' => $myParticipant?->badge_title_ar ?: ($myRank ? $this->leagueRulesService->resolveRankBadgeAr($myRank, false) : 'منافس نشط'),
                'xpToFirst' => $myRank === 1 ? 0 : max(0, $firstXp - $myXp),
                ...$tierProgress,
            ],
            'leaderboard' => $leaderboard,
            'rules' => $this->leagueRulesService->getScoringRules(),
            'tiers' => $this->leagueRulesService->getTiersDefinition(),
            'prizes' => $this->leagueRulesService->getPrizesForLeague($durationEnum->value, false),
        ];
    }

    /**
     * Build General Republic League summary row for listLeagues().
     * Strictly reflects whether Platform Admin has started an active Republic League round.
     */
    protected function buildGeneralLeagueSummary(User $student): array
    {
        $republicLeague = RepublicLeague::currentActive();

        if (! $republicLeague) {
            return [
                'id' => 'general',
                'category' => 'general',
                'isRepublicLeague' => true,
                'hasStarted' => false,
                'titleAr' => 'دوري الجمهورية العام',
                'courseTitleAr' => 'المجموع التراكمي لجميع المواد',
                'groupNameAr' => 'جميع طلاب المنصة',
                'teacherName' => null,
                'durationType' => LeagueDuration::MONTHLY->value,
                'durationLabelAr' => 'لم يبدأ بعد',
                'tier' => LeagueTier::BRONZE->value,
                'tierLabelAr' => LeagueTier::BRONZE->labelAr(),
                'participantsCount' => 0,
                'isActive' => false,
                'startsAt' => null,
                'endsAt' => null,
                'daysRemaining' => 0,
                'myRank' => null,
                'myXp' => 0,
                'myStreak' => 0,
                'myBadgeAr' => null,
            ];
        }

        $ranked = $this->computeGeneralRankedStudents($student, $republicLeague);
        $myRow = collect($ranked)->firstWhere('studentId', (int) $student->id);
        $myXp = (int) ($myRow['xpPoints'] ?? 0);
        $myRank = $myRow ? (int) $myRow['rank'] : null;
        $myStreak = (int) ($myRow['streakDays'] ?? 0);
        $tierEnum = LeagueTier::resolveFromXp($myXp);
        $durationEnum = $republicLeague->duration_type instanceof LeagueDuration
            ? $republicLeague->duration_type
            : (LeagueDuration::tryFrom((string) $republicLeague->duration_type) ?? LeagueDuration::MONTHLY);

        return [
            'id' => 'general',
            'category' => 'general',
            'isRepublicLeague' => true,
            'hasStarted' => true,
            'titleAr' => $republicLeague->title_ar ?: 'دوري الجمهورية العام',
            'courseTitleAr' => 'المجموع التراكمي لجميع المواد',
            'groupNameAr' => 'جميع طلاب المنصة',
            'teacherName' => null,
            'durationType' => $durationEnum->value,
            'durationLabelAr' => $durationEnum->labelAr(),
            'tier' => $tierEnum->value,
            'tierLabelAr' => $tierEnum->labelAr(),
            'participantsCount' => count($ranked),
            'isActive' => true,
            'startsAt' => $republicLeague->starts_at?->format('Y-m-d'),
            'endsAt' => $republicLeague->ends_at?->format('Y-m-d'),
            'daysRemaining' => $republicLeague->roundDaysRemaining(),
            'myRank' => $myRank,
            'myXp' => $myXp,
            'myStreak' => $myStreak,
            'myBadgeAr' => $myRank ? $this->leagueRulesService->resolveRankBadgeAr($myRank, true) : 'منافس نشط',
        ];
    }

    /**
     * Compute authoritative ranked student list for an active Admin-started Republic League strictly within its window:
     * True/False (+1) + MCQ (+3) + Essay (+5) + Exam Full Mark Bonus (+3) + Daily Streak (+3/day) + Teacher Bonus.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function computeGeneralRankedStudents(User $currentStudent, RepublicLeague $republicLeague): array
    {
        $activeStudents = User::query()
            ->where('role', UserRole::STUDENT)
            ->where('status', UserStatus::ACTIVE)
            ->get(['id', 'name', 'avatar']);

        $studentIds = $activeStudents->pluck('id')->map(fn ($id) => (int) $id)->all();
        $metricsMap = $this->leagueRulesService->computeMetricsForStudents(
            $studentIds,
            null,
            null,
            $republicLeague->starts_at,
            $republicLeague->ends_at
        );

        $teacherBonusByStudent = ! empty($studentIds)
            ? StudentXpLedger::query()
                ->whereIn('student_id', $studentIds)
                ->where('source_type', 'teacher_league_bonus')
                ->where('created_at', '>=', $republicLeague->starts_at)
                ->when($republicLeague->ends_at, fn ($q) => $q->where('created_at', '<=', $republicLeague->ends_at))
                ->select(['student_id', DB::raw('SUM(points) as bonus_xp')])
                ->groupBy('student_id')
                ->pluck('bonus_xp', 'student_id')
            : collect();

        $rows = [];
        foreach ($activeStudents as $u) {
            $sid = (int) $u->id;
            $m = $metricsMap[$sid] ?? [];
            $streakDays = (int) ($m['windowStreakDays'] ?? 0);
            $ruleXp = (int) ($m['ruleXp'] ?? 0);
            $teacherBonus = (int) ($teacherBonusByStudent[$sid] ?? 0);
            $totalXp = $ruleXp + $teacherBonus;

            // Include students who have earned league points in this round, plus always include current user
            if ($totalXp <= 0 && $sid !== (int) $currentStudent->id) {
                continue;
            }

            $rows[] = [
                'studentId' => $sid,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'xpPoints' => $totalXp,
                'streakDays' => $streakDays,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($b['xpPoints'] !== $a['xpPoints']) {
                return $b['xpPoints'] <=> $a['xpPoints'];
            }
            if ($b['streakDays'] !== $a['streakDays']) {
                return $b['streakDays'] <=> $a['streakDays'];
            }

            return $a['studentId'] <=> $b['studentId'];
        });

        foreach ($rows as $idx => &$row) {
            $row['rank'] = $idx + 1;
        }
        unset($row);

        return $rows;
    }

    /**
     * Apply per-student leaderboard privacy settings cleanly.
     */
    protected function resolveMaskedDisplayName(?string $rawName, ?StudentProfile $prof, bool $isMe): string
    {
        $cleanName = $rawName ?: 'طالب متفوق';
        if ($isMe || ! $prof) {
            return $cleanName;
        }

        if (! $prof->show_on_leaderboard) {
            return 'طالب متفوق (مخفي)';
        }

        if ($prof->display_name_mode === 'nickname' && ! empty($prof->nickname)) {
            return $prof->nickname;
        }

        if ($prof->display_name_mode === 'initials') {
            $parts = preg_split('/\s+/u', trim($cleanName)) ?: [];
            $first = $parts[0] ?? 'طالب';
            $lastInitial = isset($parts[1]) ? mb_substr($parts[1], 0, 1).'.' : '';

            return trim("{$first} {$lastInitial}");
        }

        return $cleanName;
    }

    /**
     * List verified certificates issued to the authenticated student (matched by student_id or fallback email).
     */
    public function listCertificates(User $student): array
    {
        return $this->mapStudentCertificateModels($student, $this->fetchStudentCertificateModels($student));
    }

    /**
     * Query verified certificates belonging to a student, including email-matched teacher-issued records.
     */
    protected function fetchStudentCertificateModels(User $student)
    {
        $emailLower = strtolower(trim((string) $student->email));

        return CourseCertificate::query()
            ->where('status', CertificateStatus::VERIFIED)
            ->where(function ($q) use ($student, $emailLower) {
                $q->where('student_id', $student->id);
                if ($emailLower !== '') {
                    $q->orWhere(function ($q2) use ($emailLower) {
                        $q2->whereNull('student_id')
                            ->whereRaw('LOWER(student_email) = ?', [$emailLower]);
                    });
                }
            })
            ->with(['teacher.teacherProfile', 'course:id,uuid,title_ar,title_en'])
            ->latest('issue_date')
            ->latest('id')
            ->get();
    }

    /**
     * Map certificate models with real per-course completion percentages and teacher details.
     */
    protected function mapStudentCertificateModels(User $student, $certModels): array
    {
        $linkedCourseIds = $certModels->pluck('course_id')->filter()->unique()->values()->all();
        $completionMap = [];

        if (! empty($linkedCourseIds)) {
            $publishedByCourse = Lesson::query()
                ->whereIn('course_id', $linkedCourseIds)
                ->where('status', LessonStatus::PUBLISHED)
                ->select(['course_id', DB::raw('COUNT(*) as total_lessons')])
                ->groupBy('course_id')
                ->pluck('total_lessons', 'course_id');

            $doneByCourse = StudentLessonProgress::forStudent($student->id)
                ->whereIn('course_id', $linkedCourseIds)
                ->where('is_completed', true)
                ->whereHas('lesson', fn ($q) => $q->where('status', LessonStatus::PUBLISHED))
                ->select(['course_id', DB::raw('COUNT(DISTINCT lesson_id) as done_lessons')])
                ->groupBy('course_id')
                ->pluck('done_lessons', 'course_id');

            foreach ($linkedCourseIds as $cid) {
                $tot = (int) ($publishedByCourse[$cid] ?? 0);
                $done = (int) ($doneByCourse[$cid] ?? 0);
                $completionMap[(int) $cid] = $tot > 0 ? min(100, (int) round(($done / $tot) * 100)) : 100;
            }
        }

        return $certModels
            ->map(fn (CourseCertificate $c) => $this->formatStudentCertificate(
                $c,
                $c->course_id ? ($completionMap[(int) $c->course_id] ?? 100) : 100
            ))
            ->values()
            ->all();
    }

    /**
     * Canonical student certificate payload matching OfficialCertificateDocument + public QR verification.
     */
    protected function formatStudentCertificate(CourseCertificate $c, int $completionPercent = 100): array
    {
        $teacherProfile = $c->teacher?->teacherProfile;
        $teacherNameAr = $teacherProfile?->name_ar ?: ($c->teacher?->name ?: 'معلم معتمد');
        $teacherNameEn = $teacherProfile?->name_en ?: $teacherNameAr;
        $teacherTitleAr = $teacherProfile?->title_ar ?: 'معلم معتمد على منصة متفوّق';
        $teacherTitleEn = $teacherProfile?->title_en ?: 'Certified Motafawweq Educator';
        $issueDate = $c->issue_date?->format('Y-m-d');
        $gradePercent = (float) $c->grade_percent;
        $scoreLabel = fmod($gradePercent, 1.0) === 0.0
            ? sprintf('%d%%', (int) $gradePercent)
            : sprintf('%.1f%%', $gradePercent);

        $issuerType = in_array($c->issuer_type, ['teacher', 'ministry', 'league'], true)
            ? $c->issuer_type
            : 'teacher';
        $meta = is_array($c->metadata) ? $c->metadata : [];

        return [
            'id' => $c->uuid,
            'serialId' => $c->certificate_number,
            'certificateNumber' => $c->certificate_number,
            'signatureHash' => $c->signature_hash,
            'issuerType' => $issuerType,
            'category' => $issuerType,
            'issuerNameAr' => $issuerType === 'teacher' ? $teacherNameAr : 'إدارة منصة متفوّق التعليمية',
            'issuerNameEn' => $issuerType === 'teacher' ? $teacherNameEn : 'Motafawweq Platform Administration',
            'instructorAr' => $teacherNameAr,
            'instructorEn' => $teacherNameEn,
            'instructorTitleAr' => $teacherTitleAr,
            'instructorTitleEn' => $teacherTitleEn,
            'studentNameAr' => $c->student_name_ar,
            'studentNameEn' => $c->student_name_en ?: $c->student_name_ar,
            'courseId' => $c->course?->uuid,
            'courseNameAr' => $c->course_name_ar,
            'courseNameEn' => $c->course_name_en ?: ($c->course?->title_en ?: $c->course_name_ar),
            'gradeAr' => $c->grade_ar ?: 'الصف الثالث الثانوي',
            'gradePercent' => $gradePercent,
            'score' => $scoreLabel,
            'scoreEn' => $scoreLabel,
            'completionRate' => "{$completionPercent}%",
            'honorsTitleAr' => $c->honors_title_ar,
            'honorsTitleEn' => $c->honors_title_en ?: 'Certificate of Academic Excellence',
            'rankAr' => $meta['rankAr'] ?? null,
            'rankEn' => $meta['rankEn'] ?? null,
            'pointsAr' => $meta['pointsAr'] ?? null,
            'pointsEn' => $meta['pointsEn'] ?? null,
            'issueDate' => $issueDate,
            'completionDate' => $issueDate,
            'completionDateEn' => $c->issue_date?->format('F j, Y'),
            'status' => $c->status?->value ?? CertificateStatus::VERIFIED->value,
            'verifyUrl' => "/api/v1/verify-certificate/{$c->certificate_number}",
            'verifyPath' => "/verify/{$c->certificate_number}",
        ];
    }
}
