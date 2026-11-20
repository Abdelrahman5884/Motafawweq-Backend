<?php

namespace App\Services\V1\Student;

use App\Enums\CertificateStatus;
use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Enums\LessonStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CourseCertificate;
use App\Models\CourseLeague;
use App\Models\ExamSubmission;
use App\Models\HomeworkSubmission;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
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

class StudentGamificationService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService,
        protected StudentActivityService $activityService,
        protected LeagueRulesService $leagueRulesService
    ) {}

    /**
     * Retrieve student's total server-verified XP, level, streak, and recent immutable XP ledger entries.
     */
    public function getGamificationSummary(User $student): array
    {
        $totalXp = (int) StudentXpLedger::forStudent($student->id)->sum('points');
        $level = max(1, (int) floor($totalXp / 100) + 1);
        $nextLevelXp = $level * 100;
        $activity = $this->activityService->getActivitySnapshot($student);

        $recentLedger = StudentXpLedger::forStudent($student->id)
            ->latest('created_at')
            ->limit(25)
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
            'nextLevelXp' => $nextLevelXp,
            'streakDays' => $activity['streakDays'],
            'longestStreakDays' => $activity['longestStreakDays'],
            'todayActiveSeconds' => $activity['activeSecondsToday'],
            'todayActiveMinutes' => $activity['activeMinutesToday'],
            'recentLedger' => $recentLedger,
        ];
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
     * 52-week daily activity heatmap computed from daily platform presence, lesson progress, exam submissions and XP ledger.
     */
    public function getActivityHeatmap(User $student): array
    {
        $profile = StudentProfile::where('student_id', $student->id)->first();
        $timezone = $this->activityService->resolveTimezone($profile);
        $start = Carbon::now($timezone)->startOfDay()->subDays(363);

        $dailyActivities = StudentDailyActivity::forStudent($student->id)
            ->whereDate('activity_date', '>=', $start->toDateString())
            ->get(['activity_date', 'active_seconds'])
            ->keyBy(fn (StudentDailyActivity $row) => $row->activity_date->toDateString());

        $xpByDay = StudentXpLedger::forStudent($student->id)
            ->where('created_at', '>=', $start->copy()->setTimezone('UTC')->subDay())
            ->get(['points', 'created_at'])
            ->groupBy(fn ($e) => $e->created_at->copy()->setTimezone($timezone)->format('Y-m-d'));

        $lessonMinutesByDay = StudentLessonProgress::forStudent($student->id)
            ->where('updated_at', '>=', $start->copy()->setTimezone('UTC')->subDay())
            ->get(['watched_seconds', 'updated_at'])
            ->groupBy(fn ($p) => $p->updated_at->copy()->setTimezone($timezone)->format('Y-m-d'))
            ->map(fn ($g) => (int) round($g->sum('watched_seconds') / 60));

        $examsByDay = ExamSubmission::forStudent($student->id)
            ->where('submitted_at', '>=', $start->copy()->setTimezone('UTC')->subDay())
            ->whereNotNull('submitted_at')
            ->get(['submitted_at'])
            ->groupBy(fn ($s) => $s->submitted_at->copy()->setTimezone($timezone)->format('Y-m-d'))
            ->map->count();

        $dayNamesAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

        $weeks = [];
        $week = [];
        $cursor = $start->copy();
        $activeDays = 0;
        for ($i = 0; $i < 364; $i++) {
            $key = $cursor->format('Y-m-d');
            /** @var StudentDailyActivity|null $dailyRow */
            $dailyRow = $dailyActivities->get($key);
            $platformMinutes = $dailyRow ? intdiv((int) $dailyRow->active_seconds, 60) : 0;
            $minutes = max($platformMinutes, (int) ($lessonMinutesByDay[$key] ?? 0));
            $quizzes = (int) ($examsByDay[$key] ?? 0);
            $xp = (int) (isset($xpByDay[$key]) ? $xpByDay[$key]->sum('points') : 0);
            $hasVisit = $dailyRow !== null;
            $score = $minutes + ($quizzes * 15) + (int) round($xp / 5) + ($hasVisit ? 1 : 0);
            $level = $score <= 0 ? 0 : ($score < 15 ? 1 : ($score < 40 ? 2 : ($score < 80 ? 3 : 4)));
            if ($level > 0) {
                $activeDays++;
            }

            $week[] = [
                'date' => $key,
                'dayName' => $dayNamesAr[$cursor->dayOfWeek],
                'minutes' => $minutes,
                'quizzes' => $quizzes,
                'xp' => $xp,
                'level' => $level,
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
            $cursor->addDay();
        }

        return [
            'weeks' => $weeks,
            'activeDays' => $activeDays,
        ];
    }

    /**
     * Weekly challenges with real progress computed from this week's activity.
     */
    public function getWeeklyChallenges(User $student): array
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::SATURDAY);

        $lessonsDone = StudentLessonProgress::forStudent($student->id)
            ->where('is_completed', true)
            ->where('completed_at', '>=', $weekStart)
            ->count();

        $examsDone = ExamSubmission::forStudent($student->id)
            ->where('submitted_at', '>=', $weekStart)
            ->count();

        $perfectExams = ExamSubmission::forStudent($student->id)
            ->where('submitted_at', '>=', $weekStart)
            ->where('percentage', '>=', 100)
            ->count();

        $homeworksDone = HomeworkSubmission::forStudent($student->id)
            ->where('submitted_at', '>=', $weekStart)
            ->count();

        $profile = StudentProfile::where('student_id', $student->id)->first();
        $weeklyExamsGoal = max(1, (int) ($profile?->weekly_exams_goal ?? 3));

        $defs = [
            ['id' => 'wc-lessons', 'titleAr' => 'أكمل 5 حصص هذا الأسبوع', 'titleEn' => 'Complete 5 lessons this week', 'target' => 5, 'current' => $lessonsDone, 'xp' => 100, 'type' => 'lessons'],
            ['id' => 'wc-exams', 'titleAr' => "حل {$weeklyExamsGoal} اختبارات هذا الأسبوع", 'titleEn' => "Solve {$weeklyExamsGoal} exams this week", 'target' => $weeklyExamsGoal, 'current' => $examsDone, 'xp' => 80, 'type' => 'exams'],
            ['id' => 'wc-perfect', 'titleAr' => 'احصل على الدرجة النهائية في اختبار واحد', 'titleEn' => 'Score 100% in one exam', 'target' => 1, 'current' => $perfectExams, 'xp' => 60, 'type' => 'perfect'],
            ['id' => 'wc-homework', 'titleAr' => 'سلّم واجبين هذا الأسبوع', 'titleEn' => 'Submit 2 homeworks this week', 'target' => 2, 'current' => $homeworksDone, 'xp' => 50, 'type' => 'homework'],
        ];

        $daysLeft = max(0, (int) Carbon::now()->diffInDays($weekStart->copy()->addDays(7), false));

        return array_map(function ($c) use ($daysLeft) {
            $current = min($c['target'], (int) $c['current']);

            return array_merge($c, [
                'current' => $current,
                'progressPercent' => (int) round(($current / max(1, $c['target'])) * 100),
                'isCompleted' => $current >= $c['target'],
                'daysLeft' => $daysLeft,
            ]);
        }, $defs);
    }

    /**
     * Archive of finished leagues the student participated in.
     */
    public function getPastLeagues(User $student): array
    {
        return LeagueParticipant::where('student_id', $student->id)
            ->whereHas('league', fn ($q) => $q->where('is_active', false))
            ->with('league:id,uuid,title_ar,course_name_ar,ends_at,total_competitors')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (LeagueParticipant $p) => [
                'id' => $p->uuid,
                'leagueId' => $p->league?->uuid,
                'titleAr' => $p->league?->title_ar,
                'seasonAr' => $p->league?->course_name_ar,
                'dateAr' => $p->league?->ends_at?->format('Y-m-d'),
                'rank' => (int) ($p->rank ?: 0),
                'points' => (int) $p->xp_points,
                'tierAr' => $p->badge_title_ar ?: ($p->tier ?: 'مشارك'),
                'totalCompetitors' => (int) ($p->league?->total_competitors ?? 0),
            ])->values()->all();
    }

    /**
     * Real eligibility status per certificate track for the student.
     */
    public function getCertificateEligibility(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);

        $completedCourses = 0;
        foreach ($entitlements['course_ids'] as $courseId) {
            $total = Lesson::where('course_id', $courseId)
                ->where('status', LessonStatus::PUBLISHED)
                ->count();
            if ($total === 0) {
                continue;
            }
            $done = StudentLessonProgress::forStudent($student->id)
                ->where('course_id', $courseId)
                ->where('is_completed', true)
                ->count();
            if ($done >= $total) {
                $completedCourses++;
            }
        }

        $highExams = ExamSubmission::forStudent($student->id)
            ->where('percentage', '>=', 80)
            ->count();

        $bestRank = LeagueParticipant::where('student_id', $student->id)
            ->where('rank', '>', 0)
            ->min('rank');

        return [
            'teacher' => ['eligible' => $completedCourses > 0, 'progressValue' => $completedCourses],
            'ministry' => ['eligible' => $highExams > 0, 'progressValue' => $highExams],
            'league' => ['eligible' => $bestRank !== null && (int) $bestRank <= 10, 'progressValue' => $bestRank !== null ? (int) $bestRank : null],
        ];
    }

    /**
     * Retrieve live General Republic League leaderboard computed strictly from official XP ledger + daily streak rules.
     */
    public function getGeneralLeaderboard(User $student): array
    {
        $rankedRows = $this->computeGeneralRankedStudents($student);
        $totalCompetitors = count($rankedRows);

        $topRows = array_slice($rankedRows, 0, 100);
        $topStudentIds = array_column($topRows, 'studentId');

        $profilesByStudent = ! empty($topStudentIds)
            ? StudentProfile::whereIn('student_id', $topStudentIds)->get()->keyBy('student_id')
            : collect();

        $metricsMap = $this->leagueRulesService->computeMetricsForStudents($topStudentIds);

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

        $roundBounds = $this->getWeeklyRoundBounds();

        return [
            'leagueId' => 'general',
            'category' => 'general',
            'isRepublicLeague' => true,
            'titleAr' => 'دوري الجمهورية العام',
            'courseTitleAr' => 'المجموع التراكمي لجميع المواد',
            'groupNameAr' => 'جميع طلاب المنصة',
            'teacherName' => null,
            'durationType' => LeagueDuration::WEEKLY->value,
            'durationLabelAr' => LeagueDuration::WEEKLY->labelAr(),
            'isActive' => true,
            'startsAt' => $roundBounds['startsAt'],
            'endsAt' => $roundBounds['endsAt'],
            'daysRemaining' => $roundBounds['daysRemaining'],
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
            'prizes' => $this->leagueRulesService->getPrizesForLeague(LeagueDuration::WEEKLY->value, true),
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
            $league->course_id ? (int) $league->course_id : null
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
     */
    protected function buildGeneralLeagueSummary(User $student): array
    {
        $ranked = $this->computeGeneralRankedStudents($student);
        $myRow = collect($ranked)->firstWhere('studentId', (int) $student->id);
        $myXp = (int) ($myRow['xpPoints'] ?? 0);
        $myRank = $myRow ? (int) $myRow['rank'] : null;
        $myStreak = (int) ($myRow['streakDays'] ?? 0);
        $tierEnum = LeagueTier::resolveFromXp($myXp);
        $roundBounds = $this->getWeeklyRoundBounds();

        return [
            'id' => 'general',
            'category' => 'general',
            'isRepublicLeague' => true,
            'titleAr' => 'دوري الجمهورية العام',
            'courseTitleAr' => 'المجموع التراكمي لجميع المواد',
            'groupNameAr' => 'جميع طلاب المنصة',
            'teacherName' => null,
            'durationType' => LeagueDuration::WEEKLY->value,
            'durationLabelAr' => LeagueDuration::WEEKLY->labelAr(),
            'tier' => $tierEnum->value,
            'tierLabelAr' => $tierEnum->labelAr(),
            'participantsCount' => count($ranked),
            'isActive' => true,
            'startsAt' => $roundBounds['startsAt'],
            'endsAt' => $roundBounds['endsAt'],
            'daysRemaining' => $roundBounds['daysRemaining'],
            'myRank' => $myRank,
            'myXp' => $myXp,
            'myStreak' => $myStreak,
            'myBadgeAr' => $myRank ? $this->leagueRulesService->resolveRankBadgeAr($myRank, true) : 'منافس نشط',
        ];
    }

    /**
     * Compute authoritative ranked student list for the General Republic League (ledger XP + streak * 20).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function computeGeneralRankedStudents(User $currentStudent): array
    {
        $ledgerTotals = StudentXpLedger::query()
            ->select(['student_id', DB::raw('SUM(points) as total_xp')])
            ->groupBy('student_id')
            ->pluck('total_xp', 'student_id');

        $profiles = StudentProfile::query()
            ->get(['student_id', 'current_streak_days'])
            ->keyBy('student_id');

        $activeStudents = User::query()
            ->where('role', UserRole::STUDENT)
            ->where('status', UserStatus::ACTIVE)
            ->get(['id', 'name', 'avatar']);

        $rows = [];
        foreach ($activeStudents as $u) {
            $sid = (int) $u->id;
            $ledgerXp = (int) ($ledgerTotals[$sid] ?? 0);
            $streakDays = (int) ($profiles->get($sid)?->current_streak_days ?? 0);
            $totalXp = $ledgerXp + ($streakDays * LeagueRulesService::XP_STREAK_PER_DAY);

            // Include students who have started earning XP or streak, plus always include current user
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
     * Compute current weekly round start/end dates (Saturday -> Friday 23:59).
     *
     * @return array{startsAt: string, endsAt: string, daysRemaining: int}
     */
    protected function getWeeklyRoundBounds(): array
    {
        $now = Carbon::now();
        $weekStart = $now->copy()->startOfWeek(Carbon::SATURDAY);
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();
        $hoursRemaining = $now->diffInHours($weekEnd, false);

        return [
            'startsAt' => $weekStart->format('Y-m-d'),
            'endsAt' => $weekEnd->format('Y-m-d'),
            'daysRemaining' => $hoursRemaining > 0 ? (int) ceil($hoursRemaining / 24) : 0,
        ];
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
     * List verified certificates issued to the authenticated student.
     */
    public function listCertificates(User $student): array
    {
        $certificates = CourseCertificate::query()
            ->where('student_id', $student->id)
            ->where('status', CertificateStatus::VERIFIED)
            ->latest('issue_date')
            ->get();

        return $certificates->map(fn (CourseCertificate $c) => [
            'id' => $c->uuid,
            'certificateNumber' => $c->certificate_number,
            'signatureHash' => $c->signature_hash,
            'studentNameAr' => $c->student_name_ar,
            'courseNameAr' => $c->course_name_ar,
            'gradeAr' => $c->grade_ar,
            'gradePercent' => (float) $c->grade_percent,
            'honorsTitleAr' => $c->honors_title_ar,
            'issueDate' => $c->issue_date?->format('Y-m-d'),
            'verifyUrl' => "/api/v1/verify-certificate/{$c->certificate_number}",
        ])->values()->all();
    }
}
