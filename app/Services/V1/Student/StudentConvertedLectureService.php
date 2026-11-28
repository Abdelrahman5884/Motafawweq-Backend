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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StudentConvertedLectureService extends BaseService
{
    private const ALLOWED_OUTPUTS = ['graph', 'transcript', 'topics', 'quiz'];

    private const ALLOWED_MIME_PREFIXES = [
        'audio/',
        'video/',
        'application/pdf',
        'text/plain',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    private const ALLOWED_DOC_EXTENSIONS = ['pdf', 'txt', 'md', 'docx', 'pptx'];

    private const ALLOWED_MEDIA_EXTENSIONS = ['mp3', 'wav', 'm4a', 'ogg', 'webm', 'aac', 'flac', 'mp4', 'mkv', 'mov', 'avi'];

    public function __construct(
        protected StudentAccessService $accessService,
        protected AiMicroserviceClient $aiClient
    ) {}

    public function list(User $student): array
    {
        return StudentConvertedLecture::forStudent($student->id)
            ->with(['lesson:id,uuid', 'latestSttJob', 'latestAnalysisJob'])
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
            $lecture = $lecture->fresh(['lesson:id,uuid', 'latestSttJob', 'latestAnalysisJob']);
        }

        // Seamless STT -> LLM Analysis auto-chaining for Student Smart Lecture
        if ($lecture->latestSttJob && $lecture->latestSttJob->status === AiProcessingJob::STATUS_COMPLETED) {
            $this->maybeChainPendingLectureAnalysis($lecture->latestSttJob, $lecture, $student);
            $lecture = $lecture->fresh(['lesson:id,uuid', 'latestSttJob', 'latestAnalysisJob']);
        }

        if ($lecture->latestAnalysisJob && $lecture->latestAnalysisJob->isActive()) {
            $this->syncLectureAnalysisJob($lecture->latestAnalysisJob, $lecture);
            $lecture = $lecture->fresh(['lesson:id,uuid', 'latestSttJob', 'latestAnalysisJob']);
        }

        return $this->format($lecture, true);
    }

    /**
     * Poll live AI STT + Analysis progress and return the updated converted lecture payload.
     *
     * @return array<string, mixed>
     */
    public function getStatus(User $student, string $uuid): array
    {
        return $this->show($student, $uuid);
    }

    /**
     * Generate (or regenerate) AI MCQ quiz questions on demand for an owned converted lecture.
     *
     * @return array<string, mixed>
     */
    public function generateQuiz(User $student, string $uuid, int $quizCount = 5): array
    {
        $lecture = $this->findOwned($student, $uuid);
        $segments = is_array($lecture->transcript) ? $lecture->transcript : [];

        if (empty($segments)) {
            throw ValidationException::withMessages([
                'transcript' => [__('messages.ai.transcript_required_for_analysis')],
            ]);
        }

        $safeCount = max(2, min(10, $quizCount));
        $outputs = is_array($lecture->outputs) ? $lecture->outputs : [];
        if (! in_array('quiz', $outputs, true)) {
            $outputs[] = 'quiz';
            $lecture->update(['outputs' => array_values(array_unique($outputs))]);
        }

        $this->dispatchLectureAnalysisJob($student, $lecture, ['quiz'], $safeCount);

        return $this->format($lecture->fresh(['lesson:id,uuid', 'latestSttJob', 'latestAnalysisJob']), true);
    }

    /**
     * Register a lecture conversion request and trigger real AI STT + Concept Map + Topics + Quiz generation.
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
        $isDocument = false;
        $storedMediaDisk = null;
        $storedMediaPath = null;
        $storedMediaMime = null;
        $sourceUrl = null;

        if (! empty($data['embed_url'])) {
            $candidateUrl = trim((string) $data['embed_url']);
            $this->assertSafeExternalUrl($candidateUrl);
            $sourceUrl = mb_substr($candidateUrl, 0, 500);
        }

        if ($file) {
            $mime = (string) $file->getMimeType();
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $allowed = false;
            foreach (self::ALLOWED_MIME_PREFIXES as $prefix) {
                if (str_starts_with($mime, $prefix)) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed && in_array($ext, self::ALLOWED_DOC_EXTENSIONS, true)) {
                $allowed = true;
            }
            if (! $allowed && in_array($ext, self::ALLOWED_MEDIA_EXTENSIONS, true)) {
                $allowed = true;
            }
            if (! $allowed) {
                throw ValidationException::withMessages([
                    'file' => ['نوع الملف غير مدعوم. يرجى رفع ملف صوت أو فيديو أو PDF أو Word (DOCX) أو PowerPoint (PPTX) أو نصي.'],
                ]);
            }
            $isAudioOrVideo = str_starts_with($mime, 'audio/')
                || str_starts_with($mime, 'video/')
                || in_array($ext, self::ALLOWED_MEDIA_EXTENSIONS, true);
            $isDocument = ! $isAudioOrVideo && (
                str_starts_with($mime, 'application/pdf')
                || str_starts_with($mime, 'text/plain')
                || str_contains($mime, 'wordprocessingml')
                || str_contains($mime, 'presentationml')
                || in_array($ext, self::ALLOWED_DOC_EXTENSIONS, true)
            );
            $fileName = mb_substr(preg_replace('/[^\pL\pN._\- ]/u', '', $file->getClientOriginalName()) ?: 'lecture', 0, 200);
            $fileSize = round($file->getSize() / (1024 * 1024), 1).' MB';

            if ($isAudioOrVideo) {
                $safeExt = in_array($ext, self::ALLOWED_MEDIA_EXTENSIONS, true) ? $ext : 'webm';
                $storedMediaDisk = 'local';
                $storedMediaPath = $file->storeAs(
                    'student-converted-lectures/'.$student->id,
                    (string) Str::uuid().'.'.$safeExt,
                    $storedMediaDisk
                );
                $storedMediaMime = mb_substr($mime ?: ($safeExt === 'mp4' ? 'video/mp4' : 'audio/webm'), 0, 120);
            }
        } elseif (! empty($data['file_name'])) {
            $fileName = mb_substr(trim(strip_tags((string) $data['file_name'])), 0, 200);
            $fileSize = isset($data['file_size']) ? mb_substr(trim(strip_tags((string) $data['file_size'])), 0, 60) : null;
        }

        $linkedLesson = null;
        $lessonId = null;
        if (! empty($data['lesson_uuid'])) {
            $lesson = Lesson::with('transcriptSegments')->where('uuid', $data['lesson_uuid'])->first();
            if ($lesson) {
                $this->accessService->assertCanAccess($student, $lesson, (int) $lesson->teacher_id, (int) $lesson->course_id);
                $linkedLesson = $lesson;
                $lessonId = $lesson->id;
            }
        }

        $title = trim(strip_tags((string) ($data['title_ar'] ?? '')));
        if ($title === '') {
            $title = $linkedLesson?->title_ar
                ?: ($fileName ? preg_replace('/\.[^.]+$/', '', $fileName) : ($sourceUrl ? 'محاضرة من رابط فيديو' : 'محاضرة محولة'));
        }

        $analysisTasks = $this->mapOutputsToAnalysisTasks($outputs);
        $initialTranscript = [];
        if (! $file && ! $sourceUrl && $linkedLesson && $linkedLesson->transcriptSegments->isNotEmpty()) {
            $initialTranscript = $linkedLesson->transcriptSegments->map(fn ($seg, $idx) => [
                'id' => (string) Str::uuid(),
                'speaker' => $seg->speaker ?: 'المعلم',
                'startSeconds' => max(0, (int) $seg->start_seconds),
                'endSeconds' => max(0, (int) $seg->start_seconds + 20),
                'timestamp' => $seg->timestamp_label ?: $this->formatSeconds((int) $seg->start_seconds),
                'text' => (string) ($seg->text_ar ?: $seg->text_en ?: ''),
                'textAr' => (string) ($seg->text_ar ?: $seg->text_en ?: ''),
                'textEn' => (string) ($seg->text_en ?: $seg->text_ar ?: ''),
                'order' => $idx + 1,
            ])->values()->all();
        }

        $lessonFallbackUrl = (! $file && ! $sourceUrl && empty($initialTranscript) && $linkedLesson && $linkedLesson->embed_url)
            ? (string) $linkedLesson->embed_url
            : null;
        $lessonFallbackMediaPath = (! $file && ! $sourceUrl && empty($initialTranscript) && $linkedLesson && $linkedLesson->media_path)
            ? (string) $linkedLesson->media_path
            : null;

        $willProcessAi = $isAudioOrVideo
            || $isDocument
            || $sourceUrl !== null
            || ! empty($initialTranscript)
            || $lessonFallbackUrl !== null
            || $lessonFallbackMediaPath !== null;

        $lecture = StudentConvertedLecture::create([
            'student_id' => $student->id,
            'lesson_id' => $lessonId,
            'title_ar' => mb_substr($title, 0, 255),
            'subject_ar' => isset($data['subject_ar'])
                ? mb_substr(trim(strip_tags((string) $data['subject_ar'])), 0, 120)
                : $linkedLesson?->subject_ar,
            'grade_ar' => isset($data['grade_ar'])
                ? mb_substr(trim(strip_tags((string) $data['grade_ar'])), 0, 120)
                : $linkedLesson?->grade_ar,
            'file_name' => $fileName ?: ($sourceUrl ? parse_url($sourceUrl, PHP_URL_HOST) : null),
            'file_size' => $fileSize,
            'media_disk' => $storedMediaDisk,
            'media_path' => $storedMediaPath,
            'media_mime_type' => $storedMediaMime,
            'source_url' => $sourceUrl ?: $lessonFallbackUrl,
            'duration_label' => $linkedLesson?->duration_label,
            'summary_ar' => $willProcessAi
                ? 'جاري تحليل المحاضرة وتوليد المخرجات المطلوبة بالذكاء الاصطناعي...'
                : 'تم استلام المحاضرة وتسجيل طلب التحويل بنجاح.',
            'outputs' => $outputs,
            'transcript' => $initialTranscript,
            'topics' => [],
            'mindmap' => null,
            'quiz_questions' => [],
            'questions_count' => 0,
            'concepts_count' => 0,
        ]);

        $requestedLang = isset($data['language']) ? strtolower(trim((string) $data['language'])) : null;
        if ($requestedLang === '' || $requestedLang === 'auto') {
            $requestedLang = null;
        }

        // Case 1: Audio/Video uploaded -> Run Hybrid STT from stored file, then auto-chain LLM Analysis
        if ($file && $isAudioOrVideo) {
            $dispatchPath = ($storedMediaDisk && $storedMediaPath && Storage::disk($storedMediaDisk)->exists($storedMediaPath))
                ? Storage::disk($storedMediaDisk)->path($storedMediaPath)
                : $file->getRealPath();

            $this->dispatchFileSttJob($student, $lecture, $dispatchPath, $fileName ?: 'lecture.webm', $requestedLang, $analysisTasks);
        }
        // Case 2: YouTube / external video URL -> Run URL STT, then auto-chain LLM Analysis
        elseif ($sourceUrl !== null) {
            $this->dispatchUrlSttJob($student, $lecture, $sourceUrl, $requestedLang, $analysisTasks);
        }
        // Case 3: PDF / DOCX / PPTX / TXT uploaded -> Dispatch directly to AI document analysis endpoint
        elseif ($file && $isDocument) {
            $docTasks = ! empty($analysisTasks) ? $analysisTasks : ['summary', 'chapters'];
            try {
                $remote = $this->aiClient->startDocumentAnalysisJob(
                    $file->getRealPath(),
                    $fileName ?: 'lecture.pdf',
                    [
                        'title' => $lecture->title_ar,
                        'subject' => (string) ($lecture->subject_ar ?: ''),
                        'grade' => (string) ($lecture->grade_ar ?: ''),
                        'language' => $requestedLang ?: 'auto',
                        'tasks' => implode(',', $docTasks),
                        'quiz_count' => (int) config('ai.llm.default_quiz_count', 5),
                    ]
                );

                AiProcessingJob::create([
                    'user_id' => $student->id,
                    'converted_lecture_id' => $lecture->id,
                    'task_type' => AiProcessingJob::TASK_LESSON_ANALYSIS,
                    'status' => (string) ($remote['status'] ?? AiProcessingJob::STATUS_QUEUED),
                    'progress' => max(5, min(95, (int) ($remote['progress'] ?? 8))),
                    'stage' => (string) ($remote['stage'] ?? 'extracting_document'),
                    'remote_job_id' => $remote['job_id'] ?? null,
                    'model_name' => (string) ($remote['model'] ?? config('ai.llm.default_model', 'Qwen/Qwen2.5-3B-Instruct')),
                    'meta' => ['tasks' => $docTasks],
                    'started_at' => now(),
                ]);
            } catch (RuntimeException $e) {
                Log::warning('Student smart lecture document analysis dispatch failed', [
                    'lecture_uuid' => $lecture->uuid,
                    'error' => $e->getMessage(),
                ]);

                AiProcessingJob::create([
                    'user_id' => $student->id,
                    'converted_lecture_id' => $lecture->id,
                    'task_type' => AiProcessingJob::TASK_LESSON_ANALYSIS,
                    'status' => AiProcessingJob::STATUS_FAILED,
                    'progress' => 0,
                    'stage' => 'failed',
                    'model_name' => (string) config('ai.llm.default_model', 'Qwen/Qwen2.5-3B-Instruct'),
                    'error_message' => mb_substr($e->getMessage(), 0, 500),
                    'meta' => ['tasks' => $docTasks],
                    'started_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        }
        // Case 4: Linked course lesson already has transcript -> Directly dispatch LLM analysis
        elseif (! empty($initialTranscript) && ! empty($analysisTasks)) {
            try {
                $this->dispatchLectureAnalysisJob($student, $lecture, $analysisTasks, (int) config('ai.llm.default_quiz_count', 5));
            } catch (RuntimeException $e) {
                Log::warning('Student linked lesson AI analysis dispatch failed', [
                    'lecture_uuid' => $lecture->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        // Case 5: Linked course lesson has uploaded media (no transcript yet) -> Run STT -> LLM chain
        elseif ($lessonFallbackMediaPath !== null && $linkedLesson) {
            $lDisk = $linkedLesson->media_disk ?: 'local';
            if (Storage::disk($lDisk)->exists($lessonFallbackMediaPath)) {
                $this->dispatchFileSttJob(
                    $student,
                    $lecture,
                    Storage::disk($lDisk)->path($lessonFallbackMediaPath),
                    $linkedLesson->media_original_name ?: 'lesson.mp4',
                    $requestedLang,
                    $analysisTasks
                );
            }
        }
        // Case 6: Linked course lesson has YouTube / video embed URL -> Run URL STT -> LLM chain
        elseif ($lessonFallbackUrl !== null) {
            $this->dispatchUrlSttJob($student, $lecture, $lessonFallbackUrl, $requestedLang, $analysisTasks);
        }

        $this->logSecurityEvent('student.lecture.converted', ['lecture_uuid' => $lecture->uuid], 'info', $lecture);

        return $this->format($lecture->fresh(['lesson:id,uuid', 'latestSttJob', 'latestAnalysisJob']), true);
    }

    /**
     * Retry AI processing on an owned converted lecture (if previous STT or analysis failed).
     *
     * @return array<string, mixed>
     */
    public function retry(User $student, string $uuid): array
    {
        $lecture = $this->findOwned($student, $uuid);
        $outputs = is_array($lecture->outputs) && ! empty($lecture->outputs)
            ? $lecture->outputs
            : ['graph', 'transcript', 'topics', 'quiz'];
        $analysisTasks = $this->mapOutputsToAnalysisTasks($outputs);

        if (! empty($lecture->transcript)) {
            $tasks = ! empty($analysisTasks) ? $analysisTasks : ['concept_graph', 'chapters', 'summary'];
            $this->dispatchLectureAnalysisJob($student, $lecture, $tasks, (int) config('ai.llm.default_quiz_count', 5));
        } elseif ($lecture->media_path && Storage::disk($lecture->media_disk ?: 'local')->exists($lecture->media_path)) {
            $this->dispatchFileSttJob(
                $student,
                $lecture,
                Storage::disk($lecture->media_disk ?: 'local')->path($lecture->media_path),
                $lecture->file_name ?: 'lecture.webm',
                $lecture->latestSttJob?->requested_language,
                $analysisTasks
            );
        } elseif ($lecture->source_url) {
            $this->dispatchUrlSttJob(
                $student,
                $lecture,
                $lecture->source_url,
                $lecture->latestSttJob?->requested_language,
                $analysisTasks
            );
        } else {
            throw ValidationException::withMessages([
                'lecture' => [__('messages.ai.converted_lecture_nothing_to_retry')],
            ]);
        }

        return $this->format($lecture->fresh(['lesson:id,uuid', 'latestSttJob', 'latestAnalysisJob']), true);
    }

    public function delete(User $student, string $uuid): bool
    {
        $lecture = $this->findOwned($student, $uuid);
        if ($lecture->media_path) {
            $disk = $lecture->media_disk ?: 'local';
            if (Storage::disk($disk)->exists($lecture->media_path)) {
                Storage::disk($disk)->delete($lecture->media_path);
            }
        }
        $lecture->delete();

        return true;
    }

    /**
     * @param  list<string>  $analysisTasks
     */
    protected function dispatchFileSttJob(
        User $student,
        StudentConvertedLecture $lecture,
        string $localFilePath,
        string $originalFilename,
        ?string $requestedLang,
        array $analysisTasks
    ): void {
        try {
            $remote = $this->aiClient->startFileTranscriptionJob(
                $localFilePath,
                $originalFilename,
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
                'model_name' => (string) ($remote['model'] ?? config('ai.stt.default_model', 'mohammedaly22/QwenCleo-ASR')),
                'requested_language' => $requestedLang,
                'meta' => [
                    'pendingAnalysisTasks' => $analysisTasks,
                ],
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
                'model_name' => (string) config('ai.stt.default_model', 'mohammedaly22/QwenCleo-ASR'),
                'requested_language' => $requestedLang,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<string>  $analysisTasks
     */
    protected function dispatchUrlSttJob(
        User $student,
        StudentConvertedLecture $lecture,
        string $url,
        ?string $requestedLang,
        array $analysisTasks
    ): void {
        try {
            $remote = $this->aiClient->startUrlTranscriptionJob($url, $requestedLang, 'المحاضر');

            AiProcessingJob::create([
                'user_id' => $student->id,
                'converted_lecture_id' => $lecture->id,
                'task_type' => AiProcessingJob::TASK_SPEECH_TO_TEXT,
                'status' => (string) ($remote['status'] ?? AiProcessingJob::STATUS_QUEUED),
                'progress' => max(5, min(95, (int) ($remote['progress'] ?? 8))),
                'stage' => (string) ($remote['stage'] ?? 'downloading_media'),
                'remote_job_id' => $remote['job_id'] ?? null,
                'model_name' => (string) ($remote['model'] ?? config('ai.stt.default_model', 'mohammedaly22/QwenCleo-ASR')),
                'requested_language' => $requestedLang,
                'meta' => [
                    'pendingAnalysisTasks' => $analysisTasks,
                    'sourceUrl' => $url,
                ],
                'started_at' => now(),
            ]);
        } catch (RuntimeException $e) {
            Log::warning('Student smart lecture URL STT dispatch failed', [
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
                'model_name' => (string) config('ai.stt.default_model', 'mohammedaly22/QwenCleo-ASR'),
                'requested_language' => $requestedLang,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }
    }

    protected function assertSafeExternalUrl(string $url): void
    {
        $parsed = parse_url($url);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) ($parsed['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            throw ValidationException::withMessages([
                'embed_url' => ['يرجى إدخال رابط HTTPS صحيح (مثل رابط يوتيوب أو فيميو).'],
            ]);
        }

        if (
            in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)
        ) {
            throw ValidationException::withMessages([
                'embed_url' => ['الرابط المدخل غير مسموح به أمنياً.'],
            ]);
        }
    }

    /**
     * Map frontend Smart Lecture output keys to AI microservice analysis task names.
     *
     * @param  list<string>  $outputs
     * @return list<string>
     */
    protected function mapOutputsToAnalysisTasks(array $outputs): array
    {
        $tasks = [];
        if (in_array('topics', $outputs, true)) {
            $tasks[] = 'chapters';
        }
        if (in_array('graph', $outputs, true)) {
            $tasks[] = 'concept_graph';
        }
        if (in_array('quiz', $outputs, true)) {
            $tasks[] = 'quiz';
        }
        if (! empty($tasks)) {
            $tasks[] = 'summary';
        }

        return array_values(array_unique($tasks));
    }

    /**
     * @param  list<string>  $tasks
     */
    protected function dispatchLectureAnalysisJob(
        User $student,
        StudentConvertedLecture $lecture,
        array $tasks,
        int $quizCount = 5
    ): AiProcessingJob {
        $rawSegments = is_array($lecture->transcript) ? $lecture->transcript : [];
        $segmentsPayload = [];
        $maxSecs = 0;

        foreach ($rawSegments as $seg) {
            if (! is_array($seg)) {
                continue;
            }
            $txt = trim((string) ($seg['textAr'] ?? $seg['text'] ?? ''));
            if ($txt === '') {
                continue;
            }
            $startS = max(0, (int) ($seg['startSeconds'] ?? $seg['start_seconds'] ?? 0));
            $endS = max($startS, (int) ($seg['endSeconds'] ?? $seg['end_seconds'] ?? ($startS + 20)));
            $maxSecs = max($maxSecs, $endS);

            $segmentsPayload[] = [
                'start_seconds' => $startS,
                'end_seconds' => $endS,
                'timestamp' => (string) ($seg['timestamp'] ?? $this->formatSeconds($startS)),
                'speaker' => (string) ($seg['speaker'] ?? 'المحاضر'),
                'text' => $txt,
            ];
        }

        $remote = $this->aiClient->startAnalysisJob([
            'title' => (string) $lecture->title_ar,
            'subject' => (string) ($lecture->subject_ar ?: ''),
            'grade' => (string) ($lecture->grade_ar ?: ''),
            'language' => $lecture->latestSttJob?->detected_language ?: 'auto',
            'duration_seconds' => $maxSecs,
            'tasks' => array_values(array_unique($tasks)),
            'quiz_count' => max(2, min(12, $quizCount)),
            'segments' => $segmentsPayload,
        ]);

        return AiProcessingJob::create([
            'user_id' => $student->id,
            'converted_lecture_id' => $lecture->id,
            'task_type' => count($tasks) === 1 && $tasks[0] === 'quiz'
                ? AiProcessingJob::TASK_QUIZ
                : AiProcessingJob::TASK_LESSON_ANALYSIS,
            'status' => (string) ($remote['status'] ?? AiProcessingJob::STATUS_QUEUED),
            'progress' => max(5, min(95, (int) ($remote['progress'] ?? 8))),
            'stage' => (string) ($remote['stage'] ?? 'queued'),
            'remote_job_id' => $remote['job_id'] ?? null,
            'model_name' => (string) ($remote['model'] ?? config('ai.llm.default_model', 'Qwen/Qwen2.5-3B-Instruct')),
            'meta' => [
                'tasks' => array_values(array_unique($tasks)),
            ],
            'started_at' => now(),
        ]);
    }

    protected function maybeChainPendingLectureAnalysis(
        AiProcessingJob $sttJob,
        StudentConvertedLecture $lecture,
        User $student
    ): void {
        $meta = is_array($sttJob->meta) ? $sttJob->meta : [];
        $pending = is_array($meta['pendingAnalysisTasks'] ?? null) ? array_values($meta['pendingAnalysisTasks']) : [];

        if (empty($pending) || ! empty($meta['chainedAnalysisJobUuid'])) {
            return;
        }

        $tasksToDispatch = $this->transaction(function () use ($sttJob): array {
            /** @var AiProcessingJob $locked */
            $locked = AiProcessingJob::where('id', $sttJob->id)->lockForUpdate()->firstOrFail();
            $lockedMeta = is_array($locked->meta) ? $locked->meta : [];
            $lockedPending = is_array($lockedMeta['pendingAnalysisTasks'] ?? null)
                ? array_values($lockedMeta['pendingAnalysisTasks'])
                : [];

            if (empty($lockedPending) || ! empty($lockedMeta['chainedAnalysisJobUuid'])) {
                return [];
            }

            $lockedMeta['chainedAnalysisJobUuid'] = 'dispatching';
            $locked->update(['meta' => $lockedMeta]);

            return $lockedPending;
        });

        if (empty($tasksToDispatch)) {
            return;
        }

        try {
            $lecture->refresh();
            if (empty($lecture->transcript)) {
                $updatedMeta = is_array($sttJob->fresh()->meta) ? $sttJob->fresh()->meta : [];
                $updatedMeta['pendingAnalysisTasks'] = [];
                $updatedMeta['chainedAnalysisJobUuid'] = 'skipped_empty_transcript';
                $sttJob->update(['meta' => $updatedMeta]);

                return;
            }

            $analysisJob = $this->dispatchLectureAnalysisJob(
                $student,
                $lecture,
                $tasksToDispatch,
                (int) config('ai.llm.default_quiz_count', 5)
            );

            $updatedMeta = is_array($sttJob->fresh()->meta) ? $sttJob->fresh()->meta : [];
            $updatedMeta['pendingAnalysisTasks'] = [];
            $updatedMeta['chainedAnalysisJobUuid'] = $analysisJob->uuid;
            $sttJob->update(['meta' => $updatedMeta]);
        } catch (\Throwable $e) {
            Log::warning('Failed to chain student smart lecture analysis after STT', [
                'lecture_uuid' => $lecture->uuid,
                'error' => $e->getMessage(),
            ]);

            AiProcessingJob::create([
                'user_id' => $student->id,
                'converted_lecture_id' => $lecture->id,
                'task_type' => AiProcessingJob::TASK_LESSON_ANALYSIS,
                'status' => AiProcessingJob::STATUS_FAILED,
                'progress' => 0,
                'stage' => 'failed',
                'model_name' => (string) config('ai.llm.default_model', 'Qwen/Qwen2.5-3B-Instruct'),
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'meta' => ['tasks' => $tasksToDispatch],
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            $updatedMeta = is_array($sttJob->fresh()->meta) ? $sttJob->fresh()->meta : [];
            $updatedMeta['pendingAnalysisTasks'] = [];
            $updatedMeta['chainedAnalysisJobUuid'] = 'failed';
            $sttJob->update(['meta' => $updatedMeta]);
        }
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
                foreach ($rawSegments as $seg) {
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

                $existingMeta = is_array($locked->meta) ? $locked->meta : [];
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
                    'meta' => array_merge($existingMeta, [
                        'device' => $result['device'] ?? null,
                        'computeType' => $result['compute_type'] ?? null,
                        'processingSeconds' => $result['processing_seconds'] ?? null,
                        'detectedLanguageNameAr' => $result['detected_language_name_ar'] ?? null,
                        'detectedLanguageNameEn' => $result['detected_language_name_en'] ?? null,
                    ]),
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

    protected function syncLectureAnalysisJob(AiProcessingJob $job, StudentConvertedLecture $lecture): AiProcessingJob
    {
        if (! $job->remote_job_id || $job->isTerminal()) {
            return $job;
        }

        try {
            $remote = $this->aiClient->getAnalysisJob($job->remote_job_id);
        } catch (RuntimeException $e) {
            Log::warning('Transient AI analysis polling failure for student lecture', [
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

            $persisted = $this->transaction(function () use ($job, $lecture, $result) {
                /** @var AiProcessingJob $locked */
                $locked = AiProcessingJob::where('id', $job->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === AiProcessingJob::STATUS_COMPLETED) {
                    return $locked;
                }

                $updates = [];

                // If document analysis extracted segments, populate transcript
                if (empty($lecture->transcript) && is_array($result['extracted_segments'] ?? null) && count($result['extracted_segments']) > 0) {
                    $formattedSegments = [];
                    foreach ($result['extracted_segments'] as $seg) {
                        $txt = trim(strip_tags((string) ($seg['text'] ?? '')));
                        if ($txt === '') {
                            continue;
                        }
                        $startSecs = max(0, (int) ($seg['start_seconds'] ?? 0));
                        $formattedSegments[] = [
                            'id' => (string) Str::uuid(),
                            'speaker' => trim(strip_tags((string) ($seg['speaker'] ?? 'المستند'))) ?: 'المستند',
                            'startSeconds' => $startSecs,
                            'endSeconds' => max($startSecs, (int) ($seg['end_seconds'] ?? $startSecs)),
                            'timestamp' => (string) ($seg['timestamp'] ?? $this->formatSeconds($startSecs)),
                            'text' => $txt,
                            'textAr' => $txt,
                            'textEn' => $txt,
                            'order' => count($formattedSegments) + 1,
                        ];
                    }
                    if (! empty($formattedSegments)) {
                        $updates['transcript'] = $formattedSegments;
                    }
                }

                // Chapters / Topics
                if (is_array($result['chapters'] ?? null) && count($result['chapters']) > 0) {
                    $formattedTopics = [];
                    foreach ($result['chapters'] as $idx => $ch) {
                        if (! is_array($ch)) {
                            continue;
                        }
                        $titleAr = trim(strip_tags((string) ($ch['title_ar'] ?? $ch['titleAr'] ?? '')));
                        $titleEn = trim(strip_tags((string) ($ch['title_en'] ?? $ch['titleEn'] ?? $titleAr)));
                        if ($titleAr === '') {
                            continue;
                        }
                        $startSecs = max(0, (int) ($ch['start_seconds'] ?? $ch['startSeconds'] ?? 0));
                        $formattedTopics[] = [
                            'id' => (string) ($ch['id'] ?? 'topic-'.($idx + 1)),
                            'titleAr' => $titleAr,
                            'titleEn' => $titleEn ?: $titleAr,
                            'title' => $titleEn ?: $titleAr,
                            'startSeconds' => $startSecs,
                            'timestamp' => (string) ($ch['timestamp'] ?? $this->formatSeconds($startSecs)),
                        ];
                    }
                    if (! empty($formattedTopics)) {
                        $updates['topics'] = $formattedTopics;
                    }
                }

                // NotebookLM Concept Graph
                if (is_array($result['concept_graph'] ?? null)) {
                    $updates['mindmap'] = $result['concept_graph'];
                    $updates['concepts_count'] = max(1, (int) ($result['concepts_count'] ?? 1));
                }

                // Quiz Questions
                if (is_array($result['quiz_questions'] ?? null) && count($result['quiz_questions']) > 0) {
                    $updates['quiz_questions'] = $result['quiz_questions'];
                    $updates['questions_count'] = count($result['quiz_questions']);
                }

                // Summary
                if (! empty($result['summary_ar'])) {
                    $updates['summary_ar'] = mb_substr(trim(strip_tags((string) $result['summary_ar'])), 0, 600);
                }

                if (! empty($updates)) {
                    $lecture->update($updates);
                }

                $existingMeta = is_array($locked->meta) ? $locked->meta : [];
                $locked->update([
                    'status' => AiProcessingJob::STATUS_COMPLETED,
                    'progress' => 100,
                    'stage' => 'completed',
                    'model_name' => (string) ($result['model'] ?? $locked->model_name),
                    'error_message' => null,
                    'meta' => array_merge($existingMeta, [
                        'device' => $result['device'] ?? null,
                        'processingSeconds' => $result['processing_seconds'] ?? null,
                        'chaptersCount' => isset($updates['topics']) ? count($updates['topics']) : null,
                        'conceptsCount' => $updates['concepts_count'] ?? null,
                        'questionsCount' => $updates['questions_count'] ?? null,
                    ]),
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
     * @return array{status: string, progress: int, activePhase: string, stage: string, error: ?string}
     */
    protected function computeLecturePipelineState(?AiProcessingJob $sttJob, ?AiProcessingJob $analysisJob): array
    {
        $sttMeta = is_array($sttJob?->meta) ? $sttJob->meta : [];
        $hasPendingChain = ! empty($sttMeta['pendingAnalysisTasks']) && empty($sttMeta['chainedAnalysisJobUuid']);
        $isChained = $hasPendingChain
            || ($sttJob && $analysisJob && $analysisJob->created_at?->gte($sttJob->created_at));

        if ($sttJob && $sttJob->isActive()) {
            $pct = $hasPendingChain
                ? max(5, min(55, (int) round($sttJob->progress * 0.55)))
                : max(5, min(99, (int) $sttJob->progress));

            return [
                'status' => $sttJob->status,
                'progress' => $pct,
                'activePhase' => 'stt',
                'stage' => (string) ($sttJob->stage ?: 'transcribing'),
                'error' => null,
            ];
        }

        if ($analysisJob && $analysisJob->isActive()) {
            $pct = $isChained
                ? max(58, min(99, 55 + (int) round($analysisJob->progress * 0.44)))
                : max(5, min(99, (int) $analysisJob->progress));

            return [
                'status' => $analysisJob->status,
                'progress' => $pct,
                'activePhase' => 'analysis',
                'stage' => (string) ($analysisJob->stage ?: 'generating_concept_graph'),
                'error' => null,
            ];
        }

        $latest = ($sttJob && $analysisJob)
            ? ($analysisJob->id >= $sttJob->id ? $analysisJob : $sttJob)
            : ($analysisJob ?? $sttJob);

        if ($latest && $latest->status === AiProcessingJob::STATUS_FAILED) {
            return [
                'status' => AiProcessingJob::STATUS_FAILED,
                'progress' => (int) $latest->progress,
                'activePhase' => 'failed',
                'stage' => 'failed',
                'error' => $latest->error_message,
            ];
        }

        return [
            'status' => $latest ? $latest->status : 'completed',
            'progress' => 100,
            'activePhase' => 'completed',
            'stage' => 'completed',
            'error' => null,
        ];
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
            ->with(['lesson:id,uuid', 'latestSttJob', 'latestAnalysisJob'])
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
        $analysisJob = $l->relationLoaded('latestAnalysisJob') ? $l->latestAnalysisJob : null;
        $pipeline = $this->computeLecturePipelineState($sttJob, $analysisJob);

        $mediaUrl = null;
        $mediaType = 'document';
        if ($l->media_path && Storage::disk($l->media_disk ?: 'local')->exists($l->media_path)) {
            $mediaUrl = URL::temporarySignedRoute(
                'api.v1.student.converted-lectures.media.stream',
                now()->addHours(2),
                ['uuid' => $l->uuid]
            );
            $mime = strtolower((string) ($l->media_mime_type ?: ''));
            $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'audio';
        } elseif ($l->source_url) {
            $mediaType = 'url';
        } elseif ($l->lesson_id) {
            $mediaType = 'lesson';
        }

        $base = [
            'id' => $l->uuid,
            'lessonId' => $l->lesson?->uuid,
            'titleAr' => $l->title_ar,
            'subjectAr' => $l->subject_ar,
            'gradeAr' => $l->grade_ar,
            'fileName' => $l->file_name,
            'fileSize' => $l->file_size,
            'mediaUrl' => $mediaUrl,
            'mediaType' => $mediaType,
            'mediaMimeType' => $l->media_mime_type,
            'sourceUrl' => $l->source_url,
            'duration' => $l->duration_label,
            'summaryAr' => $l->summary_ar,
            'outputs' => $l->outputs ?? [],
            'questionsCount' => (int) $l->questions_count,
            'conceptsCount' => (int) $l->concepts_count,
            'keyTopics' => collect($l->topics ?? [])->pluck('titleAr')->filter()->take(5)->values()->all(),
            'sttJob' => LessonSpeechToTextService::formatJob($sttJob),
            'analysisJob' => LessonSpeechToTextService::formatJob($analysisJob),
            'overallStatus' => $pipeline['status'],
            'overallProgress' => $pipeline['progress'],
            'activePhase' => $pipeline['activePhase'],
            'activeStage' => $pipeline['stage'],
            'error' => $pipeline['error'],
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
