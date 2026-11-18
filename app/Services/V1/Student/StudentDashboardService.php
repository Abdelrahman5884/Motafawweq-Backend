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
     * Aggregated Student Dashboard: KPIs, weekly activity, subject mastery, continue-watching lesson, today's tasks, upcoming deadlines, and smart recommendation.
     */
    public function getDashboard(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);
        $courseIds = $entitlements['course_ids'] ?? [];
        $teacherIds = $entitlements['teacher_ids'] ?? [];

        $completedLessons = StudentLessonProgress::forStudent($student->id)
            ->where('is_completed', true)
            ->count();

        $totalEnrolledLessons = ! empty($courseIds)
            ? Lesson::query()
                ->whereIn('course_id', $courseIds)
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

        // Load enrolled published courses with lessons & teacher for accurate mastery + continueLesson
        $enrolledCourses = ! empty($courseIds)
            ? Course::whereIn('id', $courseIds)
                ->where('status', CourseStatus::PUBLISHED)
                ->with([
                    'teacher:id,name',
                    'lessons' => fn ($q) => $q->where('status', LessonStatus::PUBLISHED)->orderBy('id'),
                ])
                ->get()
            : collect();

        $allProgressByLesson = StudentLessonProgress::forStudent($student->id)
            ->get()
            ->keyBy('lesson_id');

        // Subject Mastery & Per-Course Continue Lesson
        $colors = ['#0284C7', '#10B981', '#F59E0B', '#6366F1', '#EC4899'];
        $subjectMastery = [];
        $continueLessonsByCourse = [];
        $primaryContinueLesson = null;
        $latestUpdatedAt = null;

        foreach ($enrolledCourses as $idx => $course) {
            $lessons = $course->lessons;
            $totalL = $lessons->count();
            $progressSum = 0;
            $doneCount = 0;

            $targetLesson = null;
            $targetLessonIndex = 1;
            $targetProgress = null;
            $courseLatestUpdate = null;

            foreach ($lessons as $lIdx => $lesson) {
                /** @var StudentLessonProgress|null $prog */
                $prog = $allProgressByLesson->get($lesson->id);
                $pct = $prog ? (int) $prog->progress_percent : 0;
                if ($prog?->is_completed) {
                    $pct = 100;
                    $doneCount++;
                }
                $progressSum += $pct;

                // Prefer the most recently updated in-progress lesson
                if ($prog && ! $prog->is_completed && ($prog->watched_seconds > 0 || $prog->max_position_seconds > 0)) {
                    if (! $courseLatestUpdate || ($prog->updated_at && $prog->updated_at->gt($courseLatestUpdate))) {
                        $courseLatestUpdate = $prog->updated_at;
                        $targetLesson = $lesson;
                        $targetLessonIndex = $lIdx + 1;
                        $targetProgress = $prog;
                    }
                }
            }

            // If no mid-progress lesson in this course, pick the first uncompleted lesson (or first lesson)
            if (! $targetLesson && $totalL > 0) {
                foreach ($lessons as $lIdx => $lesson) {
                    $prog = $allProgressByLesson->get($lesson->id);
                    if (! $prog || ! $prog->is_completed) {
                        $targetLesson = $lesson;
                        $targetLessonIndex = $lIdx + 1;
                        $targetProgress = $prog;
                        break;
                    }
                }
                if (! $targetLesson) {
                    $targetLesson = $lessons->first();
                    $targetLessonIndex = 1;
                    $targetProgress = $allProgressByLesson->get($targetLesson->id);
                }
            }

            $coursePct = $totalL > 0 ? min(100, (int) round($progressSum / $totalL)) : 0;
            $subjectName = $course->title_ar ?: $course->subject_ar ?: $course->grade_ar;

            $subjectMastery[] = [
                'id' => $course->uuid,
                'nameAr' => $subjectName,
                'nameEn' => $course->title_en ?? $subjectName,
                'pct' => $coursePct,
                'completedLessons' => $doneCount,
                'totalLessons' => $totalL,
                'color' => $colors[$idx % count($colors)],
            ];

            if ($targetLesson) {
                $nextLessonObj = $lessons->get($targetLessonIndex); // 0-based index of (targetLessonIndex + 1) is $targetLessonIndex
                $durationSec = max(60, (int) ($targetLesson->duration_seconds ?: ($targetProgress?->duration_seconds ?: 1800)));
                $pausedSec = min($durationSec, (int) ($targetProgress?->max_position_seconds ?: ($targetProgress?->watched_seconds ?: 0)));
                $remainingMins = max(1, (int) ceil(max(0, $durationSec - $pausedSec) / 60));
                $progPct = $targetProgress?->is_completed
                    ? 100
                    : (int) ($targetProgress?->progress_percent ?: round(($pausedSec / $durationSec) * 100));

                $formattedHero = [
                    'courseId' => $course->uuid,
                    'courseTitleAr' => $subjectName,
                    'subjectAr' => $subjectName,
                    'subjectEn' => $course->title_en ?? $subjectName,
                    'teacherAr' => $course->teacher?->name ?? '',
                    'teacherEn' => $course->teacher?->name ?? '',
                    'lessonId' => $targetLesson->uuid,
                    'lessonTitleAr' => $targetLesson->title_ar,
                    'titleAr' => $targetLesson->title_ar,
                    'titleEn' => $targetLesson->title_ar,
                    'lessonNumber' => $targetLessonIndex,
                    'totalLessons' => max(1, $totalL),
                    'progressPercent' => min(100, max(0, $progPct)),
                    'pausedMinute' => $this->formatSeconds($pausedSec),
                    'totalDuration' => $targetLesson->duration_label ?: $this->formatSeconds($durationSec),
                    'durationLabel' => $targetLesson->duration_label ?: $this->formatSeconds($durationSec),
                    'remainingMinutes' => $remainingMins,
                    'nextLessonTitleAr' => $nextLessonObj ? $nextLessonObj->title_ar : 'مراجعة وإتمام المقرر',
                    'nextLessonTitleEn' => $nextLessonObj ? $nextLessonObj->title_ar : 'Course Review & Completion',
                    'route' => '/student/lesson',
                ];

                $continueLessonsByCourse[$course->uuid] = $formattedHero;

                if (! $primaryContinueLesson || ($courseLatestUpdate && (! $latestUpdatedAt || $courseLatestUpdate->gt($latestUpdatedAt)))) {
                    $primaryContinueLesson = $formattedHero;
                    if ($courseLatestUpdate) {
                        $latestUpdatedAt = $courseLatestUpdate;
                    }
                }
            }
        }

        // Fallback if student has progress record outside enrolledCourses collection
        if (! $primaryContinueLesson) {
            $lastProgress = StudentLessonProgress::forStudent($student->id)
                ->where('is_completed', false)
                ->with(['lesson:id,uuid,title_ar,duration_label,duration_seconds', 'course:id,uuid,title_ar,teacher_id', 'course.teacher:id,name'])
                ->latest('updated_at')
                ->first();

            if ($lastProgress && $lastProgress->lesson) {
                $durationSec = max(60, (int) ($lastProgress->lesson->duration_seconds ?: ($lastProgress->duration_seconds ?: 1800)));
                $pausedSec = min($durationSec, (int) ($lastProgress->max_position_seconds ?: $lastProgress->watched_seconds));
                $primaryContinueLesson = [
                    'courseId' => $lastProgress->course?->uuid,
                    'courseTitleAr' => $lastProgress->course?->title_ar ?? '',
                    'subjectAr' => $lastProgress->course?->title_ar ?? '',
                    'subjectEn' => $lastProgress->course?->title_ar ?? '',
                    'teacherAr' => $lastProgress->course?->teacher?->name ?? '',
                    'teacherEn' => $lastProgress->course?->teacher?->name ?? '',
                    'lessonId' => $lastProgress->lesson->uuid,
                    'lessonTitleAr' => $lastProgress->lesson->title_ar,
                    'titleAr' => $lastProgress->lesson->title_ar,
                    'titleEn' => $lastProgress->lesson->title_ar,
                    'lessonNumber' => 1,
                    'totalLessons' => max(1, $totalEnrolledLessons),
                    'progressPercent' => (int) $lastProgress->progress_percent,
                    'pausedMinute' => $this->formatSeconds($pausedSec),
                    'totalDuration' => $lastProgress->lesson->duration_label ?: $this->formatSeconds($durationSec),
                    'durationLabel' => $lastProgress->lesson->duration_label ?: $this->formatSeconds($durationSec),
                    'remainingMinutes' => max(1, (int) ceil(max(0, $durationSec - $pausedSec) / 60)),
                    'nextLessonTitleAr' => $lastProgress->course?->title_ar ?? 'الدرس التالي',
                    'nextLessonTitleEn' => $lastProgress->course?->title_ar ?? 'Next Lesson',
                    'route' => '/student/lesson',
                ];
            }
        }

        // Upcoming Active Exams (excluding already submitted/graded by this student)
        $upcomingExams = ! empty($teacherIds)
            ? Exam::query()
                ->whereIn('teacher_id', $teacherIds)
                ->where('status', ExamStatus::ACTIVE)
                ->whereDoesntHave('submissions', fn ($q) => $q->where('student_id', $student->id)
                    ->whereIn('status', [ExamSubmissionStatus::SUBMITTED, ExamSubmissionStatus::GRADED]))
                ->with('course:id,uuid,title_ar')
                ->latest()
                ->limit(6)
                ->get(['id', 'uuid', 'course_id', 'title_ar', 'course_title_ar', 'due_date', 'duration_minutes', 'full_mark'])
                ->map(fn (Exam $e) => [
                    'id' => $e->uuid,
                    'type' => 'exam',
                    'courseId' => $e->course?->uuid,
                    'titleAr' => $e->title_ar,
                    'courseTitleAr' => $e->course_title_ar ?: ($e->course?->title_ar ?? ''),
                    'dueDate' => $e->due_date?->format('Y-m-d'),
                    'durationMinutes' => (int) ($e->duration_minutes ?: 30),
                    'totalMarks' => (int) ($e->full_mark ?: 100),
                ])->values()->all()
            : [];

        // Upcoming Pending Homeworks (excluding already submitted by this student)
        $upcomingHomeworks = ! empty($teacherIds)
            ? Homework::query()
                ->whereIn('teacher_id', $teacherIds)
                ->whereDoesntHave('submissions', fn ($q) => $q->where('student_id', $student->id))
                ->with('course:id,uuid,title_ar')
                ->latest()
                ->limit(6)
                ->get(['id', 'uuid', 'course_id', 'title_ar', 'course_title_ar', 'due_date', 'max_grade'])
                ->map(fn (Homework $h) => [
                    'id' => $h->uuid,
                    'type' => 'homework',
                    'courseId' => $h->course?->uuid,
                    'titleAr' => $h->title_ar,
                    'courseTitleAr' => $h->course_title_ar ?: ($h->course?->title_ar ?? ''),
                    'dueDate' => $h->due_date?->format('Y-m-d'),
                    'maxScore' => (int) ($h->max_grade ?: 20),
                ])->values()->all()
            : [];

        // Build Today's Tasks (Lessons + Quizzes + Homeworks)
        $todayTasks = [];
        foreach ($continueLessonsByCourse as $cUuid => $heroLesson) {
            $todayTasks[] = [
                'id' => 'lesson-'.$heroLesson['lessonId'],
                'courseId' => $cUuid,
                'titleAr' => 'مذاكرة حصة: '.$heroLesson['titleAr'],
                'titleEn' => 'Study Lesson: '.$heroLesson['titleEn'],
                'subjectAr' => $heroLesson['subjectAr'],
                'type' => 'lesson',
                'duration' => $heroLesson['totalDuration'],
                'deadline' => $heroLesson['progressPercent'] > 0
                    ? "تم إنجاز {$heroLesson['progressPercent']}%"
                    : 'حصة جاهزة للمذاكرة',
                'priority' => 'normal',
                'status' => 'pending',
                'isCompleted' => false,
                'actionRoute' => "/student/lesson?courseId={$cUuid}&lessonId={$heroLesson['lessonId']}",
            ];
        }

        foreach ($upcomingExams as $examItem) {
            $todayTasks[] = [
                'id' => 'exam-'.$examItem['id'],
                'courseId' => $examItem['courseId'] ?? null,
                'titleAr' => $examItem['titleAr'],
                'titleEn' => $examItem['titleAr'],
                'subjectAr' => $examItem['courseTitleAr'] ?: 'اختبار تقييمي',
                'type' => 'quiz',
                'duration' => "{$examItem['durationMinutes']} دقيقة",
                'deadline' => $examItem['dueDate'] ? "آخر موعد: {$examItem['dueDate']}" : 'متاح الآن',
                'priority' => 'urgent',
                'status' => 'pending',
                'isCompleted' => false,
                'actionRoute' => '/student/exam',
            ];
        }

        foreach ($upcomingHomeworks as $hwItem) {
            $todayTasks[] = [
                'id' => 'hw-'.$hwItem['id'],
                'courseId' => $hwItem['courseId'] ?? null,
                'titleAr' => $hwItem['titleAr'],
                'titleEn' => $hwItem['titleAr'],
                'subjectAr' => $hwItem['courseTitleAr'] ?: 'واجب تطبيقي',
                'type' => 'homework',
                'duration' => "{$hwItem['maxScore']} درجة",
                'deadline' => $hwItem['dueDate'] ? "التسليم: {$hwItem['dueDate']}" : 'مطلوب للتسليم',
                'priority' => 'urgent',
                'status' => 'pending',
                'isCompleted' => false,
                'actionRoute' => '/student/homework',
            ];
        }

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
        $studySessionsRecent = StudentStudySession::forStudent($student->id)
            ->where('is_completed', true)
            ->where('updated_at', '>=', Carbon::now()->subDays(7))
            ->get(['day_of_week', 'duration_minutes']);

        $weeklyActivity = [];
        foreach ($daysMeta as $dow => $meta) {
            $dayProgress = $progressLast7Days->filter(fn ($p) => $p->updated_at && $p->updated_at->dayOfWeek === $dow);
            $watchedMins = (int) round($dayProgress->sum('watched_seconds') / 60);
            $lessonsDone = $dayProgress->where('is_completed', true)->count();

            $plannedMins = (int) $studySessionsRecent
                ->filter(fn ($s) => ($s->day_of_week?->value ?? (string) $s->day_of_week) === $meta['key'])
                ->sum('duration_minutes');

            $weeklyActivity[] = [
                'dayAr' => $meta['dayAr'],
                'dayEn' => $meta['dayEn'],
                'minutes' => $watchedMins + $plannedMins,
                'lessons' => $lessonsDone,
                'isToday' => $dow === $todayDow,
            ];
        }

        // Smart Next Step Recommendation
        if ($primaryContinueLesson) {
            $smartRecommendation = [
                'textAr' => "خطوتك التالية الموصى بها الآن: استكمال مذاكرة درس «{$primaryContinueLesson['titleAr']}» ضمن مقرر «{$primaryContinueLesson['subjectAr']}» (نسبة إنجازك الحالية {$primaryContinueLesson['progressPercent']}%).",
                'textEn' => "Your recommended next step: Continue studying \"{$primaryContinueLesson['titleEn']}\" ({$primaryContinueLesson['progressPercent']}% completed).",
                'route' => "/student/lesson?courseId={$primaryContinueLesson['courseId']}&lessonId={$primaryContinueLesson['lessonId']}",
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
        } elseif (! empty($upcomingHomeworks)) {
            $firstHw = $upcomingHomeworks[0];
            $smartRecommendation = [
                'textAr' => "خطوتك التالية الموصى بها الآن: إنجاز وتسليم الواجب المطلوب «{$firstHw['titleAr']}» قبل انتهاء موعد التسليم.",
                'textEn' => "Your recommended next step: Complete and submit your pending homework \"{$firstHw['titleAr']}\".",
                'route' => '/student/homework',
                'buttonAr' => 'حل الواجب الآن',
                'buttonEn' => 'Solve Homework',
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
                'accessibleCourses' => count($courseIds),
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
            'continueLesson' => $primaryContinueLesson,
            'continueLessonsByCourse' => $continueLessonsByCourse,
            'todayTasks' => $todayTasks,
            'upcomingDeadlines' => $upcomingExams,
            'upcomingHomeworks' => $upcomingHomeworks,
            'smartRecommendation' => $smartRecommendation,
        ];
    }

    private function formatSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d', $mins, $secs);
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
