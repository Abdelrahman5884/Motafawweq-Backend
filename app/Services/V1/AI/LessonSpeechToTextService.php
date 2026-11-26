<?php

namespace App\Services\V1\AI;

use App\Http\Resources\V1\Teacher\LessonTranscriptResource;
use App\Models\AiProcessingJob;
use App\Models\Lesson;
use App\Models\LessonTranscript;
use App\Models\User;
use App\Repositories\Contracts\LessonRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LessonSpeechToTextService extends BaseService
{
    public const LANGUAGE_NAMES = [
        'ar' => ['ar' => 'العربية', 'en' => 'Arabic'],
        'en' => ['ar' => 'الإنجليزية', 'en' => 'English'],
        'fr' => ['ar' => 'الفرنسية', 'en' => 'French'],
        'de' => ['ar' => 'الألمانية', 'en' => 'German'],
        'es' => ['ar' => 'الإسبانية', 'en' => 'Spanish'],
        'it' => ['ar' => 'الإيطالية', 'en' => 'Italian'],
        'tr' => ['ar' => 'التركية', 'en' => 'Turkish'],
        'ru' => ['ar' => 'الروسية', 'en' => 'Russian'],
        'zh' => ['ar' => 'الصينية', 'en' => 'Chinese'],
        'ja' => ['ar' => 'اليابانية', 'en' => 'Japanese'],
        'ko' => ['ar' => 'الكورية', 'en' => 'Korean'],
        'ur' => ['ar' => 'الأردية', 'en' => 'Urdu'],
        'fa' => ['ar' => 'الفارسية', 'en' => 'Persian'],
        'hi' => ['ar' => 'الهندية', 'en' => 'Hindi'],
    ];

    public function __construct(
        protected LessonRepositoryInterface $lessonRepository,
        protected AiMicroserviceClient $aiClient
    ) {}

    /**
     * Start (or attach to an active) Speech-to-Text transcription job for a teacher's lesson.
     */
    public function startForLesson(
        User $teacher,
        string $lessonUuid,
        ?string $language = null,
        bool $force = false
    ): AiProcessingJob {
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        $normalizedLang = $this->normalizeLanguage($language);

        // Reuse an in-flight job to prevent duplicate GPU work on page reloads / double clicks
        $existingActive = AiProcessingJob::where('lesson_id', $lesson->id)
            ->where('task_type', AiProcessingJob::TASK_SPEECH_TO_TEXT)
            ->whereIn('status', [AiProcessingJob::STATUS_QUEUED, AiProcessingJob::STATUS_PROCESSING])
            ->latest('id')
            ->first();

        if ($existingActive && ! $force) {
            return $this->syncJobState($existingActive, $lesson, $teacher);
        }

        // If already transcribed and caller didn't explicitly request re-transcription, reuse completed job
        if (! $force && $lesson->transcriptSegments->isNotEmpty()) {
            $existingCompleted = AiProcessingJob::where('lesson_id', $lesson->id)
                ->where('task_type', AiProcessingJob::TASK_SPEECH_TO_TEXT)
                ->where('status', AiProcessingJob::STATUS_COMPLETED)
                ->latest('id')
                ->first();

            if ($existingCompleted) {
                return $existingCompleted;
            }
        }

        $speakerName = trim((string) ($teacher->name ?: 'المعلم'));
        $remoteResponse = $this->dispatchMediaToAi($lesson, $normalizedLang, $speakerName);

        $job = AiProcessingJob::create([
            'user_id' => $teacher->id,
            'lesson_id' => $lesson->id,
            'task_type' => AiProcessingJob::TASK_SPEECH_TO_TEXT,
            'status' => (string) ($remoteResponse['status'] ?? AiProcessingJob::STATUS_QUEUED),
            'progress' => max(2, min(95, (int) ($remoteResponse['progress'] ?? 5))),
            'stage' => (string) ($remoteResponse['stage'] ?? 'queued'),
            'remote_job_id' => $remoteResponse['job_id'] ?? null,
            'model_name' => (string) ($remoteResponse['model'] ?? config('ai.stt.default_model', 'large-v3')),
            'requested_language' => $normalizedLang,
            'started_at' => now(),
        ]);

        $this->logSecurityEvent(
            'teacher.ai.stt_started',
            [
                'lesson_uuid' => $lesson->uuid,
                'ai_job_uuid' => $job->uuid,
                'requested_language' => $normalizedLang ?? 'auto',
                'model' => $job->model_name,
            ],
            'info',
            $lesson
        );

        return $job;
    }

    /**
     * Poll and synchronize the latest Speech-to-Text status for a teacher's lesson.
     *
     * @return array<string, mixed>
     */
    public function getStatusForLesson(User $teacher, string $lessonUuid): array
    {
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        $job = AiProcessingJob::where('lesson_id', $lesson->id)
            ->where('task_type', AiProcessingJob::TASK_SPEECH_TO_TEXT)
            ->latest('id')
            ->first();

        if ($job && $job->isActive()) {
            $job = $this->syncJobState($job, $lesson, $teacher);
            if ($job->status === AiProcessingJob::STATUS_COMPLETED) {
                $lesson->load('transcriptSegments');
            }
        }

        return [
            'lessonId' => $lesson->uuid,
            'hasMedia' => ! empty($lesson->media_path) || ! empty($lesson->embed_url),
            'mediaMode' => $lesson->media_mode?->value ?? (string) $lesson->media_mode,
            'aiFeatures' => $lesson->ai_features_requested ?? [
                'speechToText' => true,
                'conceptGraph' => false,
                'chapterIndexing' => false,
            ],
            'job' => self::formatJob($job),
            'transcript' => LessonTranscriptResource::collection($lesson->transcriptSegments)->resolve(),
            'transcriptCount' => $lesson->transcriptSegments->count(),
            'durationSeconds' => (int) ($lesson->duration_seconds ?? 0),
            'durationFormatted' => $this->formatSecondsLabel((int) ($lesson->duration_seconds ?? 0)),
        ];
    }

    /**
     * Synchronize a live AiProcessingJob against the FastAPI microservice and atomically persist completed segments.
     */
    protected function syncJobState(AiProcessingJob $job, Lesson $lesson, User $teacher): AiProcessingJob
    {
        if (! $job->remote_job_id || $job->isTerminal()) {
            return $job;
        }

        try {
            $remote = $this->aiClient->getTranscriptionJob($job->remote_job_id);
        } catch (RuntimeException $e) {
            Log::warning('Transient AI polling failure', [
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
            $segments = is_array($result['segments'] ?? null) ? $result['segments'] : [];

            $persistedJob = $this->transaction(function () use ($job, $lesson, $teacher, $result, $segments) {
                /** @var AiProcessingJob $locked */
                $locked = AiProcessingJob::where('id', $job->id)->lockForUpdate()->firstOrFail();

                // Guard against duplicate writes when two polling requests arrive simultaneously
                if ($locked->status === AiProcessingJob::STATUS_COMPLETED) {
                    return $locked;
                }

                LessonTranscript::where('lesson_id', $lesson->id)->delete();

                $defaultSpeaker = trim((string) ($teacher->name ?: 'المعلم'));
                $savedCount = 0;

                foreach ($segments as $idx => $seg) {
                    $text = trim(strip_tags((string) ($seg['text'] ?? '')));
                    if ($text === '') {
                        continue;
                    }

                    $startSecs = max(0, (int) ($seg['start_seconds'] ?? 0));
                    $timestamp = trim((string) ($seg['timestamp'] ?? $this->formatSecondsLabel($startSecs)));
                    $speaker = trim(strip_tags((string) ($seg['speaker'] ?? $defaultSpeaker))) ?: $defaultSpeaker;

                    LessonTranscript::create([
                        'lesson_id' => $lesson->id,
                        'teacher_id' => $teacher->id,
                        'speaker' => mb_substr($speaker, 0, 120),
                        'start_seconds' => $startSecs,
                        'timestamp_label' => mb_substr($timestamp, 0, 20),
                        'text_ar' => $text,
                        'text_en' => $text,
                        'display_order' => $savedCount + 1,
                    ]);
                    $savedCount++;
                }

                $audioDuration = max(0, (int) ($result['duration_seconds'] ?? 0));
                if ($audioDuration > 0 && (int) ($lesson->duration_seconds ?? 0) === 0) {
                    $lesson->update([
                        'duration_seconds' => $audioDuration,
                        'duration_label' => $this->formatSecondsLabel($audioDuration),
                    ]);
                }

                $locked->update([
                    'status' => AiProcessingJob::STATUS_COMPLETED,
                    'progress' => 100,
                    'stage' => 'completed',
                    'model_name' => (string) ($result['model'] ?? $locked->model_name),
                    'detected_language' => isset($result['detected_language']) ? mb_substr((string) $result['detected_language'], 0, 16) : null,
                    'language_probability' => isset($result['language_probability']) ? (float) $result['language_probability'] : null,
                    'duration_seconds' => $audioDuration > 0 ? $audioDuration : $locked->duration_seconds,
                    'segments_count' => $savedCount,
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

                $this->logSecurityEvent(
                    'teacher.ai.stt_completed',
                    [
                        'lesson_uuid' => $lesson->uuid,
                        'ai_job_uuid' => $locked->uuid,
                        'detected_language' => $locked->detected_language,
                        'segments_count' => $savedCount,
                        'processing_seconds' => $result['processing_seconds'] ?? null,
                    ],
                    'info',
                    $lesson
                );

                return $locked->fresh();
            });

            $this->aiClient->deleteTranscriptionJob($job->remote_job_id);

            return $persistedJob;
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

    /**
     * Resolve lesson media from local disk, Supabase S3, or verified HTTPS embed URL and dispatch to AI.
     *
     * @return array<string, mixed>
     */
    protected function dispatchMediaToAi(Lesson $lesson, ?string $language, string $speaker): array
    {
        if (! empty($lesson->media_path)) {
            $disk = $this->resolveExistingDisk($lesson->media_disk, $lesson->media_path);
            if (! $disk) {
                throw ValidationException::withMessages([
                    'media' => [__('messages.ai.lesson_media_missing')],
                ]);
            }

            $filename = $lesson->media_original_name ?: basename($lesson->media_path);

            if ($disk === 'local') {
                $fullPath = Storage::disk('local')->path($lesson->media_path);

                return $this->aiClient->startFileTranscriptionJob($fullPath, $filename, $language, $speaker);
            }

            // Stream from remote S3/Supabase into an isolated temp file, submit, and always unlink
            $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin';
            $tempPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'mtfq_stt_'.Str::uuid().'.'.$ext;

            try {
                $readStream = Storage::disk($disk)->readStream($lesson->media_path);
                if (! is_resource($readStream)) {
                    throw new RuntimeException(__('messages.ai.media_file_unreadable'));
                }

                $writeStream = fopen($tempPath, 'wb');
                if ($writeStream === false) {
                    fclose($readStream);
                    throw new RuntimeException(__('messages.ai.media_file_unreadable'));
                }

                try {
                    stream_copy_to_stream($readStream, $writeStream);
                } finally {
                    fclose($readStream);
                    fclose($writeStream);
                }

                return $this->aiClient->startFileTranscriptionJob($tempPath, $filename, $language, $speaker);
            } finally {
                if (is_file($tempPath)) {
                    @unlink($tempPath);
                }
            }
        }

        if (! empty($lesson->embed_url)) {
            return $this->aiClient->startUrlTranscriptionJob($lesson->embed_url, $language, $speaker);
        }

        throw ValidationException::withMessages([
            'media' => [__('messages.ai.lesson_media_required')],
        ]);
    }

    protected function resolveExistingDisk(?string $declaredDisk, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $primary = $declaredDisk ?: 'local';
        $candidates = $primary === 'local' ? ['local'] : [$primary, 'local'];

        foreach ($candidates as $candidate) {
            try {
                if (Storage::disk($candidate)->exists($path)) {
                    return $candidate;
                }
            } catch (\Throwable) {
                // try next candidate
            }
        }

        return null;
    }

    protected function normalizeLanguage(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        $clean = strtolower(trim($language));
        if ($clean === '' || in_array($clean, ['auto', 'detect', 'any'], true)) {
            return null;
        }

        return preg_match('/^[a-z]{2,3}$/', $clean) ? $clean : null;
    }

    protected function formatSecondsLabel(int $seconds): string
    {
        $safe = max(0, $seconds);
        $h = intdiv($safe, 3600);
        $m = intdiv($safe % 3600, 60);
        $s = $safe % 60;

        return $h > 0
            ? sprintf('%02d:%02d:%02d', $h, $m, $s)
            : sprintf('%02d:%02d', $m, $s);
    }

    /**
     * Format an AiProcessingJob into a clean, uniform API payload for frontend components.
     *
     * @return array<string, mixed>|null
     */
    public static function formatJob(?AiProcessingJob $job): ?array
    {
        if (! $job) {
            return null;
        }

        $langCode = $job->detected_language;
        $meta = is_array($job->meta) ? $job->meta : [];
        $fallbackNames = $langCode && isset(self::LANGUAGE_NAMES[$langCode])
            ? self::LANGUAGE_NAMES[$langCode]
            : ['ar' => $langCode ? strtoupper($langCode) : null, 'en' => $langCode ? strtoupper($langCode) : null];

        return [
            'id' => $job->uuid,
            'taskType' => $job->task_type,
            'status' => $job->status,
            'progress' => (int) $job->progress,
            'stage' => $job->stage,
            'model' => $job->model_name,
            'requestedLanguage' => $job->requested_language ?: 'auto',
            'detectedLanguage' => $langCode,
            'detectedLanguageNameAr' => $meta['detectedLanguageNameAr'] ?? $fallbackNames['ar'],
            'detectedLanguageNameEn' => $meta['detectedLanguageNameEn'] ?? $fallbackNames['en'],
            'languageProbability' => $job->language_probability !== null ? round((float) $job->language_probability, 4) : null,
            'durationSeconds' => $job->duration_seconds,
            'segmentsCount' => (int) $job->segments_count,
            'processingSeconds' => $meta['processingSeconds'] ?? null,
            'device' => $meta['device'] ?? null,
            'error' => $job->error_message,
            'startedAt' => $job->started_at?->toIso8601String(),
            'completedAt' => $job->completed_at?->toIso8601String(),
        ];
    }
}
