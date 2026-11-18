<?php

namespace App\Services\V1\Student;

use App\Enums\CertificateStatus;
use App\Models\CourseCertificate;
use App\Models\CourseLeague;
use App\Models\ExamSubmission;
use App\Models\HomeworkSubmission;
use App\Models\LeagueParticipant;
use App\Models\StudentLessonProgress;
use App\Models\StudentProfile;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StudentGamificationService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService
    ) {}

    /**
     * Retrieve student's total server-verified XP, level, badges, and recent immutable XP ledger entries.
     */
    public function getGamificationSummary(User $student): array
    {
        $totalXp = (int) StudentXpLedger::forStudent($student->id)->sum('points');
        $level = max(1, (int) floor($totalXp / 100) + 1);
        $nextLevelXp = $level * 100;

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
            'recentLedger' => $recentLedger,
        ];
    }

    /**
     * List accessible course leagues for the student.
     */
    public function listLeagues(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);
        if (empty($entitlements['teacher_ids']) && empty($entitlements['course_ids'])) {
            return [];
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
            ->withCount('participants')
            ->latest()
            ->get();

        $myParticipations = LeagueParticipant::where('student_id', $student->id)
            ->whereIn('course_league_id', $leagues->pluck('id'))
            ->get()
            ->keyBy('course_league_id');

        $teacherNames = User::whereIn('id', $leagues->pluck('teacher_id')->filter()->unique())
            ->pluck('name', 'id');

        return $leagues->map(function (CourseLeague $l) use ($myParticipations, $teacherNames) {
            /** @var LeagueParticipant|null $me */
            $me = $myParticipations->get($l->id);
            $myRank = null;
            if ($me) {
                $myRank = LeagueParticipant::where('course_league_id', $l->id)
                    ->where('xp_points', '>', (int) $me->xp_points)
                    ->count() + 1;
            }

            return [
                'id' => $l->uuid,
                'titleAr' => $l->title_ar,
                'courseTitleAr' => $l->course_name_ar,
                'groupNameAr' => $l->group_name_ar,
                'teacherName' => $teacherNames[$l->teacher_id] ?? null,
                'tier' => $me?->tier ?: null,
                'participantsCount' => (int) $l->participants_count,
                'isActive' => (bool) $l->is_active,
                'startsAt' => $l->starts_at?->format('Y-m-d'),
                'endsAt' => $l->ends_at?->format('Y-m-d'),
                'daysRemaining' => $l->is_active ? $l->roundDaysRemaining() : 0,
                'myRank' => $myRank,
                'myXp' => (int) ($me?->xp_points ?? 0),
                'myStreak' => (int) ($me?->streak_days ?? 0),
                'myBadgeAr' => $me?->badge_title_ar,
            ];
        })->values()->all();
    }

    /**
     * 52-week daily activity heatmap computed from lesson progress, exam submissions and XP ledger.
     */
    public function getActivityHeatmap(User $student): array
    {
        $start = Carbon::now()->startOfDay()->subDays(363);

        $xpByDay = StudentXpLedger::forStudent($student->id)
            ->where('created_at', '>=', $start)
            ->get(['points', 'created_at'])
            ->groupBy(fn ($e) => $e->created_at->format('Y-m-d'));

        $lessonMinutesByDay = StudentLessonProgress::forStudent($student->id)
            ->where('updated_at', '>=', $start)
            ->get(['watched_seconds', 'updated_at'])
            ->groupBy(fn ($p) => $p->updated_at->format('Y-m-d'))
            ->map(fn ($g) => (int) round($g->sum('watched_seconds') / 60));

        $examsByDay = ExamSubmission::forStudent($student->id)
            ->where('submitted_at', '>=', $start)
            ->whereNotNull('submitted_at')
            ->get(['submitted_at'])
            ->groupBy(fn ($s) => $s->submitted_at->format('Y-m-d'))
            ->map->count();

        $dayNamesAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

        $weeks = [];
        $week = [];
        $cursor = $start->copy();
        $activeDays = 0;
        for ($i = 0; $i < 364; $i++) {
            $key = $cursor->format('Y-m-d');
            $minutes = (int) ($lessonMinutesByDay[$key] ?? 0);
            $quizzes = (int) ($examsByDay[$key] ?? 0);
            $xp = (int) (isset($xpByDay[$key]) ? $xpByDay[$key]->sum('points') : 0);
            $score = $minutes + ($quizzes * 15) + (int) round($xp / 5);
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
            $total = \App\Models\Lesson::where('course_id', $courseId)
                ->where('status', \App\Enums\LessonStatus::PUBLISHED)
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
     * Retrieve privacy-preserving leaderboard for an accessible league.
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

        $participants = LeagueParticipant::query()
            ->where('course_league_id', $league->id)
            ->with('student:id,name')
            ->orderByDesc('xp_points')
            ->limit(100)
            ->get();

        $studentIds = $participants->pluck('student_id')->filter()->unique()->all();
        $profilesByStudent = ! empty($studentIds)
            ? StudentProfile::whereIn('student_id', $studentIds)->get()->keyBy('student_id')
            : collect();

        $leaderboard = [];
        $rank = 1;

        foreach ($participants as $p) {
            $isMe = (int) $p->student_id === (int) $student->id;
            /** @var StudentProfile|null $prof */
            $prof = $p->student_id ? $profilesByStudent->get($p->student_id) : null;
            $rawName = $p->student?->name ?: 'طالب متفوق';

            if (! $isMe && $prof && ! $prof->show_on_leaderboard) {
                $displayName = 'طالب متفوق (مخفي)';
            } elseif (! $isMe && $prof && $prof->display_name_mode === 'nickname' && ! empty($prof->nickname)) {
                $displayName = $prof->nickname;
            } elseif (! $isMe && $prof && $prof->display_name_mode === 'initials') {
                $parts = preg_split('/\s+/u', trim((string) $rawName)) ?: [];
                $first = $parts[0] ?? 'طالب';
                $lastInitial = isset($parts[1]) ? mb_substr($parts[1], 0, 1).'.' : '';
                $displayName = trim("{$first} {$lastInitial}");
            } else {
                $displayName = $rawName;
            }

            $leaderboard[] = [
                'rank' => $rank++,
                'displayName' => $displayName,
                'xpPoints' => (int) $p->xp_points,
                'streakDays' => (int) $p->streak_days,
                'isCurrentUser' => $isMe,
            ];
        }

        return [
            'leagueId' => $league->uuid,
            'titleAr' => $league->title_ar,
            'leaderboard' => $leaderboard,
        ];
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
