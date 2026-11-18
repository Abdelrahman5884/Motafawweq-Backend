<?php

namespace App\Services\V1\Student;

use App\Enums\CourseStatus;
use App\Enums\LessonAttachmentType;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\StudentLessonNote;
use App\Models\StudentLessonProgress;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class StudentCourseLessonService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService
    ) {}

    /**
     * List all published courses across teachers with the student's enrollment and progress status.
     */
    public function listCourses(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);

        $courses = Course::query()
            ->where('status', CourseStatus::PUBLISHED)
            ->with(['teacher:id,name,avatar'])
            ->withCount(['lessons as published_lessons_count' => fn ($q) => $q->where('status', LessonStatus::PUBLISHED)])
            ->latest()
            ->get();

        if ($courses->isEmpty()) {
            return [];
        }

        $completedByCourse = StudentLessonProgress::query()
            ->where('student_id', $student->id)
            ->where('is_completed', true)
            ->whereIn('course_id', $courses->pluck('id'))
            ->selectRaw('course_id, COUNT(*) as completed_count')
            ->groupBy('course_id')
            ->pluck('completed_count', 'course_id');

        return $courses->map(function (Course $course) use ($completedByCourse, $entitlements) {
            $publishedLessons = (int) $course->published_lessons_count;
            $totalLessons = max($publishedLessons, (int) $course->lessons_count);
            $completed = (int) ($completedByCourse[$course->id] ?? 0);
            $progressPercent = $publishedLessons > 0 ? min(100, (int) round(($completed / $publishedLessons) * 100)) : 0;
            $priceEgp = (float) ($course->price_egp ?? 0);
            $isEnrolled = in_array((int) $course->id, $entitlements['course_ids'], true) || $priceEgp <= 0;

            return [
                'id' => $course->uuid,
                'uuid' => $course->uuid,
                'titleAr' => $course->title_ar,
                'titleEn' => $course->title_en ?? $course->title_ar,
                'subjectAr' => $course->subject_ar ?: $course->grade_ar,
                'stage' => $course->stage_name_ar ?: ($course->stage?->value ?? ''),
                'gradeAr' => $course->grade_ar,
                'descriptionAr' => $course->description_ar,
                'priceEgp' => $priceEgp,
                'isEnrolled' => $isEnrolled,
                'teacherName' => $course->teacher?->name,
                'teacherAvatar' => $course->teacher?->avatar,
                'hoursTotal' => $course->hours_total,
                'lessonsCount' => $totalLessons,
                'completedLessons' => $completed,
                'progressPercent' => $progressPercent,
            ];
        })->values()->all();
    }

    /**
     * Get a single course and its published lessons with the student's progress per lesson.
     */
    public function getCourse(User $student, string $courseUuid): array
    {
        /** @var Course|null $course */
        $course = Course::where('uuid', $courseUuid)
            ->with(['teacher:id,name', 'lessons' => fn ($q) => $q->where('status', LessonStatus::PUBLISHED)->orderBy('sort_order')])
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

            return [
                'id' => $lesson->uuid,
                'uuid' => $lesson->uuid,
                'titleAr' => $lesson->title_ar,
                'durationLabel' => $lesson->duration_label,
                'mediaMode' => $lesson->media_mode?->value ?? (string) $lesson->media_mode,
                'progressPercent' => (int) ($prog?->progress_percent ?? 0),
                'isCompleted' => (bool) ($prog?->is_completed ?? false),
                'isBookmarked' => (bool) ($prog?->is_bookmarked ?? false),
            ];
        })->values()->all();

        return [
            'id' => $course->uuid,
            'titleAr' => $course->title_ar,
            'gradeAr' => $course->grade_ar,
            'teacherName' => $course->teacher?->name,
            'lessons' => $lessons,
        ];
    }

    /**
     * Retrieve full lesson workspace for the student (chapters, transcript, concept graph, notes, watermark).
     */
    public function getLesson(User $student, string $lessonUuid): array
    {
        /** @var Lesson|null $lesson */
        $lesson = Lesson::where('uuid', $lessonUuid)
            ->with(['course', 'chapters', 'transcriptSegments', 'conceptGraph', 'attachments'])
            ->first();

        if (! $lesson || $lesson->status !== LessonStatus::PUBLISHED) {
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$lessonUuid]);
        }

        $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);

        $progress = StudentLessonProgress::firstOrCreate(
            [
                'student_id' => $student->id,
                'lesson_id' => $lesson->id,
            ],
            [
                'course_id' => $lesson->course_id,
                'duration_seconds' => 1800,
            ]
        );

        $notes = StudentLessonNote::forStudent($student->id)
            ->where('lesson_id', $lesson->id)
            ->orderBy('timestamp_seconds')
            ->get()
            ->map(fn (StudentLessonNote $n) => [
                'id' => $n->uuid,
                'timestampSeconds' => (int) $n->timestamp_seconds,
                'content' => $n->content,
                'createdAt' => $n->created_at?->toIso8601String(),
            ])->values()->all();

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
            $signedUrl = URL::temporarySignedRoute(
                'api.v1.student.lessons.attachments.stream',
                now()->addMinutes(120),
                ['uuid' => $lesson->uuid, 'attachmentUuid' => $att->uuid]
            );
            $sizeKb = (int) round(((int) $att->file_size_bytes) / 1024);
            $sizeLabel = $sizeKb >= 1024 ? round($sizeKb / 1024, 1).' MB' : "{$sizeKb} KB";

            if ($att->type === LessonAttachmentType::WHITEBOARD_IMAGE) {
                $whiteboardImages[] = [
                    'id' => $att->uuid,
                    'order' => (int) ($att->display_order ?: count($whiteboardImages) + 1),
                    'titleAr' => $att->title_ar ?: $att->original_filename,
                    'caption' => $att->caption_ar ?: '',
                    'size' => $sizeLabel,
                    'url' => $signedUrl,
                ];
            } else {
                $pdfAttachments[] = [
                    'id' => $att->uuid,
                    'titleAr' => $att->title_ar ?: $att->original_filename,
                    'size' => $sizeLabel,
                    'pages' => 'PDF',
                    'type' => 'PDF',
                    'url' => $signedUrl,
                ];
            }
        }

        return [
            'id' => $lesson->uuid,
            'courseId' => $lesson->course?->uuid,
            'titleAr' => $lesson->title_ar,
            'descAr' => $lesson->description_ar ?? '',
            'durationSeconds' => (int) ($lesson->duration_seconds ?: 1800),
            'durationLabel' => $lesson->duration_label ?: '30:00',
            'mediaMode' => $lesson->media_mode?->value ?? (string) $lesson->media_mode,
            'embedUrl' => $lesson->embed_url,
            'isYoutube' => (bool) $lesson->is_youtube,
            'mediaUrl' => $mediaUrl,
            'attachments' => $pdfAttachments,
            'whiteboardImages' => $whiteboardImages,
            'chapters' => $lesson->chapters->map(fn ($c) => [
                'id' => $c->uuid,
                'titleAr' => $c->title_ar,
                'startTime' => $c->start_time,
                'duration' => $c->duration_label,
            ])->values()->all(),
            'transcript' => $lesson->transcriptSegments->map(fn ($t) => [
                'id' => $t->uuid,
                'startSeconds' => (int) $t->start_seconds,
                'endSeconds' => (int) $t->end_seconds,
                'textAr' => $t->text_ar,
            ])->values()->all(),
            'conceptGraph' => $lesson->conceptGraph ? [
                'nodes' => $lesson->conceptGraph->nodes ?? [],
                'edges' => $lesson->conceptGraph->edges ?? [],
            ] : null,
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
            /** @var StudentLessonProgress $progress */
            $progress = StudentLessonProgress::where('student_id', $student->id)
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->first();

            $now = Carbon::now();
            $durationSeconds = max(60, min(14400, (int) ($data['duration_seconds'] ?? $progress?->duration_seconds ?? 1800)));
            $reportedPosition = max(0, min($durationSeconds, (int) ($data['position_seconds'] ?? 0)));
            $reportedDelta = max(0, min(60, (int) ($data['watched_delta_seconds'] ?? 15)));

            if (! $progress) {
                $progress = StudentLessonProgress::create([
                    'student_id' => $student->id,
                    'lesson_id' => $lesson->id,
                    'course_id' => $lesson->course_id,
                    'watched_seconds' => min($reportedDelta, 30),
                    'max_position_seconds' => min($reportedPosition, 60),
                    'duration_seconds' => $durationSeconds,
                    'progress_percent' => (int) min(100, round((min($reportedDelta, 30) / $durationSeconds) * 100)),
                    'last_heartbeat_at' => $now,
                ]);
            } else {
                // Anti-skip: cap delta by real elapsed wall-clock seconds (up to 2x playback speed + 5s buffer)
                $elapsedWallClock = $progress->last_heartbeat_at
                    ? max(1, (int) $progress->last_heartbeat_at->diffInSeconds($now))
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

            if ($progress->is_completed) {
                StudentXpLedger::awardOnce(
                    $student->id,
                    'lesson_completed',
                    $lesson->id,
                    25,
                    "إتمام محاضرة: {$lesson->title_ar}"
                );
            }

            return [
                'watchedSeconds' => (int) $progress->watched_seconds,
                'maxPositionSeconds' => (int) $progress->max_position_seconds,
                'progressPercent' => (int) $progress->progress_percent,
                'isCompleted' => (bool) $progress->is_completed,
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
            ['course_id' => $lesson->course_id]
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

        return [
            'id' => $note->uuid,
            'timestampSeconds' => (int) $note->timestamp_seconds,
            'content' => $note->content,
            'createdAt' => $note->created_at?->toIso8601String(),
        ];
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
}
