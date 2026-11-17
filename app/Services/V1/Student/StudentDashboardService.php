<?php

namespace App\Services\V1\Student;

use App\Enums\CourseStatus;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\StudentStudySession;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;

class StudentDashboardService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService,
        protected StudentExamTakingService $examService
    ) {}

    /**
     * Aggregated Student Dashboard: KPIs, weekly activity, subject mastery, continue-watching lesson, upcoming deadlines, and smart recommendation.
     */
    public function getDashboard(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);

        $completedLessons = StudentLessonProgress::forStudent($student->id)
            ->where('is_completed', true)
            ->count();

        $totalEnrolledLessons = ! empty($entitlements['course_ids'])
            ? Lesson::query()
                ->whereIn('course_id', $entitlements['course_ids'])
                ->where('status', LessonStatus::PUBLISHED)
                ->count()
            : $completedLessons;

        $totalWatchedSeconds = (int) StudentLessonProgress::forStudent($student->id)
            ->sum('watched_seconds');

        $todayWatchedSeconds = (int) StudentLessonProgress::forStudent($student->id)
            ->whereDate('updated_at', Carbon::today())
            ->sum('watched_seconds');

        $todayStudySessionMinutes = (int) StudentStudySession::forStudent($student->id)
            ->where('is_completed', true)
            ->whereDate('updated_at', Carbon::today())
            ->sum('duration_minutes');

        $studyMinutesToday = (int) round($todayWatchedSeconds / 60) + $todayStudySessionMinutes;

        $gradedSubmissions = ExamSubmission::forStudent($student->id)
            ->where('status', ExamSubmissionStatus::GRADED)
            ->get(['percentage', 'is_passed']);

        $avgExamPercent = $gradedSubmissions->count() > 0
            ? round((float) $gradedSubmissions->avg('percentage'), 1)
            : 0.0;

        $fullMarkExamsCount = $gradedSubmissions->filter(fn ($s) => (float) $s->percentage >= 100.0)->count();

        $totalXp = (int) StudentXpLedger::forStudent($student->id)->sum('points');

        // Streak calculation from LeagueParticipant or recent activity days
        $maxLeagueStreak = (int) LeagueParticipant::where('student_id', $student->id)->max('streak_days');
        $activeDaysCount = StudentLessonProgress::forStudent($student->id)
            ->where('updated_at', '>=', Carbon::now()->subDays(30))
            ->selectRaw('DATE(updated_at) as d')
            ->distinct()
            ->count();
        $streakDays = max($maxLeagueStreak, $activeDaysCount);

        // Batch Rank calculation based on XP ledger across students
        $higherXpStudents = StudentXpLedger::query()
            ->selectRaw('student_id, SUM(points) as total_xp')
            ->groupBy('student_id')
            ->havingRaw('SUM(points) > ?', [$totalXp])
            ->count();
        $batchRank = $totalXp > 0 ? ($higherXpStudents + 1) : null;

        $lastProgress = StudentLessonProgress::forStudent($student->id)
            ->where('is_completed', false)
            ->with(['lesson:id,uuid,title_ar,duration_label', 'course:id,uuid,title_ar'])
            ->latest('updated_at')
            ->first();

        $upcomingExams = ! empty($entitlements['teacher_ids'])
            ? Exam::query()
                ->whereIn('teacher_id', $entitlements['teacher_ids'])
                ->where('status', ExamStatus::ACTIVE)
                ->latest()
                ->limit(5)
                ->get(['uuid', 'title_ar', 'course_title_ar', 'due_date', 'duration_minutes'])
                ->map(fn (Exam $e) => [
                    'id' => $e->uuid,
                    'type' => 'exam',
                    'titleAr' => $e->title_ar,
                    'courseTitleAr' => $e->course_title_ar,
                    'dueDate' => $e->due_date?->format('Y-m-d'),
                    'durationMinutes' => (int) $e->duration_minutes,
                ])->values()->all()
            : [];

        // Weekly Activity (7 days: Sat -> Fri)
        $daysMeta = [
            6 => ['dayAr' => 'السبت', 'dayEn' => 'Sat', 'key' => 'saturday'],
            0 => ['dayAr' => 'الأحد', 'dayEn' => 'Sun', 'key' => 'sunday'],
            1 => ['dayAr' => 'الإثنين', 'dayEn' => 'Mon', 'key' => 'monday'],
            2 => ['dayAr' => 'الثلاثاء', 'dayEn' => 'Tue', 'key' => 'tuesday'],
            3 => ['dayAr' => 'الأربعاء', 'dayEn' => 'Wed', 'key' => 'wednesday'],
            4 => ['dayAr' => 'الخميس', 'dayEn' => 'Thu', 'key' => 'thursday'],
            5 => ['dayAr' => 'الجمعة', 'dayEn' => 'Fri', 'key' => 'friday'],
        ];
        $todayDow = Carbon::now()->dayOfWeek;
        $progressLast7Days = StudentLessonProgress::forStudent($student->id)
            ->where('updated_at', '>=', Carbon::now()->subDays(7))
            ->get(['watched_seconds', 'is_completed', 'updated_at']);
        $studySessionsAll = StudentStudySession::forStudent($student->id)->get(['day_of_week', 'duration_minutes', 'is_completed']);

        $weeklyActivity = [];
        foreach ($daysMeta as $dow => $meta) {
            $dayProgress = $progressLast7Days->filter(fn ($p) => $p->updated_at && $p->updated_at->dayOfWeek === $dow);
            $watchedMins = (int) round($dayProgress->sum('watched_seconds') / 60);
            $lessonsDone = $dayProgress->where('is_completed', true)->count();

            $plannedMins = (int) $studySessionsAll
                ->filter(fn ($s) => ($s->day_of_week?->value ?? (string) $s->day_of_week) === $meta['key'] && $s->is_completed)
                ->sum('duration_minutes');

            $weeklyActivity[] = [
                'dayAr' => $meta['dayAr'],
                'dayEn' => $meta['dayEn'],
                'minutes' => min(100, $watchedMins + $plannedMins),
                'lessons' => $lessonsDone,
                'isToday' => $dow === $todayDow,
            ];
        }

        // Subject Mastery per enrolled course
        $colors = ['#0284C7', '#10B981', '#F59E0B', '#6366F1', '#EC4899'];
        $subjectMastery = [];
        if (! empty($entitlements['course_ids'])) {
            $courses = Course::whereIn('id', $entitlements['course_ids'])
                ->where('status', CourseStatus::PUBLISHED)
                ->withCount(['lessons as published_lessons_count' => fn ($q) => $q->where('status', LessonStatus::PUBLISHED)])
                ->limit(4)
                ->get();

            foreach ($courses as $idx => $course) {
                $doneCount = StudentLessonProgress::forStudent($student->id)
                    ->where('course_id', $course->id)
                    ->where('is_completed', true)
                    ->count();
                $totalL = max(1, (int) $course->published_lessons_count);
                $pct = min(100, (int) round(($doneCount / $totalL) * 100));
                $subjectMastery[] = [
                    'id' => $course->uuid,
                    'nameAr' => $course->title_ar,
                    'nameEn' => $course->title_en ?? $course->title_ar,
                    'pct' => $pct,
                    'color' => $colors[$idx % count($colors)],
                ];
            }
        }

        // Smart Next Step Recommendation
        $smartRecommendation = null;
        if ($lastProgress && $lastProgress->lesson) {
            $smartRecommendation = [
                'textAr' => "خطوتك التالية الموصى بها الآن: استكمال مذاكرة درس «{$lastProgress->lesson->title_ar}» ضمن مقرر «{$lastProgress->course?->title_ar}» (نسبة إنجازك الحالية {$lastProgress->progress_percent}%).",
                'textEn' => "Your recommended next step: Continue studying \"{$lastProgress->lesson->title_ar}\" ({$lastProgress->progress_percent}% completed).",
                'route' => "/student/lesson?lessonId={$lastProgress->lesson->uuid}",
                'buttonAr' => 'متابعة الدرس الآن',
                'buttonEn' => 'Continue Lesson',
            ];
        } elseif (! empty($upcomingExams)) {
            $firstExam = $upcomingExams[0];
            $smartRecommendation = [
                'textAr' => "خطوتك التالية الموصى بها الآن: الاستعداد وحل الاختبار المتاح «{$firstExam['titleAr']}» لرفع نقاطك في الدوري.",
                'textEn' => "Your recommended next step: Take the active exam \"{$firstExam['titleAr']}\" to boost your league score.",
                'route' => '/student/exam',
                'buttonAr' => 'بدء الاختبار الآن',
                'buttonEn' => 'Start Exam Now',
            ];
        } else {
            $smartRecommendation = [
                'textAr' => 'استكشف المقررات الدراسية المتاحة أو انضم إلى مجموعة دراسية بكود المعلم لبدء خطة مذاكرتك الذكية.',
                'textEn' => 'Explore available courses or join a study group using your teacher code to start your smart study plan.',
                'route' => '/student/courses',
                'buttonAr' => 'استعراض المقررات',
                'buttonEn' => 'Browse Courses',
            ];
        }

        return [
            'kpis' => [
                'enrolledGroupsCount' => count($entitlements['group_ids']),
                'accessibleCourses' => count($entitlements['course_ids']),
                'completedLessons' => $completedLessons,
                'totalEnrolledLessons' => max($completedLessons, $totalEnrolledLessons),
                'watchedHours' => round($totalWatchedSeconds / 3600, 1),
                'studyMinutesToday' => $studyMinutesToday,
                'avgExamPercentage' => $avgExamPercent,
                'examsCompletedCount' => $gradedSubmissions->count(),
                'fullMarkExamsCount' => $fullMarkExamsCount,
                'streakDays' => $streakDays,
                'longestStreakDays' => $streakDays,
                'batchRank' => $batchRank,
                'totalXp' => $totalXp,
            ],
            'weeklyActivity' => $weeklyActivity,
            'subjectMastery' => $subjectMastery,
            'continueLesson' => $lastProgress ? [
                'lessonId' => $lastProgress->lesson?->uuid,
                'lessonTitleAr' => $lastProgress->lesson?->title_ar,
                'courseId' => $lastProgress->course?->uuid,
                'courseTitleAr' => $lastProgress->course?->title_ar,
                'progressPercent' => (int) $lastProgress->progress_percent,
                'durationLabel' => $lastProgress->lesson?->duration_label ?: '30:00',
            ] : null,
            'upcomingDeadlines' => $upcomingExams,
            'smartRecommendation' => $smartRecommendation,
        ];
    }

    /**
     * Student Analytics & Performance Breakdown.
     */
    public function getAnalytics(User $student): array
    {
        $examSubmissions = ExamSubmission::forStudent($student->id)
            ->where('status', ExamSubmissionStatus::GRADED)
            ->with('exam:id,uuid,title_ar,course_title_ar')
            ->latest('submitted_at')
            ->limit(20)
            ->get();

        $homeworkCount = HomeworkSubmission::forStudent($student->id)->count();
        $completedLessons = StudentLessonProgress::forStudent($student->id)->where('is_completed', true)->count();

        return [
            'completedLessonsCount' => $completedLessons,
            'submittedHomeworksCount' => $homeworkCount,
            'gradedExamsCount' => $examSubmissions->count(),
            'avgExamScorePercent' => $examSubmissions->count() > 0 ? round((float) $examSubmissions->avg('percentage'), 1) : 0.0,
            'recentExamPerformances' => $examSubmissions->map(fn (ExamSubmission $s) => [
                'id' => $s->uuid,
                'examTitleAr' => $s->exam?->title_ar,
                'courseTitleAr' => $s->exam?->course_title_ar,
                'percentage' => (float) $s->percentage,
                'isPassed' => (bool) $s->is_passed,
                'submittedAt' => $s->submitted_at?->format('Y-m-d'),
            ])->values()->all(),
        ];
    }

    /**
     * Student Revision Hub: Bookmarked lessons, Mistake Bank, and Pre-Exam Checklist.
     */
    public function getRevisionHub(User $student): array
    {
        $bookmarks = StudentLessonProgress::forStudent($student->id)
            ->where('is_bookmarked', true)
            ->with(['lesson:id,uuid,title_ar,duration_label', 'course:id,uuid,title_ar'])
            ->latest('updated_at')
            ->get()
            ->map(fn (StudentLessonProgress $p) => [
                'lessonId' => $p->lesson?->uuid,
                'lessonTitleAr' => $p->lesson?->title_ar,
                'courseTitleAr' => $p->course?->title_ar,
                'durationLabel' => $p->lesson?->duration_label,
                'progressPercent' => (int) $p->progress_percent,
            ])->values()->all();

        $mistakes = $this->examService->getMistakeBank($student);

        $checklist = [];
        foreach (array_slice($bookmarks, 0, 3) as $idx => $bm) {
            $checklist[] = [
                'id' => 'chk-bm-'.$idx,
                'textAr' => "مراجعة الدرس المحفوظ: {$bm['lessonTitleAr']} ({$bm['courseTitleAr']})",
                'done' => $bm['progressPercent'] >= 90,
            ];
        }
        if (count($mistakes) > 0) {
            $checklist[] = [
                'id' => 'chk-mistakes',
                'textAr' => 'إعادة حل الأسئلة الخاطئة في بنك الأخطاء ('.count($mistakes).' سؤال)',
                'done' => false,
            ];
        }

        $entitlements = $this->accessService->getEntitlements($student);
        if (! empty($entitlements['teacher_ids'])) {
            $pendingHwCount = Homework::whereIn('teacher_id', $entitlements['teacher_ids'])
                ->whereDoesntHave('submissions', fn ($q) => $q->where('student_id', $student->id))
                ->count();
            if ($pendingHwCount > 0) {
                $checklist[] = [
                    'id' => 'chk-hw',
                    'textAr' => "إنجاز وتسليم الواجبات المطلوبة ({$pendingHwCount} واجب)",
                    'done' => false,
                ];
            }
        }

        return [
            'bookmarkedLessons' => $bookmarks,
            'mistakeBank' => $mistakes,
            'preExamChecklist' => $checklist,
        ];
    }
}
