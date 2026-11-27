<?php

namespace App\Services\V1\AI;

use App\Http\Resources\V1\Teacher\LessonChapterResource;
use App\Http\Resources\V1\Teacher\LessonTranscriptResource;
use App\Models\AiProcessingJob;
use App\Models\Lesson;
use App\Models\LessonChapter;
use App\Models\LessonConceptGraph;
use App\Models\User;
use App\Repositories\Contracts\LessonRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LessonAiPipelineService extends BaseService
{
    private const ALLOWED_ANALYSIS_TASKS = ['chapters', 'concept_graph', 'summary'];

    private const MAX_GRAPH_DEPTH = 6;

    private const MAX_GRAPH_NODES = 150;

    public function __construct(
        protected LessonRepositoryInterface $lessonRepository,
        protected AiMicroserviceClient $aiClient,
        protected LessonSpeechToTextService $sttService
    ) {}

    /**
     * Launch the full requested AI pipeline for a lesson (STT -> Chapter Indexing -> NotebookLM Concept Graph).
     * Automatically chains LLM analysis immediately once STT finishes without requiring a queue worker.
     *
     * @param  array<string, mixed>|null  $requestedFeatures
     * @return array<string, mixed>
     */
    public function startPipeline(
        User $teacher,
        string $lessonUuid,
        ?array $requestedFeatures = null,
        ?string $language = null,
        bool $force = false
    ): array {
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        $storedFeatures = is_array($lesson->ai_features_requested) ? $lesson->ai_features_requested : [];
        $features = [
            'speechToText' => (bool) ($requestedFeatures['speechToText'] ?? $storedFeatures['speechToText'] ?? false),
            'chapterIndexing' => (bool) ($requestedFeatures['chapterIndexing'] ?? $storedFeatures['chapterIndexing'] ?? false),
            'conceptGraph' => (bool) ($requestedFeatures['conceptGraph'] ?? $storedFeatures['conceptGraph'] ?? false),
        ];

        if ($requestedFeatures !== null) {
            $lesson->update(['ai_features_requested' => $features]);
        }

        $analysisTasks = [];
        if ($features['chapterIndexing']) {
            $analysisTasks[] = 'chapters';
        }
        if ($features['conceptGraph']) {
            $analysisTasks[] = 'concept_graph';
        }

        $hasTranscript = $lesson->transcriptSegments->isNotEmpty();
        $hasMedia = ! empty($lesson->media_path) || ! empty($lesson->embed_url);
        $needsStt = ($features['speechToText'] && (! $hasTranscript || $force))
            || (! empty($analysisTasks) && ! $hasTranscript);

        if ($needsStt) {
            if (! $hasMedia) {
                throw ValidationException::withMessages([
                    'media' => [__('messages.ai.lesson_media_required')],
                ]);
            }

            $sttJob = $this->sttService->startForLesson($teacher, $lessonUuid, $language, $force);

            if (! empty($analysisTasks)) {
                if ($sttJob->isActive()) {
                    $this->attachPendingAnalysisTasks($sttJob, $analysisTasks);
                } elseif ($sttJob->status === AiProcessingJob::STATUS_COMPLETED) {
                    $lesson->load('transcriptSegments');
                    if ($lesson->transcriptSegments->isNotEmpty()) {
                        $this->dispatchAnalysisJob($teacher, $lesson, $analysisTasks, $force);
                    }
                }
            }
        } elseif (! empty($analysisTasks)) {
            $this->dispatchAnalysisJob($teacher, $lesson, $analysisTasks, $force);
        }

        return $this->getStatusForLesson($teacher, $lessonUuid);
    }

    /**
     * Trigger standalone AI generation for specific outputs (e.g. only `chapters` or only `concept_graph`).
     * If no transcript exists yet and media is attached, automatically runs STT first and chains analysis.
     *
     * @param  list<string>  $tasks
     * @return array<string, mixed>
     */
    public function startAnalysisTasks(
        User $teacher,
        string $lessonUuid,
        array $tasks,
        bool $force = true
    ): array {
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);
        $cleanTasks = $this->normalizeTasks($tasks);

        if (empty($cleanTasks)) {
            throw ValidationException::withMessages([
                'tasks' => [__('messages.ai.invalid_analysis_tasks')],
            ]);
        }

        // If no transcript exists yet, seamlessly transcribe first and chain the requested analysis tasks
        if ($lesson->transcriptSegments->isEmpty()) {
            $hasMedia = ! empty($lesson->media_path) || ! empty($lesson->embed_url);
            if (! $hasMedia) {
                throw ValidationException::withMessages([
                    'transcript' => [__('messages.ai.transcript_or_media_required')],
                ]);
            }

            $sttJob = $this->sttService->startForLesson($teacher, $lessonUuid, null, false);
            if ($sttJob->isActive()) {
                $this->attachPendingAnalysisTasks($sttJob, $cleanTasks);
            } elseif ($sttJob->status === AiProcessingJob::STATUS_COMPLETED) {
                $lesson->load('transcriptSegments');
                if ($lesson->transcriptSegments->isNotEmpty()) {
                    $this->dispatchAnalysisJob($teacher, $lesson, $cleanTasks, $force);
                }
            }

            return $this->getStatusForLesson($teacher, $lessonUuid);
        }

        $this->dispatchAnalysisJob($teacher, $lesson, $cleanTasks, $force);

        return $this->getStatusForLesson($teacher, $lessonUuid);
    }

    /**
     * Poll unified AI pipeline status for a teacher's lesson (STT + Chapters + NotebookLM Concept Graph).
     * Automatically chains pending analysis when STT completes and persists structured outputs atomically.
     *
     * @return array<string, mixed>
     */
    public function getStatusForLesson(User $teacher, string $lessonUuid): array
    {
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        $sttJob = AiProcessingJob::where('lesson_id', $lesson->id)
            ->where('task_type', AiProcessingJob::TASK_SPEECH_TO_TEXT)
            ->latest('id')
            ->first();

        if ($sttJob && $sttJob->isActive()) {
            $sttJob = $this->sttService->syncActiveJob($sttJob, $lesson, $teacher);
            if ($sttJob->status === AiProcessingJob::STATUS_COMPLETED) {
                $lesson->load('transcriptSegments');
            }
        }

        // Seamless STT -> LLM Analysis auto-chaining (atomic guard against duplicate dispatch on concurrent polls)
        if ($sttJob && $sttJob->status === AiProcessingJob::STATUS_COMPLETED) {
            $this->maybeChainPendingAnalysis($sttJob, $lesson, $teacher);
            $sttJob = $sttJob->fresh();
        }

        $analysisJob = AiProcessingJob::where('lesson_id', $lesson->id)
            ->whereIn('task_type', AiProcessingJob::ANALYSIS_TASK_TYPES)
            ->latest('id')
            ->first();

        if ($analysisJob && $analysisJob->isActive()) {
            $analysisJob = $this->syncAnalysisJobState($analysisJob, $lesson, $teacher);
            if ($analysisJob->status === AiProcessingJob::STATUS_COMPLETED) {
                $lesson->load(['chapters', 'conceptGraph']);
            }
        }

        $lesson->loadMissing(['transcriptSegments', 'chapters', 'conceptGraph']);

        $formattedStt = LessonSpeechToTextService::formatJob($sttJob);
        $formattedAnalysis = LessonSpeechToTextService::formatJob($analysisJob);
        $summary = $this->computeUnifiedSummary($sttJob, $analysisJob);

        // Keep `job` key backwards-compatible while also exposing separate `sttJob` & `analysisJob`
        $primaryJob = $summary['activePhase'] === 'analysis' && $formattedAnalysis
            ? $formattedAnalysis
            : ($formattedStt ?? $formattedAnalysis);

        $conceptsCount = $this->countConceptNodes($lesson->conceptGraph?->tree_data);

        return [
            'lessonId' => $lesson->uuid,
            'hasMedia' => ! empty($lesson->media_path) || ! empty($lesson->embed_url),
            'mediaMode' => $lesson->media_mode?->value ?? (string) $lesson->media_mode,
            'aiFeatures' => $lesson->ai_features_requested ?? [
                'speechToText' => true,
                'conceptGraph' => false,
                'chapterIndexing' => false,
            ],
            'job' => $primaryJob,
            'sttJob' => $formattedStt,
            'analysisJob' => $formattedAnalysis,
            'overallStatus' => $summary['status'],
            'overallProgress' => $summary['progress'],
            'activePhase' => $summary['activePhase'],
            'activeStage' => $summary['stage'],
            'error' => $summary['error'],
            'transcript' => LessonTranscriptResource::collection($lesson->transcriptSegments)->resolve(),
            'transcriptCount' => $lesson->transcriptSegments->count(),
            'chapters' => LessonChapterResource::collection($lesson->chapters)->resolve(),
            'chaptersCount' => $lesson->chapters->count(),
            'notebookMindMap' => $lesson->conceptGraph?->tree_data,
            'conceptsCount' => $conceptsCount,
            'durationSeconds' => (int) ($lesson->duration_seconds ?? 0),
            'durationFormatted' => $this->formatSecondsLabel((int) ($lesson->duration_seconds ?? 0)),
        ];
    }

    /**
     * Dispatch an LLM analysis job against the AI microservice for a lesson's existing transcript.
     *
     * @param  list<string>  $tasks
     */
    protected function dispatchAnalysisJob(
        User $teacher,
        Lesson $lesson,
        array $tasks,
        bool $force = false
    ): AiProcessingJob {
        $cleanTasks = $this->normalizeTasks($tasks);
        if (empty($cleanTasks)) {
            throw ValidationException::withMessages([
                'tasks' => [__('messages.ai.invalid_analysis_tasks')],
            ]);
        }

        if (! $force) {
            $existingActive = AiProcessingJob::where('lesson_id', $lesson->id)
                ->whereIn('task_type', AiProcessingJob::ANALYSIS_TASK_TYPES)
                ->whereIn('status', [AiProcessingJob::STATUS_QUEUED, AiProcessingJob::STATUS_PROCESSING])
                ->latest('id')
                ->first();

            if ($existingActive) {
                return $this->syncAnalysisJobState($existingActive, $lesson, $teacher);
            }
        }

        $lesson->loadMissing('transcriptSegments');
        if ($lesson->transcriptSegments->isEmpty()) {
            throw ValidationException::withMessages([
                'transcript' => [__('messages.ai.transcript_required_for_analysis')],
            ]);
        }

        $segmentsPayload = $lesson->transcriptSegments->map(fn ($seg) => [
            'start_seconds' => max(0, (int) $seg->start_seconds),
            'end_seconds' => max(0, (int) $seg->start_seconds + 20),
            'timestamp' => (string) ($seg->timestamp_label ?: $this->formatSecondsLabel((int) $seg->start_seconds)),
            'speaker' => (string) ($seg->speaker ?: ($teacher->name ?: 'المعلم')),
            'text' => trim((string) ($seg->text_ar ?: $seg->text_en ?: '')),
        ])->filter(fn (array $s) => $s['text'] !== '')->values()->all();

        if (empty($segmentsPayload)) {
            throw ValidationException::withMessages([
                'transcript' => [__('messages.ai.transcript_required_for_analysis')],
            ]);
        }

        $latestStt = AiProcessingJob::where('lesson_id', $lesson->id)
            ->where('task_type', AiProcessingJob::TASK_SPEECH_TO_TEXT)
            ->latest('id')
            ->first();

        $remoteResponse = $this->aiClient->startAnalysisJob([
            'title' => (string) ($lesson->title_ar ?: $lesson->title_en ?: ''),
            'subject' => (string) ($lesson->subject_ar ?: ''),
            'grade' => (string) ($lesson->grade_ar ?: ''),
            'language' => $latestStt?->detected_language ?: 'auto',
            'duration_seconds' => (int) ($lesson->duration_seconds ?? 0),
            'tasks' => $cleanTasks,
            'segments' => $segmentsPayload,
        ]);

        $taskType = count($cleanTasks) === 1
            ? ($cleanTasks[0] === 'chapters' ? AiProcessingJob::TASK_CHAPTERS : AiProcessingJob::TASK_CONCEPT_GRAPH)
            : AiProcessingJob::TASK_LESSON_ANALYSIS;

        $job = AiProcessingJob::create([
            'user_id' => $teacher->id,
            'lesson_id' => $lesson->id,
            'task_type' => $taskType,
            'status' => (string) ($remoteResponse['status'] ?? AiProcessingJob::STATUS_QUEUED),
            'progress' => max(4, min(95, (int) ($remoteResponse['progress'] ?? 6))),
            'stage' => (string) ($remoteResponse['stage'] ?? 'queued'),
            'remote_job_id' => $remoteResponse['job_id'] ?? null,
            'model_name' => (string) ($remoteResponse['model'] ?? config('ai.llm.default_model', 'Qwen/Qwen2.5-3B-Instruct')),
            'detected_language' => $latestStt?->detected_language,
            'meta' => [
                'tasks' => $cleanTasks,
            ],
            'started_at' => now(),
        ]);

        $this->logSecurityEvent(
            'teacher.ai.analysis_started',
            [
                'lesson_uuid' => $lesson->uuid,
                'ai_job_uuid' => $job->uuid,
                'tasks' => $cleanTasks,
                'model' => $job->model_name,
            ],
            'info',
            $lesson
        );

        return $job;
    }

    protected function attachPendingAnalysisTasks(AiProcessingJob $sttJob, array $tasks): void
    {
        $cleanTasks = $this->normalizeTasks($tasks);
        if (empty($cleanTasks)) {
            return;
        }

        $meta = is_array($sttJob->meta) ? $sttJob->meta : [];
        $existing = is_array($meta['pendingAnalysisTasks'] ?? null) ? $meta['pendingAnalysisTasks'] : [];
        $merged = array_values(array_unique(array_merge($existing, $cleanTasks)));
        $meta['pendingAnalysisTasks'] = $merged;
        unset($meta['chainedAnalysisJobUuid']);
        $sttJob->update(['meta' => $meta]);
    }

    protected function maybeChainPendingAnalysis(AiProcessingJob $sttJob, Lesson $lesson, User $teacher): void
    {
        $meta = is_array($sttJob->meta) ? $sttJob->meta : [];
        $pending = is_array($meta['pendingAnalysisTasks'] ?? null) ? $this->normalizeTasks($meta['pendingAnalysisTasks']) : [];

        if (empty($pending) || ! empty($meta['chainedAnalysisJobUuid'])) {
            return;
        }

        $tasksToDispatch = $this->transaction(function () use ($sttJob): array {
            /** @var AiProcessingJob $locked */
            $locked = AiProcessingJob::where('id', $sttJob->id)->lockForUpdate()->firstOrFail();
            $lockedMeta = is_array($locked->meta) ? $locked->meta : [];
            $lockedPending = is_array($lockedMeta['pendingAnalysisTasks'] ?? null)
                ? $this->normalizeTasks($lockedMeta['pendingAnalysisTasks'])
                : [];

            if (empty($lockedPending) || ! empty($lockedMeta['chainedAnalysisJobUuid'])) {
                return [];
            }

            // Mark claimed before making outbound HTTP call so concurrent polls cannot double-dispatch
            $lockedMeta['chainedAnalysisJobUuid'] = 'dispatching';
            $locked->update(['meta' => $lockedMeta]);

            return $lockedPending;
        });

        if (empty($tasksToDispatch)) {
            return;
        }

        try {
            $lesson->load('transcriptSegments');
            if ($lesson->transcriptSegments->isEmpty()) {
                $updatedMeta = is_array($sttJob->fresh()->meta) ? $sttJob->fresh()->meta : [];
                $updatedMeta['pendingAnalysisTasks'] = [];
                $updatedMeta['chainedAnalysisJobUuid'] = 'skipped_empty_transcript';
                $sttJob->update(['meta' => $updatedMeta]);

                return;
            }

            $analysisJob = $this->dispatchAnalysisJob($teacher, $lesson, $tasksToDispatch, true);

            $updatedMeta = is_array($sttJob->fresh()->meta) ? $sttJob->fresh()->meta : [];
            $updatedMeta['pendingAnalysisTasks'] = [];
            $updatedMeta['chainedAnalysisJobUuid'] = $analysisJob->uuid;
            $sttJob->update(['meta' => $updatedMeta]);
        } catch (\Throwable $e) {
            Log::warning('Failed to chain AI analysis after STT completion', [
                'lesson_uuid' => $lesson->uuid,
                'error' => $e->getMessage(),
            ]);

            AiProcessingJob::create([
                'user_id' => $teacher->id,
                'lesson_id' => $lesson->id,
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

    protected function syncAnalysisJobState(AiProcessingJob $job, Lesson $lesson, User $teacher): AiProcessingJob
    {
        if (! $job->remote_job_id || $job->isTerminal()) {
            return $job;
        }

        try {
            $remote = $this->aiClient->getAnalysisJob($job->remote_job_id);
        } catch (RuntimeException $e) {
            Log::warning('Transient AI analysis polling failure', [
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

            $persisted = $this->transaction(function () use ($job, $lesson, $teacher, $result) {
                /** @var AiProcessingJob $locked */
                $locked = AiProcessingJob::where('id', $job->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === AiProcessingJob::STATUS_COMPLETED) {
                    return $locked;
                }

                $meta = is_array($locked->meta) ? $locked->meta : [];
                $requestedTasks = is_array($meta['tasks'] ?? null) ? $meta['tasks'] : ['chapters', 'concept_graph'];

                $savedChaptersCount = null;
                if (in_array('chapters', $requestedTasks, true) && is_array($result['chapters'] ?? null) && count($result['chapters']) > 0) {
                    LessonChapter::where('lesson_id', $lesson->id)->delete();
                    $saved = 0;
                    foreach ($result['chapters'] as $ch) {
                        if (! is_array($ch)) {
                            continue;
                        }
                        $titleAr = trim(strip_tags((string) ($ch['title_ar'] ?? $ch['titleAr'] ?? '')));
                        $titleEn = trim(strip_tags((string) ($ch['title_en'] ?? $ch['titleEn'] ?? $titleAr)));
                        if ($titleAr === '') {
                            continue;
                        }
                        $startSecs = max(0, (int) ($ch['start_seconds'] ?? $ch['startSeconds'] ?? 0));
                        $ts = trim((string) ($ch['timestamp'] ?? $this->formatSecondsLabel($startSecs)));

                        LessonChapter::create([
                            'lesson_id' => $lesson->id,
                            'teacher_id' => $teacher->id,
                            'title_ar' => mb_substr($titleAr, 0, 200),
                            'title_en' => mb_substr($titleEn ?: $titleAr, 0, 200),
                            'start_seconds' => $startSecs,
                            'timestamp_label' => mb_substr($ts, 0, 20),
                            'display_order' => $saved + 1,
                        ]);
                        $saved++;
                    }
                    $savedChaptersCount = $saved;
                }

                $savedConceptsCount = null;
                if (in_array('concept_graph', $requestedTasks, true) && is_array($result['concept_graph'] ?? null)) {
                    $nodeCounter = 0;
                    $sanitizedTree = $this->sanitizeGraphNode($result['concept_graph'], 1, $nodeCounter);
                    if ($sanitizedTree !== null) {
                        LessonConceptGraph::updateOrCreate(
                            ['lesson_id' => $lesson->id],
                            [
                                'teacher_id' => $teacher->id,
                                'tree_data' => $sanitizedTree,
                                'source' => 'ai',
                            ]
                        );
                        $savedConceptsCount = $nodeCounter;
                    }
                }

                $locked->update([
                    'status' => AiProcessingJob::STATUS_COMPLETED,
                    'progress' => 100,
                    'stage' => 'completed',
                    'model_name' => (string) ($result['model'] ?? $locked->model_name),
                    'detected_language' => isset($result['language'])
                        ? mb_substr((string) $result['language'], 0, 16)
                        : $locked->detected_language,
                    'error_message' => null,
                    'meta' => array_merge($meta, [
                        'device' => $result['device'] ?? null,
                        'quantization' => $result['quantization'] ?? null,
                        'processingSeconds' => $result['processing_seconds'] ?? null,
                        'chaptersCount' => $savedChaptersCount,
                        'conceptsCount' => $savedConceptsCount,
                    ]),
                    'completed_at' => now(),
                ]);

                $this->logSecurityEvent(
                    'teacher.ai.analysis_completed',
                    [
                        'lesson_uuid' => $lesson->uuid,
                        'ai_job_uuid' => $locked->uuid,
                        'tasks' => $requestedTasks,
                        'chapters_count' => $savedChaptersCount,
                        'concepts_count' => $savedConceptsCount,
                        'processing_seconds' => $result['processing_seconds'] ?? null,
                    ],
                    'info',
                    $lesson
                );

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
     * Compute clean unified progress & status across chained STT + Analysis jobs.
     *
     * @return array{status: string, progress: int, activePhase: string, stage: string, error: ?string}
     */
    protected function computeUnifiedSummary(?AiProcessingJob $sttJob, ?AiProcessingJob $analysisJob): array
    {
        $sttMeta = is_array($sttJob?->meta) ? $sttJob->meta : [];
        $hasPendingChain = ! empty($sttMeta['pendingAnalysisTasks']) && empty($sttMeta['chainedAnalysisJobUuid']);
        $isChainedPipeline = $hasPendingChain
            || ($sttJob && $analysisJob && $analysisJob->created_at?->gte($sttJob->created_at));

        if ($sttJob && $sttJob->isActive()) {
            $pct = $hasPendingChain
                ? max(5, min(58, (int) round($sttJob->progress * 0.58)))
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
            $pct = $isChainedPipeline
                ? max(60, min(99, 58 + (int) round($analysisJob->progress * 0.41)))
                : max(5, min(99, (int) $analysisJob->progress));

            return [
                'status' => $analysisJob->status,
                'progress' => $pct,
                'activePhase' => 'analysis',
                'stage' => (string) ($analysisJob->stage ?: 'generating_concept_graph'),
                'error' => null,
            ];
        }

        // Check failures (prefer whichever ran most recently)
        $latestJob = null;
        if ($sttJob && $analysisJob) {
            $latestJob = $analysisJob->id >= $sttJob->id ? $analysisJob : $sttJob;
        } else {
            $latestJob = $analysisJob ?? $sttJob;
        }

        if ($latestJob && $latestJob->status === AiProcessingJob::STATUS_FAILED) {
            return [
                'status' => AiProcessingJob::STATUS_FAILED,
                'progress' => (int) $latestJob->progress,
                'activePhase' => 'failed',
                'stage' => 'failed',
                'error' => $latestJob->error_message,
            ];
        }

        if ($latestJob && $latestJob->status === AiProcessingJob::STATUS_COMPLETED) {
            return [
                'status' => AiProcessingJob::STATUS_COMPLETED,
                'progress' => 100,
                'activePhase' => 'completed',
                'stage' => 'completed',
                'error' => null,
            ];
        }

        return [
            'status' => 'idle',
            'progress' => 0,
            'activePhase' => 'idle',
            'stage' => 'idle',
            'error' => null,
        ];
    }

    /**
     * @param  list<string>  $tasks
     * @return list<string>
     */
    protected function normalizeTasks(array $tasks): array
    {
        $mapped = [];
        foreach ($tasks as $t) {
            $val = strtolower(trim((string) $t));
            if ($val === 'chapterindexing' || $val === 'topics') {
                $val = 'chapters';
            } elseif ($val === 'conceptgraph' || $val === 'mindmap' || $val === 'graph') {
                $val = 'concept_graph';
            }
            if (in_array($val, self::ALLOWED_ANALYSIS_TASKS, true)) {
                $mapped[] = $val;
            }
        }

        return array_values(array_unique($mapped));
    }

    protected function sanitizeGraphNode(array $node, int $depth, int &$nodeCounter): ?array
    {
        if ($depth > self::MAX_GRAPH_DEPTH || $nodeCounter >= self::MAX_GRAPH_NODES) {
            return null;
        }

        $nodeCounter++;
        $labelAr = trim(strip_tags((string) ($node['labelAr'] ?? $node['label_ar'] ?? $node['label'] ?? 'مفهوم علمي')));
        $labelEn = trim(strip_tags((string) ($node['labelEn'] ?? $node['label_en'] ?? $node['label'] ?? $labelAr)));
        $summaryAr = isset($node['summaryAr']) || isset($node['summary_ar'])
            ? trim(strip_tags((string) ($node['summaryAr'] ?? $node['summary_ar'])))
            : null;
        $startSecs = max(0, (int) ($node['startSeconds'] ?? $node['start_seconds'] ?? 0));
        $timestamp = trim(strip_tags((string) ($node['timestamp'] ?? $this->formatSecondsLabel($startSecs))));

        $children = [];
        if (! empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $built = $this->sanitizeGraphNode($child, $depth + 1, $nodeCounter);
                    if ($built !== null) {
                        $children[] = $built;
                    }
                }
            }
        }

        return array_filter([
            'id' => isset($node['id']) ? trim(strip_tags((string) $node['id'])) : ($depth === 1 ? 'root-1' : 'node-'.$nodeCounter),
            'label' => $labelAr,
            'labelAr' => $labelAr,
            'labelEn' => $labelEn ?: $labelAr,
            'summaryAr' => $summaryAr !== '' ? $summaryAr : null,
            'startSeconds' => $startSecs,
            'timestamp' => $timestamp,
            'children' => $children,
        ], fn ($v) => $v !== null);
    }

    protected function countConceptNodes(?array $tree): int
    {
        if (! is_array($tree)) {
            return 0;
        }
        $count = 1;
        if (! empty($tree['children']) && is_array($tree['children'])) {
            foreach ($tree['children'] as $child) {
                if (is_array($child)) {
                    $count += $this->countConceptNodes($child);
                }
            }
        }

        return $count;
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
}
