<?php

namespace App\Services\V1\Student;

use App\Enums\CourseStatus;
use App\Enums\DirectPaymentStatus;
use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\LessonAttachmentType;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonChapter;
use App\Models\LessonTranscript;
use App\Models\StudentDirectPayment;
use App\Models\StudentLessonNote;
use App\Models\StudentLessonProgress;
use App\Models\StudentProfile;
use App\Models\StudentXpLedger;
use App\Models\TeacherPayoutAccount;
use App\Models\User;
use App\Services\BaseService;
use App\Support\MinistryCurriculumCatalog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class StudentCourseLessonService extends BaseService
{
    private const LESSON_COMPLETION_XP = 25;

    private const GRAPH_DEPTH_COLORS = [
        '#1588C7',
        '#06B6D4',
        '#7C3AED',
        '#10B981',
        '#F59E0B',
        '#EC4899',
    ];

    public function __construct(
        protected StudentAccessService $accessService
    ) {}

    /**
     * List published teacher courses scoped strictly to the student's current grade by default
     * (plus already enrolled/pending courses). When `$search` is provided, searches across ALL
     * grades while prioritizing enrolled, pending, and same-grade courses (zero N+1).
     */
    public function listCourses(User $student, ?string $search = null): array
    {
        $entitlements = $this->accessService->getEntitlements($student);
        $cleanSearch = mb_substr(trim((string) $search), 0, 120);
        $isSearching = $cleanSearch !== '';

        $rawStudentGrade = StudentProfile::where('student_id', $student->id)->value('grade_ar');
        $studentGrade = MinistryCurriculumCatalog::normalizeGrade($rawStudentGrade);

        $pendingCourseIds = StudentDirectPayment::query()
            ->where('student_id', $student->id)
            ->where('status', DirectPaymentStatus::PENDING)
            ->whereNotNull('course_id')
            ->pluck('course_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $allPublished = Course::query()
            ->where('status', CourseStatus::PUBLISHED)
            ->with(['teacher:id,name,avatar'])
            ->withCount(['lessons as published_lessons_count' => fn ($q) => $q->where('status', LessonStatus::PUBLISHED)])
            ->withSum(['lessons as published_duration_seconds' => fn ($q) => $q->where('status', LessonStatus::PUBLISHED)], 'duration_seconds')
            ->latest()
            ->get();

        if ($allPublished->isEmpty()) {
            return [];
        }

        // Filter BEFORE bulk progress/lesson/payout lookups to avoid loading data for hidden grades
        $courses = $allPublished->filter(function (Course $course) use (
            $entitlements,
            $pendingCourseIds,
            $studentGrade,
            $isSearching,
            $cleanSearch
        ) {
            $normalizedGrade = MinistryCurriculumCatalog::tryNormalizeGrade($course->grade_ar);

            if ($isSearching) {
                return MinistryCurriculumCatalog::matchesSearchQuery($cleanSearch, [
                    $course->title_ar,
                    $course->title_en,
                    $course->subject_ar,
                    $course->subject_en,
                    $course->grade_ar,
                    $normalizedGrade,
                    MinistryCurriculumCatalog::gradeSearchAliases($normalizedGrade),
                    $course->stage_name_ar,
                    $course->description_ar,
                    $course->teacher?->name,
                ]);
            }

            $cid = (int) $course->id;
            $isDirectlyEnrolled = in_array($cid, $entitlements['direct_course_ids'] ?? [], true);
            $isPending = in_array($cid, $pendingCourseIds, true);

            return $normalizedGrade === $studentGrade || $isDirectlyEnrolled || $isPending;
        })->values();

        if ($courses->isEmpty()) {
            return [];
        }

        $courseIds = $courses->pluck('id')->all();
        $teacherIds = $courses->pluck('teacher_id')->filter()->unique()->values()->all();

        $progressRecords = StudentLessonProgress::query()
            ->where('student_id', $student->id)
            ->whereIn('course_id', $courseIds)
            ->get(['course_id', 'lesson_id', 'is_completed', 'last_heartbeat_at']);

        $completedByCourse = [];
        $completedLessonIds = [];
        foreach ($progressRecords as $prog) {
            if ($prog->is_completed) {
                $cid = (int) $prog->course_id;
                $completedByCourse[$cid] = ($completedByCourse[$cid] ?? 0) + 1;
                $completedLessonIds[(int) $prog->lesson_id] = true;
            }
        }

        // Bulk-load published lesson UUIDs for enrolled courses to determine exact next resume lesson
        $enrolledCourseIds = array_values(array_intersect(
            array_map('intval', $courseIds),
            $entitlements['course_ids']
        ));

        $nextLessonByCourse = [];
        if (! empty($enrolledCourseIds)) {
            $enrolledLessons = Lesson::query()
                ->whereIn('course_id', $enrolledCourseIds)
                ->where('status', LessonStatus::PUBLISHED)
                ->orderBy('id')
                ->get(['id', 'uuid', 'course_id'])
                ->groupBy('course_id');

            foreach ($enrolledLessons as $cid => $lessonsForCourse) {
                $firstIncomplete = $lessonsForCourse->first(
                    fn (Lesson $l) => ! isset($completedLessonIds[(int) $l->id])
                );
                $chosen = $firstIncomplete ?: $lessonsForCourse->first();
                if ($chosen) {
                    $nextLessonByCourse[(int) $cid] = $chosen->uuid;
                }
            }
        }

        // Bulk-load active payout accounts per teacher for transparent enrollment checkout
        $payoutsByTeacher = ! empty($teacherIds)
            ? TeacherPayoutAccount::query()
                ->whereIn('teacher_id', $teacherIds)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->groupBy('teacher_id')
            : collect();

        $mapped = $courses->map(function (Course $course, int $idx) use (
            $completedByCourse,
            $entitlements,
            $pendingCourseIds,
            $nextLessonByCourse,
            $payoutsByTeacher,
            $studentGrade,
            $isSearching
        ) {
            $publishedLessons = (int) ($course->published_lessons_count ?? 0);
            $publishedDurationSec = max(0, (int) ($course->published_duration_seconds ?? 0));
            $completed = min($publishedLessons, (int) ($completedByCourse[(int) $course->id] ?? 0));
            $progressPercent = $publishedLessons > 0
                ? min(100, (int) round(($completed / $publishedLessons) * 100))
                : 0;
            $priceEgp = max(0.0, (float) ($course->price_egp ?? 0));
            $isEnrolled = in_array((int) $course->id, $entitlements['course_ids'], true);
            $isPending = ! $isEnrolled && in_array((int) $course->id, $pendingCourseIds, true);
            $normalizedGrade = MinistryCurriculumCatalog::tryNormalizeGrade($course->grade_ar);
            $isSameGrade = $normalizedGrade === $studentGrade;

            $teacherAccounts = ($payoutsByTeacher->get($course->teacher_id) ?? collect())
                ->map(fn (TeacherPayoutAccount $acc) => [
                    'id' => $acc->uuid,
                    'type' => $acc->type?->value ?? (string) $acc->type,
                    'labelAr' => $acc->label_ar ?: ($acc->type?->defaultLabelAr() ?? ''),
                    'labelEn' => $acc->label_en ?: ($acc->type?->defaultLabelEn() ?? ''),
                    'accountIdentifier' => (string) $acc->account_identifier,
                    'isDefault' => (bool) $acc->is_default,
                ])
                ->values()
                ->all();

            $enrollBucket = $isEnrolled ? 0 : ($isPending ? 1 : 2);
            $gradeBucket = $isSameGrade ? 0 : 1;
            $sortKey = $isSearching
                ? [$gradeBucket, $enrollBucket, $idx]
                : [$enrollBucket, $gradeBucket, $idx];

            return [
                '_sortKey' => $sortKey,
                'payload' => [
                    'id' => $course->uuid,
                    'uuid' => $course->uuid,
                    'titleAr' => $course->title_ar,
                    'titleEn' => $course->title_en ?: $course->title_ar,
                    'subjectAr' => $course->subject_ar ?: $course->grade_ar,
                    'subjectEn' => $course->subject_en ?: ($course->subject_ar ?: $course->grade_ar),
                    'stage' => $course->stage_name_ar ?: (string) ($course->stage_id ?? ''),
                    'gradeAr' => $normalizedGrade ?? ($course->grade_ar ?: ''),
                    'isSameGrade' => $isSameGrade,
                    'descriptionAr' => $course->description_ar ?: '',
                    'descriptionEn' => $course->description_en ?: ($course->description_ar ?: ''),
                    'coverUrl' => $course->cover_url ?: '',
                    'priceEgp' => $priceEgp,
                    'isEnrolled' => $isEnrolled,
                    'isPending' => $isPending,
                    'teacherName' => $course->teacher?->name ?? '',
                    'teacherAvatar' => $course->teacher?->avatar ?? '',
                    'hoursTotal' => $course->hours_total ?: '',
                    'publishedDurationSeconds' => $publishedDurationSec,
                    'lessonsCount' => $publishedLessons,
                    'completedLessons' => $completed,
                    'progressPercent' => $progressPercent,
                    'studentsCount' => max(0, (int) ($course->students_count ?? 0)),
                    'nextLessonId' => $isEnrolled ? ($nextLessonByCourse[(int) $course->id] ?? null) : null,
                    'payoutAccounts' => $teacherAccounts,
                ],
            ];
        })->all();

        usort($mapped, fn (array $a, array $b) => $a['_sortKey'] <=> $b['_sortKey']);

        return array_map(fn (array $row) => $row['payload'], $mapped);
    }

    /**
     * Get a single course and its published lessons with the student's progress per lesson.
     */
    public function getCourse(User $student, string $courseUuid): array
    {
        /** @var Course|null $course */
        $course = Course::where('uuid', $courseUuid)
            ->with([
                'teacher:id,name,avatar',
                'lessons' => fn ($q) => $q->where('status', LessonStatus::PUBLISHED)->orderBy('id'),
            ])
            ->first();

        if (! $course) {
            throw (new ModelNotFoundException)->setModel(Course::class, [$courseUuid]);
        }

        $this->accessService->assertCanAccess($student, $course, (int) $course->teacher_id, (int) $course->id);

        $progressByLesson = StudentLessonProgress::query()
            ->where('student_id', $student->id)
            ->where('course_id', $course->id)
            ->get()
            ->keyBy('lesson_id');

        $lessons = $course->lessons->map(function (Lesson $lesson) use ($progressByLesson) {
            /** @var StudentLessonProgress|null $prog */
            $prog = $progressByLesson->get($lesson->id);
            $durationSec = (int) ($lesson->duration_seconds ?: 1800);

            return [
                'id' => $lesson->uuid,
                'uuid' => $lesson->uuid,
                'titleAr' => $lesson->title_ar,
                'titleEn' => $lesson->title_en ?: $lesson->title_ar,
                'descAr' => $lesson->description_ar ?? '',
                'durationSeconds' => $durationSec,
                'durationLabel' => $lesson->duration_label ?: $this->formatSeconds($durationSec),
                'mediaMode' => $lesson->media_mode?->value ?? (string) $lesson->media_mode,
                'hasMedia' => ! empty($lesson->media_path) || ! empty($lesson->embed_url),
                'progressPercent' => (int) ($prog?->progress_percent ?? 0),
                'isCompleted' => (bool) ($prog?->is_completed ?? false),
                'isBookmarked' => (bool) ($prog?->is_bookmarked ?? false),
            ];
        })->values()->all();

        return [
            'id' => $course->uuid,
            'titleAr' => $course->title_ar,
            'titleEn' => $course->title_en ?: $course->title_ar,
            'subjectAr' => $course->subject_ar ?: $course->grade_ar,
            'gradeAr' => $course->grade_ar,
            'teacherName' => $course->teacher?->name ?? '',
            'teacherAvatar' => $course->teacher?->avatar ?? '',
            'lessons' => $lessons,
        ];
    }

    /**
     * Retrieve full lesson workspace for the student (media, chapters, transcript, concept graph,
     * attachments, notes, linked exams/review questions, course context, watermark).
     */
    public function getLesson(User $student, string $lessonUuid): array
    {
        /** @var Lesson|null $lesson */
        $lesson = Lesson::where('uuid', $lessonUuid)
            ->with([
                'course.teacher:id,name,avatar',
                'chapters',
                'transcriptSegments',
                'conceptGraph',
                'attachments',
            ])
            ->first();

        if (! $lesson || $lesson->status !== LessonStatus::PUBLISHED) {
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$lessonUuid]);
        }

        $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);

        $lessonDuration = max(10, (int) ($lesson->duration_seconds ?: 1800));

        $progress = StudentLessonProgress::firstOrCreate(
            [
                'student_id' => $student->id,
                'lesson_id' => $lesson->id,
            ],
            [
                'course_id' => $lesson->course_id,
                'duration_seconds' => $lessonDuration,
            ]
        );

        $notes = StudentLessonNote::forStudent($student->id)
            ->where('lesson_id', $lesson->id)
            ->orderBy('timestamp_seconds')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StudentLessonNote $n) => $this->formatNote($n))
            ->values()
            ->all();

        $mediaUrl = null;
        if (! empty($lesson->media_path)) {
            $mediaUrl = URL::temporarySignedRoute(
                'api.v1.student.lessons.media.stream',
                now()->addMinutes(120),
                ['uuid' => $lesson->uuid]
            );
        } elseif (! empty($lesson->embed_url)) {
            $mediaUrl = $lesson->embed_url;
        }

        $pdfAttachments = [];
        $whiteboardImages = [];
        foreach ($lesson->attachments as $att) {
            /** @var LessonAttachment $att */
            $inlineUrl = URL::temporarySignedRoute(
                'api.v1.student.lessons.attachments.stream',
                now()->addMinutes(120),
                ['uuid' => $lesson->uuid, 'attachmentUuid' => $att->uuid]
            );
            $downloadUrl = URL::temporarySignedRoute(
                'api.v1.student.lessons.attachments.stream',
                now()->addMinutes(120),
                ['uuid' => $lesson->uuid, 'attachmentUuid' => $att->uuid, 'download' => 1]
            );
            $sizeKb = max(1, (int) round(((int) $att->file_size_bytes) / 1024));
            $sizeLabel = $att->file_size_label ?: ($sizeKb >= 1024 ? round($sizeKb / 1024, 1).' MB' : "{$sizeKb} KB");
            $displayTitle = $att->title ?: ($att->original_file_name ?: 'ملف مرفق');

            if ($att->type === LessonAttachmentType::WHITEBOARD_IMAGE) {
                $whiteboardImages[] = [
                    'id' => $att->uuid,
                    'order' => (int) ($att->display_order ?: count($whiteboardImages) + 1),
                    'titleAr' => $displayTitle,
                    'caption' => $att->caption ?: '',
                    'size' => $sizeLabel,
                    'url' => $inlineUrl,
                    'downloadUrl' => $downloadUrl,
                ];
            } else {
                $pagesLabel = $att->pages_count ? "{$att->pages_count} صفحة" : 'PDF';
                $pdfAttachments[] = [
                    'id' => $att->uuid,
                    'titleAr' => $displayTitle,
                    'size' => $sizeLabel,
                    'pages' => $pagesLabel,
                    'type' => 'PDF',
                    'mimeType' => $att->mime_type ?: 'application/pdf',
                    'url' => $inlineUrl,
                    'downloadUrl' => $downloadUrl,
                ];
            }
        }

        // Chapters with accurate startSeconds, endSeconds & display order
        $chaptersCollection = $lesson->chapters->values();
        $chapters = $chaptersCollection->map(function (LessonChapter $ch, int $idx) use ($chaptersCollection, $lessonDuration) {
            $startSec = max(0, (int) $ch->start_seconds);
            /** @var LessonChapter|null $next */
            $next = $chaptersCollection->get($idx + 1);
            $endSec = $next
                ? max($startSec + 1, (int) $next->start_seconds)
                : max($startSec + 60, $lessonDuration);
            $chDuration = max(0, $endSec - $startSec);

            return [
                'id' => $ch->uuid,
                'order' => (int) ($ch->display_order ?: ($idx + 1)),
                'titleAr' => $ch->title_ar,
                'titleEn' => $ch->title_en ?: $ch->title_ar,
                'startSeconds' => $startSec,
                'endSeconds' => $endSec,
                'startTime' => $ch->timestamp_label ?: $this->formatSeconds($startSec),
                'durationSeconds' => $chDuration,
                'duration' => $this->formatSeconds($chDuration),
            ];
        })->all();

        // Transcript segments with computed endSeconds & real speaker
        $teacherFallbackName = $lesson->course?->teacher?->name ?? 'المعلم';
        $transcriptCollection = $lesson->transcriptSegments->values();
        $transcript = $transcriptCollection->map(function (LessonTranscript $seg, int $idx) use ($transcriptCollection, $lessonDuration, $teacherFallbackName) {
            $startSec = max(0, (int) $seg->start_seconds);
            /** @var LessonTranscript|null $next */
            $next = $transcriptCollection->get($idx + 1);
            $endSec = $next
                ? max($startSec + 1, (int) $next->start_seconds)
                : max($startSec + 30, $lessonDuration);

            return [
                'id' => $seg->uuid,
                'speaker' => $seg->speaker ?: $teacherFallbackName,
                'startSeconds' => $startSec,
                'endSeconds' => $endSec,
                'timestampLabel' => $seg->timestamp_label ?: $this->formatSeconds($startSec),
                'textAr' => $seg->text_ar,
                'textEn' => $seg->text_en ?: $seg->text_ar,
            ];
        })->all();

        // Concept Graph: flatten hierarchical tree_data into SVG nodes + edges
        $conceptGraph = $this->buildConceptGraphPayload($lesson->conceptGraph?->tree_data);

        // Linked course exams + security-safe review questions (ONLY from already-graded exams with show_results=true)
        [$relatedExams, $practiceQuestions] = $this->loadCourseExamsAndReviewQuestions($student, $lesson);

        $courseMeta = $lesson->course ? [
            'id' => $lesson->course->uuid,
            'titleAr' => $lesson->course->title_ar,
            'titleEn' => $lesson->course->title_en ?: $lesson->course->title_ar,
            'subjectAr' => $lesson->course->subject_ar ?: $lesson->course->grade_ar,
            'gradeAr' => $lesson->course->grade_ar,
            'teacherName' => $lesson->course->teacher?->name ?? '',
            'teacherAvatar' => $lesson->course->teacher?->avatar ?? '',
        ] : null;

        return [
            'id' => $lesson->uuid,
            'courseId' => $lesson->course?->uuid,
            'course' => $courseMeta,
            'titleAr' => $lesson->title_ar,
            'titleEn' => $lesson->title_en ?: $lesson->title_ar,
            'descAr' => $lesson->description_ar ?? '',
            'durationSeconds' => $lessonDuration,
            'durationLabel' => $lesson->duration_label ?: $this->formatSeconds($lessonDuration),
            'mediaMode' => $lesson->media_mode?->value ?? (string) $lesson->media_mode,
            'embedUrl' => $lesson->embed_url,
            'isYoutube' => (bool) $lesson->is_youtube,
            'mediaUrl' => $mediaUrl,
            'attachments' => $pdfAttachments,
            'whiteboardImages' => $whiteboardImages,
            'chapters' => $chapters,
            'transcript' => $transcript,
            'conceptGraph' => $conceptGraph,
            'relatedExams' => $relatedExams,
            'practiceQuestions' => $practiceQuestions,
            'progress' => [
                'watchedSeconds' => (int) $progress->watched_seconds,
                'maxPositionSeconds' => (int) $progress->max_position_seconds,
                'progressPercent' => (int) $progress->progress_percent,
                'isCompleted' => (bool) $progress->is_completed,
                'isBookmarked' => (bool) $progress->is_bookmarked,
            ],
            'notes' => $notes,
            'watermark' => [
                'studentCode' => $student->student_code ?: "STD-{$student->id}",
                'studentName' => $student->name,
            ],
        ];
    }

    /**
     * Record a lesson playback progress heartbeat with server-side anti-skip enforcement.
     * Caps watched_seconds increment by actual wall-clock elapsed time since the previous heartbeat.
     */
    public function recordProgressHeartbeat(User $student, string $lessonUuid, array $data): array
    {
        /** @var Lesson|null $lesson */
        $lesson = Lesson::where('uuid', $lessonUuid)->first();
        if (! $lesson || $lesson->status !== LessonStatus::PUBLISHED) {
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$lessonUuid]);
        }

        $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);

        return $this->transaction(function () use ($student, $lesson, $data) {
            /** @var StudentLessonProgress|null $progress */
            $progress = StudentLessonProgress::where('student_id', $student->id)
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->first();

            $now = Carbon::now();
            $fallbackDuration = max(10, (int) ($lesson->duration_seconds ?: 1800));
            $durationSeconds = max(10, min(14400, (int) ($data['duration_seconds'] ?? $progress?->duration_seconds ?? $fallbackDuration)));
            $reportedPosition = max(0, min($durationSeconds, (int) ($data['position_seconds'] ?? 0)));
            $reportedDelta = max(0, min(60, (int) ($data['watched_delta_seconds'] ?? 15)));

            if (! $progress) {
                $initialWatched = min($durationSeconds, min($reportedDelta, 30));
                $initialPercent = (int) min(100, round(($initialWatched / $durationSeconds) * 100));
                $initialCompleted = $initialPercent >= 90;

                $progress = StudentLessonProgress::create([
                    'student_id' => $student->id,
                    'lesson_id' => $lesson->id,
                    'course_id' => $lesson->course_id,
                    'watched_seconds' => $initialWatched,
                    'max_position_seconds' => min($durationSeconds, min($reportedPosition, 60)),
                    'duration_seconds' => $durationSeconds,
                    'progress_percent' => $initialCompleted ? max(90, $initialPercent) : $initialPercent,
                    'is_completed' => $initialCompleted,
                    'completed_at' => $initialCompleted ? $now : null,
                    'last_heartbeat_at' => $now,
                ]);
            } else {
                // Anti-skip: cap delta by real elapsed wall-clock seconds (up to 2x playback speed + 5s buffer)
                $elapsedWallClock = $progress->last_heartbeat_at
                    ? max(1, (int) abs($progress->last_heartbeat_at->diffInSeconds($now)))
                    : 15;
                $maxAllowedDelta = min(60, ($elapsedWallClock * 2) + 5);
                $safeDelta = min($reportedDelta, $maxAllowedDelta);

                $newWatched = min($durationSeconds, $progress->watched_seconds + $safeDelta);
                $maxPosJump = $progress->max_position_seconds + $maxAllowedDelta + 30;
                $newMaxPos = min($durationSeconds, max($progress->max_position_seconds, min($reportedPosition, $maxPosJump)));
                $percent = (int) min(100, round(($newWatched / $durationSeconds) * 100));
                $isCompleted = $progress->is_completed || $percent >= 90;

                $progress->update([
                    'watched_seconds' => $newWatched,
                    'max_position_seconds' => $newMaxPos,
                    'duration_seconds' => $durationSeconds,
                    'progress_percent' => $isCompleted ? max(90, $percent) : $percent,
                    'is_completed' => $isCompleted,
                    'completed_at' => ($isCompleted && ! $progress->completed_at) ? $now : $progress->completed_at,
                    'last_heartbeat_at' => $now,
                ]);
            }

            $xpAwarded = 0;
            if ($progress->is_completed) {
                $xpAwarded = $this->awardLessonCompletionXp($student, $lesson);
            }

            return [
                'watchedSeconds' => (int) $progress->watched_seconds,
                'maxPositionSeconds' => (int) $progress->max_position_seconds,
                'progressPercent' => (int) $progress->progress_percent,
                'isCompleted' => (bool) $progress->is_completed,
                'xpAwarded' => $xpAwarded,
            ];
        });
    }

    /**
     * Explicitly mark a lesson as completed or revert it back to in-progress.
     * XP is strictly awarded at most once via the immutable StudentXpLedger.
     */
    public function toggleComplete(User $student, string $lessonUuid, ?bool $targetCompleted = null): array
    {
        /** @var Lesson|null $lesson */
        $lesson = Lesson::where('uuid', $lessonUuid)->first();
        if (! $lesson || $lesson->status !== LessonStatus::PUBLISHED) {
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$lessonUuid]);
        }

        $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);

        return $this->transaction(function () use ($student, $lesson, $targetCompleted) {
            $lessonDuration = max(10, (int) ($lesson->duration_seconds ?: 1800));

            /** @var StudentLessonProgress|null $progress */
            $progress = StudentLessonProgress::where('student_id', $student->id)
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->first();

            if (! $progress) {
                $progress = StudentLessonProgress::create([
                    'student_id' => $student->id,
                    'lesson_id' => $lesson->id,
                    'course_id' => $lesson->course_id,
                    'duration_seconds' => $lessonDuration,
                ]);
            }

            $nextCompleted = $targetCompleted !== null ? $targetCompleted : ! $progress->is_completed;
            $duration = max(10, (int) ($progress->duration_seconds ?: $lessonDuration));
            $watchedRatioPct = (int) min(99, round(((int) $progress->watched_seconds / $duration) * 100));

            $progress->update([
                'is_completed' => $nextCompleted,
                'completed_at' => $nextCompleted ? ($progress->completed_at ?? now()) : null,
                'progress_percent' => $nextCompleted ? 100 : $watchedRatioPct,
            ]);

            $xpAwarded = 0;
            if ($nextCompleted) {
                $xpAwarded = $this->awardLessonCompletionXp($student, $lesson);
            }

            return [
                'lessonId' => $lesson->uuid,
                'watchedSeconds' => (int) $progress->watched_seconds,
                'maxPositionSeconds' => (int) $progress->max_position_seconds,
                'progressPercent' => (int) $progress->progress_percent,
                'isCompleted' => (bool) $progress->is_completed,
                'xpAwarded' => $xpAwarded,
            ];
        });
    }

    /**
     * Toggle bookmark on an accessible lesson.
     */
    public function toggleBookmark(User $student, string $lessonUuid): array
    {
        /** @var Lesson|null $lesson */
        $lesson = Lesson::where('uuid', $lessonUuid)->first();
        if (! $lesson || $lesson->status !== LessonStatus::PUBLISHED) {
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$lessonUuid]);
        }

        $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);

        $progress = StudentLessonProgress::firstOrCreate(
            ['student_id' => $student->id, 'lesson_id' => $lesson->id],
            [
                'course_id' => $lesson->course_id,
                'duration_seconds' => max(10, (int) ($lesson->duration_seconds ?: 1800)),
            ]
        );

        $progress->update(['is_bookmarked' => ! $progress->is_bookmarked]);

        return [
            'lessonId' => $lesson->uuid,
            'isBookmarked' => (bool) $progress->is_bookmarked,
        ];
    }

    /**
     * Create a private timestamped note on an accessible lesson.
     */
    public function createLessonNote(User $student, string $lessonUuid, array $data): array
    {
        /** @var Lesson|null $lesson */
        $lesson = Lesson::where('uuid', $lessonUuid)->first();
        if (! $lesson || $lesson->status !== LessonStatus::PUBLISHED) {
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$lessonUuid]);
        }

        $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);

        $content = trim(strip_tags((string) ($data['content'] ?? '')));
        if ($content === '') {
            throw ValidationException::withMessages(['content' => ['يرجى كتابة نص الملاحظة.']]);
        }

        $note = StudentLessonNote::create([
            'student_id' => $student->id,
            'lesson_id' => $lesson->id,
            'timestamp_seconds' => max(0, (int) ($data['timestamp_seconds'] ?? 0)),
            'content' => $content,
        ]);

        return $this->formatNote($note);
    }

    /**
     * Update a student's own lesson note (enforces BelongsToStudent ownership).
     */
    public function updateLessonNote(User $student, string $noteUuid, array $data): array
    {
        /** @var StudentLessonNote|null $note */
        $note = StudentLessonNote::where('uuid', $noteUuid)->first();
        if (! $note) {
            throw (new ModelNotFoundException)->setModel(StudentLessonNote::class, [$noteUuid]);
        }

        $note->assertOwnedByStudent($student);

        $content = trim(strip_tags((string) ($data['content'] ?? '')));
        if ($content === '') {
            throw ValidationException::withMessages(['content' => ['يرجى كتابة نص الملاحظة.']]);
        }

        $updatePayload = ['content' => $content];
        if (array_key_exists('timestamp_seconds', $data) && $data['timestamp_seconds'] !== null) {
            $updatePayload['timestamp_seconds'] = max(0, (int) $data['timestamp_seconds']);
        }

        $note->update($updatePayload);

        return $this->formatNote($note->fresh());
    }

    /**
     * Delete a student's own lesson note (enforces BelongsToStudent ownership).
     */
    public function deleteLessonNote(User $student, string $noteUuid): bool
    {
        /** @var StudentLessonNote|null $note */
        $note = StudentLessonNote::where('uuid', $noteUuid)->first();
        if (! $note) {
            throw (new ModelNotFoundException)->setModel(StudentLessonNote::class, [$noteUuid]);
        }

        $note->assertOwnedByStudent($student);
        $note->delete();

        return true;
    }

    /**
     * Award lesson completion XP once and return the newly awarded points (0 if already granted).
     */
    protected function awardLessonCompletionXp(User $student, Lesson $lesson): int
    {
        $entry = StudentXpLedger::awardOnce(
            $student->id,
            'lesson_completed',
            $lesson->id,
            self::LESSON_COMPLETION_XP,
            "إتمام محاضرة: {$lesson->title_ar}"
        );

        return ($entry && $entry->wasRecentlyCreated) ? (int) $entry->points : 0;
    }

    protected function formatNote(StudentLessonNote $note): array
    {
        return [
            'id' => $note->uuid,
            'timestampSeconds' => (int) $note->timestamp_seconds,
            'content' => $note->content,
            'createdAt' => $note->created_at?->toIso8601String(),
        ];
    }

    protected function formatSeconds(int $seconds): string
    {
        $safe = max(0, $seconds);
        $hours = intdiv($safe, 3600);
        $mins = intdiv($safe % 3600, 60);
        $secs = $safe % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $mins, $secs);
        }

        return sprintf('%02d:%02d', $mins, $secs);
    }

    /**
     * Convert stored hierarchical tree_data (or legacy nodes/edges array) into clean SVG graph nodes & edges.
     *
     * @param  array<string, mixed>|null  $treeData
     * @return array<string, mixed>|null
     */
    protected function buildConceptGraphPayload(?array $treeData): ?array
    {
        if (empty($treeData)) {
            return null;
        }

        // If already in explicit { nodes, edges } format, normalize coordinates cleanly
        if (isset($treeData['nodes']) && is_array($treeData['nodes'])) {
            $nodes = [];
            foreach ($treeData['nodes'] as $idx => $n) {
                if (! is_array($n)) {
                    continue;
                }
                $nodes[] = [
                    'id' => (string) ($n['id'] ?? 'gn-'.($idx + 1)),
                    'label' => (string) ($n['label'] ?? $n['labelAr'] ?? 'مفهوم'),
                    'labelEn' => (string) ($n['labelEn'] ?? $n['label'] ?? ''),
                    'tag' => (string) ($n['tag'] ?? ''),
                    'desc' => (string) ($n['desc'] ?? $n['descAr'] ?? $n['summaryAr'] ?? ''),
                    'startSec' => max(0, (int) ($n['startSec'] ?? $n['startSeconds'] ?? 0)),
                    'x' => (int) ($n['x'] ?? (140 + ($idx * 190))),
                    'y' => (int) ($n['y'] ?? 180),
                    'r' => (int) ($n['r'] ?? ($idx === 0 ? 48 : 38)),
                    'color' => (string) ($n['color'] ?? self::GRAPH_DEPTH_COLORS[$idx % count(self::GRAPH_DEPTH_COLORS)]),
                ];
            }

            return [
                'nodes' => $nodes,
                'edges' => is_array($treeData['edges'] ?? null) ? $treeData['edges'] : [],
                'treeData' => $treeData,
            ];
        }

        // Flatten hierarchical mind-map tree_data into depth-grouped columns with clean SVG coordinates
        $nodesByDepth = [];
        $edges = [];
        $seenIds = [];

        $walk = function (array $node, int $depth, ?string $parentId) use (&$walk, &$nodesByDepth, &$edges, &$seenIds): void {
            $rawId = (string) ($node['id'] ?? ('node-'.$depth.'-'.count($seenIds)));
            $id = isset($seenIds[$rawId]) ? $rawId.'-'.count($seenIds) : $rawId;
            $seenIds[$id] = true;

            $startSec = max(0, (int) ($node['startSeconds'] ?? $node['start_seconds'] ?? $node['startSec'] ?? 0));
            $labelAr = trim((string) ($node['labelAr'] ?? $node['label'] ?? 'مفهوم علمي'));
            $labelEn = trim((string) ($node['labelEn'] ?? $labelAr));
            $desc = trim((string) ($node['summaryAr'] ?? $node['descAr'] ?? $node['desc'] ?? ''));
            $tag = trim((string) ($node['timestamp'] ?? ($startSec > 0 ? $this->formatSeconds($startSec) : ($depth === 0 ? 'المحور الرئيسي' : 'مفهوم فرعي'))));

            $nodesByDepth[$depth][] = [
                'id' => $id,
                'label' => $labelAr,
                'labelEn' => $labelEn,
                'tag' => $tag,
                'desc' => $desc,
                'startSec' => $startSec,
                'depth' => $depth,
            ];

            if ($parentId !== null) {
                $edges[] = [
                    'from' => $parentId,
                    'to' => $id,
                ];
            }

            if (! empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) {
                    if (is_array($child)) {
                        $walk($child, $depth + 1, $id);
                    }
                }
            }
        };

        $walk($treeData, 0, null);

        $flatNodes = [];
        $depthCount = max(1, count($nodesByDepth));
        $canvasWidth = max(920, $depthCount * 280);
        $canvasHeight = 360;

        foreach ($nodesByDepth as $depth => $group) {
            $count = count($group);
            $x = $depthCount === 1
                ? (int) round($canvasWidth / 2)
                : (int) round(130 + ($depth * (($canvasWidth - 260) / max(1, $depthCount - 1))));

            foreach ($group as $idx => $item) {
                $y = $count === 1
                    ? (int) round($canvasHeight / 2)
                    : (int) round(72 + ($idx * (($canvasHeight - 144) / max(1, $count - 1))));

                $flatNodes[] = [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'labelEn' => $item['labelEn'],
                    'tag' => $item['tag'],
                    'desc' => $item['desc'],
                    'startSec' => $item['startSec'],
                    'x' => $x,
                    'y' => $y,
                    'r' => $depth === 0 ? 50 : ($depth === 1 ? 42 : 36),
                    'color' => self::GRAPH_DEPTH_COLORS[$depth % count(self::GRAPH_DEPTH_COLORS)],
                ];
            }
        }

        return [
            'nodes' => $flatNodes,
            'edges' => $edges,
            'canvasWidth' => $canvasWidth,
            'canvasHeight' => $canvasHeight,
            'treeData' => $treeData,
        ];
    }

    /**
     * Load linked course exams for navigation and security-safe review questions
     * ONLY from exams this student has already completed & graded (when show_results is enabled).
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    protected function loadCourseExamsAndReviewQuestions(User $student, Lesson $lesson): array
    {
        if (! $lesson->course_id) {
            return [[], []];
        }

        $exams = Exam::query()
            ->where('course_id', $lesson->course_id)
            ->whereIn('status', [ExamStatus::ACTIVE, ExamStatus::CLOSED])
            ->latest()
            ->limit(6)
            ->get();

        if ($exams->isEmpty()) {
            return [[], []];
        }

        $submissions = ExamSubmission::query()
            ->where('student_id', $student->id)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->get()
            ->keyBy('exam_id');

        $relatedExams = [];
        $reviewableExamIds = [];

        foreach ($exams as $exam) {
            /** @var ExamSubmission|null $sub */
            $sub = $submissions->get($exam->id);
            $subStatus = $sub?->status instanceof ExamSubmissionStatus
                ? $sub->status->value
                : ($sub?->status ?? 'not_started');

            $canShowResults = (bool) $exam->show_results && $subStatus === ExamSubmissionStatus::GRADED->value;
            if ($canShowResults) {
                $reviewableExamIds[] = $exam->id;
            }

            $relatedExams[] = [
                'id' => $exam->uuid,
                'titleAr' => $exam->title_ar,
                'durationMinutes' => (int) $exam->duration_minutes,
                'questionsCount' => (int) $exam->questions_count,
                'fullMark' => (int) $exam->full_mark,
                'status' => $exam->status instanceof ExamStatus ? $exam->status->value : (string) $exam->status,
                'submissionStatus' => $subStatus,
                'percentage' => $canShowResults ? (float) $sub->percentage : null,
                'isPassed' => $canShowResults ? (bool) $sub->is_passed : null,
            ];
        }

        if (empty($reviewableExamIds)) {
            return [$relatedExams, []];
        }

        $examTitles = $exams->pluck('title_ar', 'id');

        $questions = ExamQuestion::query()
            ->whereIn('exam_id', $reviewableExamIds)
            ->orderBy('exam_id')
            ->orderBy('question_number')
            ->limit(25)
            ->get()
            ->map(function (ExamQuestion $q) use ($examTitles) {
                $difficultyMap = [
                    'easy' => 'سهل',
                    'medium' => 'متوسط',
                    'hard' => 'صعب',
                ];
                $rawDiff = strtolower((string) ($q->difficulty ?? 'medium'));
                $diffLabel = $difficultyMap[$rawDiff] ?? ($q->difficulty ?: 'متوسط');

                $answerText = $q->model_answer_ar ?: '';
                $qType = $q->type instanceof ExamQuestionType ? $q->type : null;
                if ($qType === ExamQuestionType::MCQ && is_array($q->options_ar) && $q->correct_index !== null) {
                    $answerText = (string) ($q->options_ar[$q->correct_index] ?? $answerText);
                } elseif ($qType === ExamQuestionType::TRUE_FALSE && $q->correct_bool !== null) {
                    $answerText = $q->correct_bool ? 'صواب (True)' : 'خطأ (False)';
                }

                if ($q->explanation_ar) {
                    $answerText = trim($answerText !== '' ? "{$answerText} — {$q->explanation_ar}" : $q->explanation_ar);
                }

                return [
                    'id' => $q->uuid,
                    'diff' => $diffLabel,
                    'src' => (string) ($examTitles[$q->exam_id] ?? 'تدريب المقرر'),
                    'q' => $q->question_ar,
                    'options' => is_array($q->options_ar) ? array_values($q->options_ar) : [],
                    'a' => $answerText !== '' ? $answerText : 'راجع نموذج الإجابة مع المعلم.',
                ];
            })
            ->values()
            ->all();

        return [$relatedExams, $questions];
    }
}
