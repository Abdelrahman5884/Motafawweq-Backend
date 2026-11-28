<?php

namespace App\Services\V1\Student;

use App\Enums\CourseStatus;
use App\Enums\LessonStatus;
use App\Models\AiProcessingJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\BaseService;
use App\Services\V1\AI\AiMicroserviceClient;
use App\Services\V1\AI\LessonSpeechToTextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StudentAiService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService,
        protected StudentExamTakingService $examService,
        protected AiMicroserviceClient $aiClient
    ) {}

    /**
     * List accessible published course lessons for AI pickers (Smart Lecture "From My Lessons" & AI Quiz Hub).
     *
     * @return list<array<string, mixed>>
     */
    public function listAccessibleLessons(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);
        $courseIds = $entitlements['course_ids'] ?? [];
        if (empty($courseIds)) {
            return [];
        }

        $courses = Course::whereIn('id', $courseIds)
            ->where('status', CourseStatus::PUBLISHED)
            ->with([
                'lessons' => fn ($q) => $q
                    ->where('status', LessonStatus::PUBLISHED)
                    ->withCount('transcriptSegments')
                    ->orderBy('id'),
            ])
            ->get(['id', 'uuid', 'title_ar', 'title_en', 'subject_ar', 'grade_ar']);

        $items = [];
        foreach ($courses as $course) {
            foreach ($course->lessons as $lesson) {
                $items[] = [
                    'id' => $lesson->uuid,
                    'courseId' => $course->uuid,
                    'courseTitleAr' => $course->title_ar,
                    'subjectAr' => $lesson->subject_ar ?: $course->subject_ar ?: $course->title_ar,
                    'gradeAr' => $lesson->grade_ar ?: $course->grade_ar,
                    'titleAr' => $lesson->title_ar,
                    'titleEn' => $lesson->title_en ?: $lesson->title_ar,
                    'durationLabel' => $lesson->duration_label ?: '00:00',
                    'hasTranscript' => (int) $lesson->transcript_segments_count > 0,
                    'transcriptCount' => (int) $lesson->transcript_segments_count,
                    'hasMedia' => ! empty($lesson->media_path) || ! empty($lesson->embed_url),
                ];
            }
        }

        return $items;
    }

    /**
     * Start an async AI MCQ Practice Quiz directly from any accessible course lesson's transcript.
     *
     * @return array<string, mixed>
     */
    public function startLessonQuiz(User $student, string $lessonUuid, int $quizCount = 5): array
    {
        /** @var Lesson|null $lesson */
        $lesson = Lesson::with(['course:id,uuid,title_ar,subject_ar', 'transcriptSegments'])
            ->where('uuid', $lessonUuid)
            ->first();

        if (! $lesson) {
            throw (new ModelNotFoundException)->setModel(Lesson::class, [$lessonUuid]);
        }

        $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);

        if ($lesson->transcriptSegments->isEmpty()) {
            throw ValidationException::withMessages([
                'transcript' => [__('messages.ai.transcript_required_for_analysis')],
            ]);
        }

        $safeCount = max(3, min(10, $quizCount));
        $segmentsPayload = [];
        $maxSecs = max(0, (int) ($lesson->duration_seconds ?? 0));

        foreach ($lesson->transcriptSegments as $seg) {
            $txt = trim((string) ($seg->text_ar ?: $seg->text_en ?: ''));
            if ($txt === '') {
                continue;
            }
            $startS = max(0, (int) $seg->start_seconds);
            $endS = $startS + 25;
            $maxSecs = max($maxSecs, $endS);

            $segmentsPayload[] = [
                'start_seconds' => $startS,
                'end_seconds' => $endS,
                'timestamp' => (string) ($seg->timestamp_label ?: $this->formatSeconds($startS)),
                'speaker' => (string) ($seg->speaker ?: 'المعلم'),
                'text' => $txt,
            ];
        }

        $remote = $this->aiClient->startAnalysisJob([
            'title' => (string) $lesson->title_ar,
            'language' => 'auto',
            'duration_seconds' => $maxSecs,
            'tasks' => ['quiz'],
            'quiz_count' => $safeCount,
            'segments' => $segmentsPayload,
        ]);

        $job = AiProcessingJob::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'task_type' => AiProcessingJob::TASK_QUIZ,
            'status' => (string) ($remote['status'] ?? AiProcessingJob::STATUS_QUEUED),
            'progress' => max(5, min(95, (int) ($remote['progress'] ?? 8))),
            'stage' => (string) ($remote['stage'] ?? 'queued'),
            'remote_job_id' => $remote['job_id'] ?? null,
            'model_name' => (string) ($remote['model'] ?? config('ai.llm.default_model', 'Qwen/Qwen2.5-3B-Instruct')),
            'meta' => [
                'source' => 'student_lesson_quiz',
                'lessonUuid' => $lesson->uuid,
                'lessonTitleAr' => $lesson->title_ar,
                'subjectAr' => $lesson->subject_ar ?: $lesson->course?->subject_ar ?: $lesson->course?->title_ar,
                'quizCount' => $safeCount,
            ],
            'started_at' => now(),
        ]);

        $this->logSecurityEvent('student.ai.lesson_quiz_started', ['lesson_uuid' => $lesson->uuid, 'job_uuid' => $job->uuid], 'info', $lesson);

        return $this->formatStudentAiJob($job);
    }

    /**
     * Start an async AI Weak-Area Coach diagnosis + targeted remediation quiz from the student's real mistake bank.
     *
     * @param  list<string>  $mistakeIds
     * @return array<string, mixed>
     */
    public function startWeakAreaDiagnosis(
        User $student,
        array $mistakeIds = [],
        bool $includeQuiz = true,
        int $quizCount = 5
    ): array {
        $allMistakes = $this->examService->getMistakeBank($student);

        if (! empty($mistakeIds)) {
            $wanted = array_flip($mistakeIds);
            $filtered = array_values(array_filter(
                $allMistakes,
                fn (array $m) => isset($wanted[(string) ($m['id'] ?? '')])
            ));
            $selected = ! empty($filtered) ? $filtered : $allMistakes;
        } else {
            $selected = $allMistakes;
        }

        if (empty($selected)) {
            throw ValidationException::withMessages([
                'mistakes' => [__('messages.ai.no_mistakes_for_coach')],
            ]);
        }

        $mistakesPayload = [];
        foreach (array_slice($selected, 0, 20) as $m) {
            $qText = trim((string) ($m['questionAr'] ?? ''));
            if ($qText === '') {
                continue;
            }
            $mistakesPayload[] = [
                'question' => mb_substr($qText, 0, 580),
                'subject' => mb_substr((string) ($m['subjectAr'] ?? ''), 0, 120),
                'exam_title' => mb_substr((string) ($m['examTitleAr'] ?? ''), 0, 180),
                'student_answer' => mb_substr((string) ($m['wrongAnswerGivenAr'] ?? ''), 0, 280),
                'correct_answer' => mb_substr((string) ($m['correctAnswerAr'] ?? ''), 0, 280),
                'explanation' => mb_substr((string) ($m['explanationAr'] ?? ''), 0, 480),
            ];
        }

        $tasks = $includeQuiz ? ['student_coach', 'quiz'] : ['student_coach'];
        $safeQuizCount = max(3, min(10, $quizCount));

        $remote = $this->aiClient->startAnalysisJob([
            'title' => 'تشخيص نقاط الضعف والمراجعة العلاجية',
            'language' => 'auto',
            'tasks' => $tasks,
            'quiz_count' => $safeQuizCount,
            'mistakes' => $mistakesPayload,
        ]);

        $job = AiProcessingJob::create([
            'user_id' => $student->id,
            'task_type' => AiProcessingJob::TASK_STUDENT_COACH,
            'status' => (string) ($remote['status'] ?? AiProcessingJob::STATUS_QUEUED),
            'progress' => max(5, min(95, (int) ($remote['progress'] ?? 8))),
            'stage' => (string) ($remote['stage'] ?? 'queued'),
            'remote_job_id' => $remote['job_id'] ?? null,
            'model_name' => (string) ($remote['model'] ?? config('ai.llm.default_model', 'Qwen/Qwen2.5-3B-Instruct')),
            'meta' => [
                'source' => 'student_weak_areas_coach',
                'mistakesCount' => count($mistakesPayload),
                'tasks' => $tasks,
                'quizCount' => $safeQuizCount,
            ],
            'started_at' => now(),
        ]);

        $this->logSecurityEvent('student.ai.weak_areas_diagnosed', ['job_uuid' => $job->uuid, 'mistakes_count' => count($mistakesPayload)], 'info', $student);

        return $this->formatStudentAiJob($job);
    }

    /**
     * Retrieve the student's latest Weak-Area AI Coach job/report (and sync if still running).
     *
     * @return array<string, mixed>|null
     */
    public function getLatestCoachReport(User $student): ?array
    {
        /** @var AiProcessingJob|null $job */
        $job = AiProcessingJob::where('user_id', $student->id)
            ->where('task_type', AiProcessingJob::TASK_STUDENT_COACH)
            ->latest('id')
            ->first();

        if (! $job) {
            return null;
        }

        if ($job->isActive()) {
            $job = $this->syncStudentAiJob($job);
        }

        return $this->formatStudentAiJob($job);
    }

    /**
     * Poll an owned Student AI job (Lesson Practice Quiz or Weak-Area AI Coach).
     *
     * @return array<string, mixed>
     */
    public function getJobStatus(User $student, string $jobUuid): array
    {
        /** @var AiProcessingJob|null $job */
        $job = AiProcessingJob::where('uuid', $jobUuid)->first();
        if (! $job) {
            throw (new ModelNotFoundException)->setModel(AiProcessingJob::class, [$jobUuid]);
        }

        if ((int) $job->user_id !== (int) $student->id) {
            throw new AuthorizationException(__('messages.forbidden'));
        }

        if ($job->isActive()) {
            $job = $this->syncStudentAiJob($job);
        }

        return $this->formatStudentAiJob($job);
    }

    protected function syncStudentAiJob(AiProcessingJob $job): AiProcessingJob
    {
        if (! $job->remote_job_id || $job->isTerminal()) {
            return $job;
        }

        try {
            $remote = $this->aiClient->getAnalysisJob($job->remote_job_id);
        } catch (RuntimeException $e) {
            Log::warning('Transient Student AI job polling failure', [
                'job_uuid' => $job->uuid,
                'error' => $e->getMessage(),
            ]);

            return $job;
        }

        $remoteStatus = (string) ($remote['status'] ?? $job->status);
        $remoteStage = (string) ($remote['stage'] ?? $job->stage);
        $remoteProgress = (int) ($remote['progress'] ?? $job->progress);

        if ($remoteStatus === 'completed' && is_array($remote['result'] ?? null)) {
            $result = $remote['result'];

            $persisted = $this->transaction(function () use ($job, $result): AiProcessingJob {
                /** @var AiProcessingJob $locked */
                $locked = AiProcessingJob::where('id', $job->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === AiProcessingJob::STATUS_COMPLETED) {
                    return $locked;
                }

                $existingMeta = is_array($locked->meta) ? $locked->meta : [];
                $metaUpdates = [
                    'device' => $result['device'] ?? null,
                    'processingSeconds' => $result['processing_seconds'] ?? null,
                ];
                if (is_array($result['coach_report'] ?? null)) {
                    $metaUpdates['coachReport'] = $result['coach_report'];
                }
                if (is_array($result['quiz_questions'] ?? null)) {
                    $metaUpdates['quizQuestions'] = $result['quiz_questions'];
                    $metaUpdates['questionsCount'] = count($result['quiz_questions']);
                }

                $locked->update([
                    'status' => AiProcessingJob::STATUS_COMPLETED,
                    'progress' => 100,
                    'stage' => 'completed',
                    'model_name' => (string) ($result['model'] ?? $locked->model_name),
                    'error_message' => null,
                    'meta' => array_merge($existingMeta, $metaUpdates),
                    'completed_at' => now(),
                ]);

                return $locked->fresh();
            });

            $this->aiClient->deleteAnalysisJob($job->remote_job_id);

            return $persisted;
        }

        if ($remoteStatus === 'failed') {
            $errText = mb_substr(
                trim(strip_tags((string) ($remote['error'] ?? __('messages.ai.analysis_error')))),
                0,
                500
            );
            $job->update([
                'status' => AiProcessingJob::STATUS_FAILED,
                'stage' => 'failed',
                'error_message' => $errText,
                'completed_at' => now(),
            ]);
            $this->aiClient->deleteAnalysisJob($job->remote_job_id);

            return $job->fresh();
        }

        $job->update([
            'status' => in_array($remoteStatus, [AiProcessingJob::STATUS_QUEUED, AiProcessingJob::STATUS_PROCESSING], true)
                ? $remoteStatus
                : AiProcessingJob::STATUS_PROCESSING,
            'progress' => max($job->progress, min(98, $remoteProgress)),
            'stage' => mb_substr($remoteStage, 0, 50),
        ]);

        return $job->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatStudentAiJob(AiProcessingJob $job): array
    {
        $meta = is_array($job->meta) ? $job->meta : [];

        return [
            'job' => LessonSpeechToTextService::formatJob($job),
            'source' => $meta['source'] ?? $job->task_type,
            'lessonId' => $meta['lessonUuid'] ?? null,
            'lessonTitleAr' => $meta['lessonTitleAr'] ?? null,
            'subjectAr' => $meta['subjectAr'] ?? null,
            'coachReport' => is_array($meta['coachReport'] ?? null) ? $meta['coachReport'] : null,
            'quizQuestions' => is_array($meta['quizQuestions'] ?? null) ? $meta['quizQuestions'] : [],
        ];
    }

    protected function formatSeconds(int $seconds): string
    {
        $safe = max(0, $seconds);
        $m = intdiv($safe, 60);
        $s = $safe % 60;

        return sprintf('%02d:%02d', $m, $s);
    }
}
