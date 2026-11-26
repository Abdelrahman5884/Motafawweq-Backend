<?php

namespace App\Services\V1\Student;

use App\Models\AiProcessingJob;
use App\Models\Lesson;
use App\Models\StudentConvertedLecture;
use App\Models\User;
use App\Services\BaseService;
use App\Services\V1\AI\AiMicroserviceClient;
use App\Services\V1\AI\LessonSpeechToTextService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StudentConvertedLectureService extends BaseService
{
    private const ALLOWED_OUTPUTS = ['graph', 'transcript', 'topics', 'quiz'];

    private const ALLOWED_MIME_PREFIXES = ['audio/', 'video/', 'application/pdf'];

    public function __construct(
        protected StudentAccessService $accessService,
        protected AiMicroserviceClient $aiClient
    ) {}

    public function list(User $student): array
    {
        return StudentConvertedLecture::forStudent($student->id)
            ->with(['lesson:id,uuid', 'latestSttJob'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (StudentConvertedLecture $l) => $this->format($l, false))
            ->values()
            ->all();
    }

    public function show(User $student, string $uuid): array
    {
        $lecture = $this->findOwned($student, $uuid);

        if ($lecture->latestSttJob && $lecture->latestSttJob->isActive()) {
            $this->syncLectureSttJob($lecture->latestSttJob, $lecture);
            $lecture = $lecture->fresh(['lesson:id,uuid', 'latestSttJob']);
        }

        return $this->format($lecture, true);
    }

    /**
     * Poll live AI Speech-to-Text progress and return the updated converted lecture payload.
     *
     * @return array<string, mixed>
     */
    public function getStatus(User $student, string $uuid): array
    {
        return $this->show($student, $uuid);
    }

    /**
     * Register a lecture conversion request and trigger real AI Speech-to-Text when an audio/video file is provided.
     */
    public function create(User $student, array $data, ?UploadedFile $file = null): array
    {
        $outputs = array_values(array_intersect(
            self::ALLOWED_OUTPUTS,
            array_map('strval', (array) ($data['outputs'] ?? ['graph', 'transcript', 'topics']))
        ));
        if (empty($outputs)) {
            $outputs = ['transcript'];
        }

        $fileName = null;
        $fileSize = null;
        $isAudioOrVideo = false;
        if ($file) {
            $mime = (string) $file->getMimeType();
            $allowed = false;
            foreach (self::ALLOWED_MIME_PREFIXES as $prefix) {
                if (str_starts_with($mime, $prefix)) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                throw ValidationException::withMessages([
                    'file' => ['نوع الملف غير مدعوم. يرجى رفع ملف صوت أو فيديو أو PDF.'],
                ]);
            }
            $isAudioOrVideo = str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/');
            $fileName = mb_substr(preg_replace('/[^\pL\pN._\- ]/u', '', $file->getClientOriginalName()) ?: 'lecture', 0, 200);
            $fileSize = round($file->getSize() / (1024 * 1024), 1).' MB';
        } elseif (! empty($data['file_name'])) {
            $fileName = mb_substr(trim(strip_tags((string) $data['file_name'])), 0, 200);
            $fileSize = isset($data['file_size']) ? mb_substr(trim(strip_tags((string) $data['file_size'])), 0, 60) : null;
        }

        $lessonId = null;
        if (! empty($data['lesson_uuid'])) {
            $lesson = Lesson::where('uuid', $data['lesson_uuid'])->first();
            if ($lesson) {
                $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);
                $lessonId = $lesson->id;
            }
        }

        $title = trim(strip_tags((string) ($data['title_ar'] ?? '')));
        if ($title === '') {
            $title = $fileName ? preg_replace('/\.[^.]+$/', '', $fileName) : 'محاضرة محولة';
        }

        $wantsTranscript = in_array('transcript', $outputs, true);

        $lecture = StudentConvertedLecture::create([
            'student_id' => $student->id,
            'lesson_id' => $lessonId,
            'title_ar' => mb_substr($title, 0, 255),
            'subject_ar' => isset($data['subject_ar']) ? mb_substr(trim(strip_tags((string) $data['subject_ar'])), 0, 120) : null,
            'grade_ar' => isset($data['grade_ar']) ? mb_substr(trim(strip_tags((string) $data['grade_ar'])), 0, 120) : null,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'duration_label' => null,
            'summary_ar' => $wantsTranscript && $isAudioOrVideo
                ? 'جاري التفريغ النصي الذكي للمحاضرة بواسطة محرك الذكاء الاصطناعي...'
                : 'تم استلام المحاضرة وتسجيل طلب التحويل بنجاح.',
            'outputs' => $outputs,
            'transcript' => [],
            'topics' => [],
            'mindmap' => null,
            'quiz_questions' => [],
            'questions_count' => 0,
            'concepts_count' => 0,
        ]);

        // Dispatch real Whisper large-v3 Speech-to-Text when an audio/video file is uploaded
        if ($file && $isAudioOrVideo && $wantsTranscript) {
            $requestedLang = isset($data['language']) ? strtolower(trim((string) $data['language'])) : null;
            if ($requestedLang === '' || $requestedLang === 'auto') {
                $requestedLang = null;
            }

            try {
                $remote = $this->aiClient->startFileTranscriptionJob(
                    $file->getRealPath(),
                    $fileName ?: 'lecture.mp3',
                    $requestedLang,
                    'المحاضر'
                );

                AiProcessingJob::create([
                    'user_id' => $student->id,
                    'converted_lecture_id' => $lecture->id,
                    'task_type' => AiProcessingJob::TASK_SPEECH_TO_TEXT,
                    'status' => (string) ($remote['status'] ?? AiProcessingJob::STATUS_QUEUED),
                    'progress' => max(5, min(95, (int) ($remote['progress'] ?? 8))),
                    'stage' => (string) ($remote['stage'] ?? 'queued'),
                    'remote_job_id' => $remote['job_id'] ?? null,
                    'model_name' => (string) ($remote['model'] ?? config('ai.stt.default_model', 'large-v3')),
                    'requested_language' => $requestedLang,
                    'started_at' => now(),
                ]);
            } catch (RuntimeException $e) {
                Log::warning('Student smart lecture STT dispatch failed', [
                    'lecture_uuid' => $lecture->uuid,
                    'error' => $e->getMessage(),
                ]);

                AiProcessingJob::create([
                    'user_id' => $student->id,
                    'converted_lecture_id' => $lecture->id,
                    'task_type' => AiProcessingJob::TASK_SPEECH_TO_TEXT,
                    'status' => AiProcessingJob::STATUS_FAILED,
                    'progress' => 0,
                    'stage' => 'failed',
                    'model_name' => (string) config('ai.stt.default_model', 'large-v3'),
                    'requested_language' => $requestedLang,
                    'error_message' => mb_substr($e->getMessage(), 0, 500),
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        }

        $this->logSecurityEvent('student.lecture.converted', ['lecture_uuid' => $lecture->uuid], 'info', $lecture);

        return $this->format($lecture->fresh(['lesson:id,uuid', 'latestSttJob']), true);
    }

    public function delete(User $student, string $uuid): bool
    {
        $lecture = $this->findOwned($student, $uuid);
        $lecture->delete();

        return true;
    }

    protected function syncLectureSttJob(AiProcessingJob $job, StudentConvertedLecture $lecture): AiProcessingJob
    {
        if (! $job->remote_job_id || $job->isTerminal()) {
            return $job;
        }

        try {
            $remote = $this->aiClient->getTranscriptionJob($job->remote_job_id);
        } catch (RuntimeException $e) {
            Log::warning('Transient AI polling failure for student lecture', [
                'lecture_uuid' => $lecture->uuid,
                'error' => $e->getMessage(),
            ]);

            return $job;
        }

        $remoteStatus = (string) ($remote['status'] ?? $job->status);
        $remoteStage = (string) ($remote['stage'] ?? $job->stage);
        $remoteProgress = (int) ($remote['progress'] ?? $job->progress);

        if ($remoteStatus === 'completed' && is_array($remote['result'] ?? null)) {
            $result = $remote['result'];
            $rawSegments = is_array($result['segments'] ?? null) ? $result['segments'] : [];

            $persisted = $this->transaction(function () use ($job, $lecture, $result, $rawSegments) {
                /** @var AiProcessingJob $locked */
                $locked = AiProcessingJob::where('id', $job->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === AiProcessingJob::STATUS_COMPLETED) {
                    return $locked;
                }

                $formattedSegments = [];
                foreach ($rawSegments as $idx => $seg) {
                    $text = trim(strip_tags((string) ($seg['text'] ?? '')));
                    if ($text === '') {
                        continue;
                    }
                    $startSecs = max(0, (int) ($seg['start_seconds'] ?? 0));
                    $formattedSegments[] = [
                        'id' => (string) Str::uuid(),
                        'speaker' => trim(strip_tags((string) ($seg['speaker'] ?? 'المحاضر'))) ?: 'المحاضر',
                        'startSeconds' => $startSecs,
                        'endSeconds' => max($startSecs, (int) ($seg['end_seconds'] ?? $startSecs)),
                        'timestamp' => trim((string) ($seg['timestamp'] ?? $this->formatSeconds($startSecs))),
                        'text' => $text,
                        'textAr' => $text,
                        'textEn' => $text,
                        'order' => count($formattedSegments) + 1,
                    ];
                }

                $durationSecs = max(0, (int) ($result['duration_seconds'] ?? 0));
                $langNameAr = (string) ($result['detected_language_name_ar'] ?? $result['detected_language'] ?? '');
                $summaryText = count($formattedSegments) > 0
                    ? sprintf('تم التفريغ النصي الذكي بنجاح (%d فقرة • اللغة: %s).', count($formattedSegments), $langNameAr ?: 'تلقائي')
                    : 'اكتمل تحليل الصوت ولكن لم يتم اكتشاف كلام واضح في التسجيل.';

                $lecture->update([
                    'transcript' => $formattedSegments,
                    'duration_label' => $durationSecs > 0 ? $this->formatSeconds($durationSecs) : $lecture->duration_label,
                    'summary_ar' => $summaryText,
                ]);

                $locked->update([
                    'status' => AiProcessingJob::STATUS_COMPLETED,
                    'progress' => 100,
                    'stage' => 'completed',
                    'model_name' => (string) ($result['model'] ?? $locked->model_name),
                    'detected_language' => isset($result['detected_language']) ? mb_substr((string) $result['detected_language'], 0, 16) : null,
                    'language_probability' => isset($result['language_probability']) ? (float) $result['language_probability'] : null,
                    'duration_seconds' => $durationSecs > 0 ? $durationSecs : $locked->duration_seconds,
                    'segments_count' => count($formattedSegments),
                    'error_message' => null,
                    'meta' => [
                        'device' => $result['device'] ?? null,
                        'computeType' => $result['compute_type'] ?? null,
                        'processingSeconds' => $result['processing_seconds'] ?? null,
                        'detectedLanguageNameAr' => $result['detected_language_name_ar'] ?? null,
                        'detectedLanguageNameEn' => $result['detected_language_name_en'] ?? null,
                    ],
                    'completed_at' => now(),
                ]);

                return $locked->fresh();
            });

            $this->aiClient->deleteTranscriptionJob($job->remote_job_id);

            return $persisted;
        }

        if ($remoteStatus === 'failed') {
            $errText = mb_substr(
                trim(strip_tags((string) ($remote['error'] ?? __('messages.ai.transcription_failed')))),
                0,
                500
            );
            $job->update([
                'status' => AiProcessingJob::STATUS_FAILED,
                'stage' => 'failed',
                'error_message' => $errText,
                'completed_at' => now(),
            ]);
            $this->aiClient->deleteTranscriptionJob($job->remote_job_id);

            return $job->fresh();
        }

        $job->update([
            'status' => in_array($remoteStatus, [AiProcessingJob::STATUS_QUEUED, AiProcessingJob::STATUS_PROCESSING], true)
                ? $remoteStatus
                : AiProcessingJob::STATUS_PROCESSING,
            'progress' => max($job->progress, min(98, $remoteProgress)),
            'stage' => mb_substr($remoteStage, 0, 50),
            'detected_language' => $remote['detected_language'] ?? $job->detected_language,
            'language_probability' => isset($remote['language_probability'])
                ? (float) $remote['language_probability']
                : $job->language_probability,
            'duration_seconds' => isset($remote['duration_seconds'])
                ? (int) $remote['duration_seconds']
                : $job->duration_seconds,
            'segments_count' => (int) ($remote['segments_processed'] ?? $job->segments_count),
        ]);

        return $job->fresh();
    }

    protected function formatSeconds(int $seconds): string
    {
        $safe = max(0, $seconds);
        $h = intdiv($safe, 3600);
        $m = intdiv($safe % 3600, 60);
        $s = $safe % 60;

        return $h > 0
            ? sprintf('%02d:%02d:%02d', $h, $m, $s)
            : sprintf('%02d:%02d', $m, $s);
    }

    protected function findOwned(User $student, string $uuid): StudentConvertedLecture
    {
        /** @var StudentConvertedLecture|null $lecture */
        $lecture = StudentConvertedLecture::where('uuid', $uuid)
            ->with(['lesson:id,uuid', 'latestSttJob'])
            ->first();
        if (! $lecture) {
            throw (new ModelNotFoundException)->setModel(StudentConvertedLecture::class, [$uuid]);
        }
        $lecture->assertOwnedByStudent($student);

        return $lecture;
    }

    protected function format(StudentConvertedLecture $l, bool $withContent): array
    {
        $sttJob = $l->relationLoaded('latestSttJob') ? $l->latestSttJob : null;

        $base = [
            'id' => $l->uuid,
            'lessonId' => $l->lesson?->uuid,
            'titleAr' => $l->title_ar,
            'subjectAr' => $l->subject_ar,
            'gradeAr' => $l->grade_ar,
            'fileName' => $l->file_name,
            'fileSize' => $l->file_size,
            'duration' => $l->duration_label,
            'summaryAr' => $l->summary_ar,
            'outputs' => $l->outputs ?? [],
            'questionsCount' => (int) $l->questions_count,
            'conceptsCount' => (int) $l->concepts_count,
            'keyTopics' => collect($l->topics ?? [])->pluck('titleAr')->filter()->take(5)->values()->all(),
            'sttJob' => LessonSpeechToTextService::formatJob($sttJob),
            'createdAt' => $l->created_at?->toIso8601String(),
            'dateAr' => $l->created_at?->format('Y-m-d'),
        ];

        if ($withContent) {
            $base['transcript'] = $l->transcript ?? [];
            $base['topics'] = $l->topics ?? [];
            $base['mindmap'] = $l->mindmap;
            $base['quizQuestions'] = $l->quiz_questions ?? [];
        }

        return $base;
    }
}
