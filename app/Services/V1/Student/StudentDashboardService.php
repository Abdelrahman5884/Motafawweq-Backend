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
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\StudentStudySession;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StudentDashboardService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService,
        protected StudentExamTakingService $examService,
        protected StudentActivityService $activityService
    ) {}

    /**
     * Aggregated Student Dashboard: KPIs, daily streak & live platform minutes, 7-day activity,
     * subject mastery, continue-watching lesson, today's tasks, upcoming deadlines, and smart recommendation.
     */
    public function getDashboard(User $student, ?string $timezone = null): array
    {
        // 1. Record today's platform visit (updates consecutive daily streak & returns authoritative daily time)
        $activity = $this->activityService->recordHeartbeat($student, 0, $timezone, true);
        $resolvedTimezone = $activity['timezone'];

        $entitlements = $this->accessService->getEntitlements($student);
        $courseIds = $entitlements['course_ids'] ?? [];
        $teacherIds = $entitlements['teacher_ids'] ?? [];

        // 2. Load enrolled published courses with published lessons & teacher
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

        $totalWatchedSeconds = (int) $allProgressByLesson->sum('watched_seconds');

        // 3. Graded exam submissions (overall + per-course breakdown for subject filtering)
        $gradedSubmissions = ExamSubmission::forStudent($student->id)
            ->where('status', ExamSubmissionStatus::GRADED)
            ->with('exam:id,course_id')
            ->get(['id', 'exam_id', 'percentage', 'is_passed', 'is_full_mark']);

        $avgExamPercent = $gradedSubmissions->count() > 0
            ? round((float) $gradedSubmissions->avg('percentage'), 1)
            : 0.0;

        $fullMarkExamsCount = $gradedSubmissions
            ->filter(fn (ExamSubmission $s) => (bool) $s->is_full_mark || (float) $s->percentage >= 100.0)
            ->count();

        $examStatsByCourse = [];
        foreach ($gradedSubmissions as $sub) {
            $cId = $sub->exam?->course_id;
            if (! $cId) {
                continue;
            }
            if (! isset($examStatsByCourse[$cId])) {
                $examStatsByCourse[$cId] = ['count' => 0, 'fullMarks' => 0, 'sumPct' => 0.0];
            }
            $examStatsByCourse[$cId]['count']++;
            if ((bool) $sub->is_full_mark || (float) $sub->percentage >= 100.0) {
                $examStatsByCourse[$cId]['fullMarks']++;
            }
            $examStatsByCourse[$cId]['sumPct'] += (float) $sub->percentage;
        }

        // 4. Immutable XP total & accurate cross-DB batch rank (subquery count works identically in SQLite & MySQL)
        $totalXp = (int) StudentXpLedger::forStudent($student->id)->sum('points');
        $batchRank = null;
        if ($totalXp > 0) {
            $higherXpSubquery = StudentXpLedger::query()
                ->select('student_id')
                ->groupBy('student_id')
                ->havingRaw('SUM(points) > ?', [$totalXp]);

            $higherXpStudents = DB::query()
                ->fromSub($higherXpSubquery, 'higher_xp_students')
                ->count();

            $batchRank = $higherXpStudents + 1;
        }

        // 5. Subject Mastery, Completed Lessons Totals & Per-Course Continue Lesson
        $colors = ['#0284C7', '#10B981', '#F59E0B', '#6366F1', '#EC4899'];
        $subjectMastery = [];
        $continueLessonsByCourse = [];
        $primaryContinueLesson = null;
        $latestUpdatedAt = null;
        $enrolledCompletedLessons = 0;
        $totalEnrolledLessons = 0;

        foreach ($enrolledCourses as $idx => $course) {
            $lessons = $course->lessons->values();
            $totalL = $lessons->count();
            $totalEnrolledLessons += $totalL;
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

            $enrolledCompletedLessons += $doneCount;

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
            $courseExamStat = $examStatsByCourse[$course->id] ?? ['count' => 0, 'fullMarks' => 0, 'sumPct' => 0.0];

            $subjectMastery[] = [
                'id' => $course->uuid,
                'nameAr' => $subjectName,
                'nameEn' => $course->title_en ?: $subjectName,
                'pct' => $coursePct,
                'completedLessons' => $doneCount,
                'totalLessons' => $totalL,
                'examsCompletedCount' => $courseExamStat['count'],
                'fullMarkExamsCount' => $courseExamStat['fullMarks'],
                'avgExamPercentage' => $courseExamStat['count'] > 0
                    ? round($courseExamStat['sumPct'] / $courseExamStat['count'], 1)
                    : 0.0,
                'color' => $colors[$idx % count($colors)],
            ];

            if ($targetLesson) {
                $nextLessonObj = $lessons->get($targetLessonIndex);
                $durationSec = max(60, (int) ($targetLesson->duration_seconds ?: ($targetProgress?->duration_seconds ?: 1800)));
                $pausedSec = min($durationSec, (int) ($targetProgress?->max_position_seconds ?: ($targetProgress?->watched_seconds ?: 0)));
                $isLessonCompleted = (bool) ($targetProgress?->is_completed ?? false);
                $remainingMins = $isLessonCompleted
                    ? 0
                    : max(1, (int) ceil(max(0, $durationSec - $pausedSec) / 60));
                $progPct = $isLessonCompleted
                    ? 100
                    : (int) ($targetProgress?->progress_percent ?: round(($pausedSec / $durationSec) * 100));

                $formattedHero = [
                    'courseId' => $course->uuid,
                    'courseTitleAr' => $subjectName,
                    'subjectAr' => $subjectName,
                    'subjectEn' => $course->title_en ?: $subjectName,
                    'teacherAr' => $course->teacher?->name ?? '',
                    'teacherEn' => $course->teacher?->name ?? '',
                    'lessonId' => $targetLesson->uuid,
                    'lessonTitleAr' => $targetLesson->title_ar,
                    'titleAr' => $targetLesson->title_ar,
                    'titleEn' => $targetLesson->title_en ?: $targetLesson->title_ar,
                    'lessonNumber' => $targetLessonIndex,
                    'totalLessons' => max(1, $totalL),
                    'progressPercent' => min(100, max(0, $progPct)),
                    'isCompleted' => $isLessonCompleted,
                    'pausedMinute' => $this->formatSeconds($pausedSec),
                    'totalDuration' => $targetLesson->duration_label ?: $this->formatSeconds($durationSec),
                    'durationLabel' => $targetLesson->duration_label ?: $this->formatSeconds($durationSec),
                    'remainingMinutes' => $remainingMins,
                    'nextLessonTitleAr' => $nextLessonObj ? $nextLessonObj->title_ar : 'مراجعة وإتمام المقرر',
                    'nextLessonTitleEn' => $nextLessonObj ? ($nextLessonObj->title_en ?: $nextLessonObj->title_ar) : 'Course Review & Completion',
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

        // If student is not enrolled in published courses yet, fall back to raw completed progress count
        $completedLessons = $enrolledCourses->isNotEmpty()
            ? $enrolledCompletedLessons
            : $allProgressByLesson->where('is_completed', true)->count();
        $totalEnrolledLessons = max($completedLessons, $totalEnrolledLessons);

        // Fallback if student has in-progress lesson outside current enrolledCourses collection
        if (! $primaryContinueLesson) {
            $lastProgress = StudentLessonProgress::forStudent($student->id)
                ->where('is_completed', false)
                ->with(['lesson:id,uuid,title_ar,title_en,duration_label,duration_seconds', 'course:id,uuid,title_ar,title_en,teacher_id', 'course.teacher:id,name'])
                ->latest('updated_at')
                ->first();

            if ($lastProgress && $lastProgress->lesson) {
                $durationSec = max(60, (int) ($lastProgress->lesson->duration_seconds ?: ($lastProgress->duration_seconds ?: 1800)));
                $pausedSec = min($durationSec, (int) ($lastProgress->max_position_seconds ?: $lastProgress->watched_seconds));
                $primaryContinueLesson = [
                    'courseId' => $lastProgress->course?->uuid,
                    'courseTitleAr' => $lastProgress->course?->title_ar ?? '',
                    'subjectAr' => $lastProgress->course?->title_ar ?? '',
                    'subjectEn' => $lastProgress->course?->title_en ?: ($lastProgress->course?->title_ar ?? ''),
                    'teacherAr' => $lastProgress->course?->teacher?->name ?? '',
                    'teacherEn' => $lastProgress->course?->teacher?->name ?? '',
                    'lessonId' => $lastProgress->lesson->uuid,
                    'lessonTitleAr' => $lastProgress->lesson->title_ar,
                    'titleAr' => $lastProgress->lesson->title_ar,
                    'titleEn' => $lastProgress->lesson->title_en ?: $lastProgress->lesson->title_ar,
                    'lessonNumber' => 1,
                    'totalLessons' => max(1, $totalEnrolledLessons),
                    'progressPercent' => (int) $lastProgress->progress_percent,
                    'isCompleted' => false,
                    'pausedMinute' => $this->formatSeconds($pausedSec),
                    'totalDuration' => $lastProgress->lesson->duration_label ?: $this->formatSeconds($durationSec),
                    'durationLabel' => $lastProgress->lesson->duration_label ?: $this->formatSeconds($durationSec),
                    'remainingMinutes' => max(1, (int) ceil(max(0, $durationSec - $pausedSec) / 60)),
                    'nextLessonTitleAr' => $lastProgress->course?->title_ar ?? 'الدرس التالي',
                    'nextLessonTitleEn' => $lastProgress->course?->title_en ?: ($lastProgress->course?->title_ar ?? 'Next Lesson'),
                    'route' => '/student/lesson',
                ];
            }
        }

        // 6. Upcoming Active Exams (matching both teacher_ids & course_ids, sorted soonest due_date first)
        $hasAccessScope = ! empty($teacherIds) || ! empty($courseIds);

        $upcomingExams = $hasAccessScope
            ? Exam::query()
                ->where('status', ExamStatus::ACTIVE)
                ->where(function ($q) use ($teacherIds, $courseIds) {
                    if (! empty($teacherIds)) {
                        $q->whereIn('teacher_id', $teacherIds);
                    }
                    if (! empty($courseIds)) {
                        $q->orWhereIn('course_id', $courseIds);
                    }
                })
                ->whereDoesntHave('submissions', fn ($q) => $q->where('student_id', $student->id)
                    ->whereIn('status', [ExamSubmissionStatus::SUBMITTED, ExamSubmissionStatus::GRADED]))
                ->with('course:id,uuid,title_ar,title_en')
                ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('due_date', 'asc')
                ->latest('created_at')
                ->limit(6)
                ->get(['id', 'uuid', 'course_id', 'title_ar', 'title_en', 'course_title_ar', 'due_date', 'duration_minutes', 'full_mark'])
                ->map(fn (Exam $e) => [
                    'id' => $e->uuid,
                    'type' => 'exam',
                    'courseId' => $e->course?->uuid,
                    'titleAr' => $e->title_ar,
                    'titleEn' => $e->title_en ?: $e->title_ar,
                    'courseTitleAr' => $e->course_title_ar ?: ($e->course?->title_ar ?? ''),
                    'courseTitleEn' => $e->course?->title_en ?: ($e->course_title_ar ?: ($e->course?->title_ar ?? '')),
                    'dueDate' => $e->due_date?->format('Y-m-d'),
                    'durationMinutes' => (int) ($e->duration_minutes ?: 30),
                    'totalMarks' => (int) ($e->full_mark ?: 100),
                ])->values()->all()
            : [];

        // 7. Upcoming Pending Homeworks (matching both teacher_ids & course_ids, sorted soonest due_date first)
        $upcomingHomeworks = $hasAccessScope
            ? Homework::query()
                ->where(function ($q) use ($teacherIds, $courseIds) {
                    if (! empty($teacherIds)) {
                        $q->whereIn('teacher_id', $teacherIds);
                    }
                    if (! empty($courseIds)) {
                        $q->orWhereIn('course_id', $courseIds);
                    }
                })
                ->whereDoesntHave('submissions', fn ($q) => $q->where('student_id', $student->id))
                ->with('course:id,uuid,title_ar,title_en')
                ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END ASC')
                ->orderBy('due_date', 'asc')
                ->latest('created_at')
                ->limit(6)
                ->get(['id', 'uuid', 'course_id', 'title_ar', 'course_title_ar', 'due_date', 'max_grade'])
                ->map(fn (Homework $h) => [
                    'id' => $h->uuid,
                    'type' => 'homework',
                    'courseId' => $h->course?->uuid,
                    'titleAr' => $h->title_ar,
                    'titleEn' => $h->title_ar,
                    'courseTitleAr' => $h->course_title_ar ?: ($h->course?->title_ar ?? ''),
                    'courseTitleEn' => $h->course?->title_en ?: ($h->course_title_ar ?: ($h->course?->title_ar ?? '')),
                    'dueDate' => $h->due_date?->format('Y-m-d'),
                    'maxScore' => (int) ($h->max_grade ?: 20),
                ])->values()->all()
            : [];

        // 8. Today's Tasks (Personal Study Sessions for today + Lessons + Quizzes + Homeworks)
        $todayTasks = [];
        $todayDayKey = strtolower(Carbon::now($resolvedTimezone)->englishDayOfWeek);

        $todayStudySessions = StudentStudySession::forStudent($student->id)
            ->where('day_of_week', $todayDayKey)
            ->orderBy('start_time')
            ->get();

        foreach ($todayStudySessions as $session) {
            $durMins = (int) ($session->duration_minutes ?: 60);
            $timeWindow = trim("{$session->start_time} - {$session->end_time}");

            $todayTasks[] = [
                'id' => 'session-'.$session->uuid,
                'source' => 'study_session',
                'sessionUuid' => $session->uuid,
                'courseId' => null,
                'titleAr' => $session->title_ar,
                'titleEn' => $session->title_ar,
                'subjectAr' => $session->subject_ar,
                'subjectEn' => $session->subject_ar,
                'type' => 'lesson',
                'duration' => "{$durMins} دقيقة",
                'durationAr' => "{$durMins} دقيقة",
                'durationEn' => "{$durMins} min",
                'deadline' => $timeWindow !== '-' ? "الموعد: {$timeWindow}" : 'جلسة مذاكرة اليوم',
                'deadlineAr' => $timeWindow !== '-' ? "الموعد: {$timeWindow}" : 'جلسة مذاكرة اليوم',
                'deadlineEn' => $timeWindow !== '-' ? "Time: {$timeWindow}" : 'Today\'s Study Session',
                'priority' => 'normal',
                'status' => $session->is_completed ? 'completed' : 'pending',
                'isCompleted' => (bool) $session->is_completed,
                'actionRoute' => '/student/schedule',
            ];
        }

        foreach ($continueLessonsByCourse as $cUuid => $heroLesson) {
            $isDone = (bool) ($heroLesson['isCompleted'] ?? false);
            $pct = (int) ($heroLesson['progressPercent'] ?? 0);

            $todayTasks[] = [
                'id' => 'lesson-'.$heroLesson['lessonId'],
                'source' => 'lesson',
                'courseId' => $cUuid,
                'titleAr' => 'مذاكرة حصة: '.$heroLesson['titleAr'],
                'titleEn' => 'Study Lesson: '.$heroLesson['titleEn'],
                'subjectAr' => $heroLesson['subjectAr'],
                'subjectEn' => $heroLesson['subjectEn'],
                'type' => 'lesson',
                'duration' => $heroLesson['totalDuration'],
                'durationAr' => $heroLesson['totalDuration'],
                'durationEn' => $heroLesson['totalDuration'],
                'deadline' => $isDone
                    ? 'مكتملة 100%'
                    : ($pct > 0 ? "تم إنجاز {$pct}%" : 'حصة جاهزة للمذاكرة'),
                'deadlineAr' => $isDone
                    ? 'مكتملة 100%'
                    : ($pct > 0 ? "تم إنجاز {$pct}%" : 'حصة جاهزة للمذاكرة'),
                'deadlineEn' => $isDone
                    ? '100% Completed'
                    : ($pct > 0 ? "{$pct}% completed" : 'Ready to study'),
                'priority' => 'normal',
                'status' => $isDone ? 'completed' : 'pending',
                'isCompleted' => $isDone,
                'actionRoute' => "/student/lesson?courseId={$cUuid}&lessonId={$heroLesson['lessonId']}",
            ];
        }

        foreach ($upcomingExams as $examItem) {
            $todayTasks[] = [
                'id' => 'exam-'.$examItem['id'],
                'source' => 'exam',
                'courseId' => $examItem['courseId'] ?? null,
                'titleAr' => $examItem['titleAr'],
                'titleEn' => $examItem['titleEn'] ?: $examItem['titleAr'],
                'subjectAr' => $examItem['courseTitleAr'] ?: 'اختبار تقييمي',
                'subjectEn' => $examItem['courseTitleEn'] ?: 'Assessment Exam',
                'type' => 'quiz',
                'duration' => "{$examItem['durationMinutes']} دقيقة",
                'durationAr' => "{$examItem['durationMinutes']} دقيقة",
                'durationEn' => "{$examItem['durationMinutes']} min",
                'deadline' => $examItem['dueDate'] ? "آخر موعد: {$examItem['dueDate']}" : 'متاح الآن',
                'deadlineAr' => $examItem['dueDate'] ? "آخر موعد: {$examItem['dueDate']}" : 'متاح الآن',
                'deadlineEn' => $examItem['dueDate'] ? "Due: {$examItem['dueDate']}" : 'Available now',
                'priority' => 'urgent',
                'status' => 'pending',
                'isCompleted' => false,
                'actionRoute' => "/student/exam?examId={$examItem['id']}",
            ];
        }

        foreach ($upcomingHomeworks as $hwItem) {
            $todayTasks[] = [
                'id' => 'hw-'.$hwItem['id'],
                'source' => 'homework',
                'courseId' => $hwItem['courseId'] ?? null,
                'titleAr' => $hwItem['titleAr'],
                'titleEn' => $hwItem['titleEn'] ?: $hwItem['titleAr'],
                'subjectAr' => $hwItem['courseTitleAr'] ?: 'واجب تطبيقي',
                'subjectEn' => $hwItem['courseTitleEn'] ?: 'Homework',
                'type' => 'homework',
                'duration' => "{$hwItem['maxScore']} درجة",
                'durationAr' => "{$hwItem['maxScore']} درجة",
                'durationEn' => "{$hwItem['maxScore']} pts",
                'deadline' => $hwItem['dueDate'] ? "التسليم: {$hwItem['dueDate']}" : 'مطلوب للتسليم',
                'deadlineAr' => $hwItem['dueDate'] ? "التسليم: {$hwItem['dueDate']}" : 'مطلوب للتسليم',
                'deadlineEn' => $hwItem['dueDate'] ? "Due: {$hwItem['dueDate']}" : 'Pending submission',
                'priority' => 'urgent',
                'status' => 'pending',
                'isCompleted' => false,
                'actionRoute' => "/student/homework?homeworkId={$hwItem['id']}",
            ];
        }

        // 9. Accurate Last 7 Calendar Days Activity (real platform open minutes + completed lessons)
        $weeklyActivity = $this->activityService->getLast7DaysActivity($student, $resolvedTimezone);

        // 10. Smart Next Step Recommendation
        if ($primaryContinueLesson && ! ($primaryContinueLesson['isCompleted'] ?? false)) {
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
                'textEn' => "Your recommended next step: Take the active exam \"{$firstExam['titleEn']}\" to boost your league score.",
                'route' => "/student/exam?examId={$firstExam['id']}",
                'buttonAr' => 'بدء الاختبار الآن',
                'buttonEn' => 'Start Exam Now',
            ];
        } elseif (! empty($upcomingHomeworks)) {
            $firstHw = $upcomingHomeworks[0];
            $smartRecommendation = [
                'textAr' => "خطوتك التالية الموصى بها الآن: إنجاز وتسليم الواجب المطلوب «{$firstHw['titleAr']}» قبل انتهاء موعد التسليم.",
                'textEn' => "Your recommended next step: Complete and submit your pending homework \"{$firstHw['titleEn']}\".",
                'route' => "/student/homework?homeworkId={$firstHw['id']}",
                'buttonAr' => 'حل الواجب الآن',
                'buttonEn' => 'Solve Homework',
            ];
        } elseif ($primaryContinueLesson) {
            $smartRecommendation = [
                'textAr' => "أحسنت! أتممت دروسك الحالية — يمكنك مراجعة درس «{$primaryContinueLesson['titleAr']}» لتثبيت المعلومات.",
                'textEn' => "Great job! You've completed your current lessons — review \"{$primaryContinueLesson['titleEn']}\" to reinforce your mastery.",
                'route' => "/student/lesson?courseId={$primaryContinueLesson['courseId']}&lessonId={$primaryContinueLesson['lessonId']}",
                'buttonAr' => 'مراجعة الدرس',
                'buttonEn' => 'Review Lesson',
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
                'totalEnrolledLessons' => $totalEnrolledLessons,
                'watchedHours' => round($totalWatchedSeconds / 3600, 1),
                'studyMinutesToday' => $activity['activeMinutesToday'],
                'studySecondsToday' => $activity['activeSecondsToday'],
                'avgExamPercentage' => $avgExamPercent,
                'examsCompletedCount' => $gradedSubmissions->count(),
                'fullMarkExamsCount' => $fullMarkExamsCount,
                'streakDays' => $activity['streakDays'],
                'longestStreakDays' => $activity['longestStreakDays'],
                'isActiveToday' => $activity['isActiveToday'],
                'streakWasReset' => $activity['streakWasReset'],
                'previousStreakDays' => $activity['previousStreakDays'],
                'batchRank' => $batchRank,
                'totalXp' => $totalXp,
            ],
            'activity' => $activity,
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
            ->with('exam:id,uuid,course_id,title_ar,course_title_ar')
            ->latest('submitted_at')
            ->limit(30)
            ->get();

        $homeworkCount = HomeworkSubmission::forStudent($student->id)->count();
        $allProgress = StudentLessonProgress::forStudent($student->id)->get(['lesson_id', 'is_completed', 'watched_seconds']);
        $completedLessons = $allProgress->where('is_completed', true)->count();
        $totalWatchedSeconds = (int) $allProgress->sum('watched_seconds');

        $entitlements = $this->accessService->getEntitlements($student);
        $courseIds = $entitlements['course_ids'] ?? [];
        $enrolledCourses = ! empty($courseIds)
            ? Course::whereIn('id', $courseIds)
                ->where('status', CourseStatus::PUBLISHED)
                ->with(['lessons' => fn ($q) => $q->where('status', LessonStatus::PUBLISHED)->select('id', 'course_id')])
                ->get(['id', 'uuid', 'title_ar', 'title_en', 'subject_ar'])
            : collect();

        $progressByLesson = $allProgress->keyBy('lesson_id');
        $totalEnrolledLessons = 0;

        $examPctByCourse = [];
        foreach ($examSubmissions as $sub) {
            $cid = $sub->exam?->course_id;
            if (! $cid) {
                continue;
            }
            $examPctByCourse[$cid][] = (float) $sub->percentage;
        }

        $subjectAverages = [];
        foreach ($enrolledCourses as $course) {
            $lessonIds = $course->lessons->pluck('id');
            $totalL = $lessonIds->count();
            $totalEnrolledLessons += $totalL;
            $doneL = $lessonIds->filter(fn ($lid) => (bool) ($progressByLesson->get($lid)?->is_completed))->count();
            $examScores = $examPctByCourse[$course->id] ?? [];
            $avgPct = ! empty($examScores)
                ? round(array_sum($examScores) / count($examScores), 1)
                : ($totalL > 0 ? round(($doneL / $totalL) * 100, 1) : 0.0);

            $subjectAverages[] = [
                'courseId' => $course->uuid,
                'subject' => $course->subject_ar ?: $course->title_ar,
                'subjectAr' => $course->subject_ar ?: $course->title_ar,
                'subjectEn' => $course->title_en ?: ($course->subject_ar ?: $course->title_ar),
                'avg' => (int) round($avgPct),
                'avgPercent' => $avgPct,
                'examsCount' => count($examScores),
                'testsCount' => count($examScores),
                'completedLessons' => $doneL,
                'lessonsDone' => $doneL,
                'totalLessons' => $totalL,
                'lessonsTotal' => $totalL,
            ];
        }

        // If student took exams outside enrolled courses, still include those subjects cleanly
        if (empty($subjectAverages) && $examSubmissions->isNotEmpty()) {
            $grouped = $examSubmissions->groupBy(fn (ExamSubmission $s) => $s->exam?->course_title_ar ?: 'مادة عامة');
            foreach ($grouped as $subjName => $subs) {
                $avgPct = round((float) $subs->avg('percentage'), 1);
                $subjectAverages[] = [
                    'courseId' => null,
                    'subject' => (string) $subjName,
                    'subjectAr' => (string) $subjName,
                    'subjectEn' => (string) $subjName,
                    'avg' => (int) round($avgPct),
                    'avgPercent' => $avgPct,
                    'examsCount' => $subs->count(),
                    'testsCount' => $subs->count(),
                    'completedLessons' => 0,
                    'lessonsDone' => 0,
                    'totalLessons' => 0,
                    'lessonsTotal' => 0,
                ];
            }
        }

        // Derive actionable weak areas from real graded mistakes
        $mistakes = $this->examService->getMistakeBank($student);
        $weakAreas = collect($mistakes)
            ->groupBy(fn (array $m) => ($m['subjectAr'] ?: 'مراجعة عامة').'::'.($m['examTitleAr'] ?: 'امتحان'))
            ->take(6)
            ->map(function ($group) {
                $first = $group->first();
                $count = $group->count();
                $estMastery = max(25, 85 - ($count * 12));

                return [
                    'topicAr' => $first['examTitleAr'] ?: $first['questionAr'],
                    'subjectAr' => $first['subjectAr'] ?: 'مراجعة عامة',
                    'mistakesCount' => $count,
                    'mastery' => $estMastery,
                    'adviceAr' => $first['explanationAr']
                        ?: "لديك {$count} خطأ في هذا الموضوع — استخدم الذكاء الاصطناعي في مركز نقاط الضعف للحصول على تشخيص وخطة علاجية.",
                ];
            })
            ->values()
            ->all();

        $activity = $this->activityService->recordHeartbeat($student, 0, null, false);
        $studyHours = round(max($totalWatchedSeconds / 3600, ((int) ($activity['activeMinutesToday'] ?? 0)) / 60), 1);

        return [
            'completedLessonsCount' => $completedLessons,
            'totalEnrolledLessons' => max($completedLessons, $totalEnrolledLessons),
            'submittedHomeworksCount' => $homeworkCount,
            'gradedExamsCount' => $examSubmissions->count(),
            'studyHours' => $studyHours,
            'avgExamScorePercent' => $examSubmissions->count() > 0 ? round((float) $examSubmissions->avg('percentage'), 1) : 0.0,
            'subjectAverages' => $subjectAverages,
            'weakAreas' => $weakAreas,
            'recentExamPerformances' => $examSubmissions->take(12)->map(fn (ExamSubmission $s) => [
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
