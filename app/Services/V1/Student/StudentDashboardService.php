<?php

namespace App\Services\V1\Student;

use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\HomeworkSubmission;
use App\Models\StudentLessonProgress;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;

class StudentDashboardService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService,
        protected StudentExamTakingService $examService
    ) {}

    /**
     * Aggregated Student Dashboard: KPIs, continue-watching lesson, and upcoming deadlines.
     */
    public function getDashboard(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);

        $completedLessons = StudentLessonProgress::forStudent($student->id)
            ->where('is_completed', true)
            ->count();

        $totalWatchedSeconds = (int) StudentLessonProgress::forStudent($student->id)
            ->sum('watched_seconds');

        $gradedSubmissions = ExamSubmission::forStudent($student->id)
            ->where('status', ExamSubmissionStatus::GRADED)
            ->get(['percentage', 'is_passed']);

        $avgExamPercent = $gradedSubmissions->count() > 0
            ? round((float) $gradedSubmissions->avg('percentage'), 1)
            : 0.0;

        $totalXp = (int) StudentXpLedger::forStudent($student->id)->sum('points');

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

        return [
            'kpis' => [
                'enrolledGroupsCount' => count($entitlements['group_ids']),
                'accessibleCourses' => count($entitlements['course_ids']),
                'completedLessons' => $completedLessons,
                'watchedHours' => round($totalWatchedSeconds / 3600, 1),
                'avgExamPercentage' => $avgExamPercent,
                'examsCompletedCount' => $gradedSubmissions->count(),
                'totalXp' => $totalXp,
            ],
            'continueLesson' => $lastProgress ? [
                'lessonId' => $lastProgress->lesson?->uuid,
                'lessonTitleAr' => $lastProgress->lesson?->title_ar,
                'courseId' => $lastProgress->course?->uuid,
                'courseTitleAr' => $lastProgress->course?->title_ar,
                'progressPercent' => (int) $lastProgress->progress_percent,
            ] : null,
            'upcomingDeadlines' => $upcomingExams,
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
     * Student Revision Hub: Bookmarked lessons and Mistake Bank.
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

        return [
            'bookmarkedLessons' => $bookmarks,
            'mistakeBank' => $this->examService->getMistakeBank($student),
        ];
    }
}
