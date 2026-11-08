<?php

namespace App\Services\V1\Teacher;

use App\Enums\LessonMediaMode;
use App\Models\Lesson;
use App\Models\LessonChapter;
use App\Models\LessonConceptGraph;
use App\Models\LessonTranscript;
use App\Models\User;
use App\Repositories\Contracts\LessonRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LessonWorkspaceService extends BaseService
{
    private const MAX_GRAPH_DEPTH = 6;
    private const MAX_GRAPH_NODES = 150;

    public function __construct(
        protected LessonRepositoryInterface $lessonRepository
    ) {}

    /**
     * Retrieve the full workspace overview (all lessons + KPI summary).
     *
     * @return array{lessons: Collection, stats: array<string, mixed>}
     */
    public function getWorkspaceOverview(User $teacher, array $filters = []): array
    {
        $lessons = $this->lessonRepository->getForTeacher($teacher->id, $filters);

        $totalSeconds = (int) $lessons->sum('duration_seconds');
        $totalHours = round($totalSeconds / 3600, 1);

        $videoCount = $lessons->filter(function (Lesson $l) {
            $mode = $l->media_mode instanceof LessonMediaMode ? $l->media_mode->value : (string) $l->media_mode;
            return in_array($mode, ['video', 'embed'], true);
        })->count();

        $audioCount = $lessons->count() - $videoCount;

        return [
            'lessons' => $lessons,
            'stats'   => [
                'totalLessons' => $lessons->count(),
                'totalHours'   => $totalHours,
                'videoCount'   => $videoCount,
                'audioCount'   => $audioCount,
            ],
        ];
    }

    /**
     * Retrieve a single lesson's full workspace (media, attachments, chapters, transcript, concept graph).
     */
    public function getLessonWorkspace(User $teacher, string $lessonUuid): Lesson
    {
        return $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);
    }

    /**
     * Replace/sync the timestamped chapters for a lesson.
     */
    public function syncChapters(User $teacher, string $lessonUuid, array $chapters): Collection
    {
        // Enforce ownership BEFORE opening transaction
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        return $this->transaction(function () use ($teacher, $lesson, $chapters) {
            LessonChapter::where('lesson_id', $lesson->id)->delete();

            // Sort chapters by startSeconds ascending
            usort($chapters, function (array $a, array $b) {
                $secA = (int) ($a['start_seconds'] ?? $a['startSeconds'] ?? 0);
                $secB = (int) ($b['start_seconds'] ?? $b['startSeconds'] ?? 0);
                return $secA <=> $secB;
            });

            foreach ($chapters as $idx => $item) {
                $startSecs = max(0, (int) ($item['start_seconds'] ?? $item['startSeconds'] ?? 0));
                $titleAr = trim(strip_tags((string) ($item['title_ar'] ?? $item['titleAr'] ?? $item['title'] ?? '')));
                $titleEn = trim(strip_tags((string) ($item['title_en'] ?? $item['titleEn'] ?? $item['title'] ?? $titleAr)));
                $timestamp = trim((string) ($item['timestamp'] ?? $item['timestamp_label'] ?? $this->formatTimestamp($startSecs)));

                LessonChapter::create([
                    'lesson_id'       => $lesson->id,
                    'teacher_id'      => $teacher->id,
                    'title_ar'        => $titleAr !== '' ? $titleAr : 'فصل دراسي',
                    'title_en'        => $titleEn !== '' ? $titleEn : $titleAr,
                    'start_seconds'   => $startSecs,
                    'timestamp_label' => $timestamp,
                    'display_order'   => $idx + 1,
                ]);
            }

            $this->logSecurityEvent(
                'teacher.workspace.chapters_updated',
                [
                    'lesson_uuid'    => $lesson->uuid,
                    'chapters_count' => count($chapters),
                ],
                'info',
                $lesson
            );

            return $lesson->chapters()->get();
        });
    }

    /**
     * Replace/sync the timestamped transcript segments for a lesson.
     */
    public function syncTranscript(User $teacher, string $lessonUuid, array $segments): Collection
    {
        // Enforce ownership BEFORE opening transaction
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        return $this->transaction(function () use ($teacher, $lesson, $segments) {
            LessonTranscript::where('lesson_id', $lesson->id)->delete();

            usort($segments, function (array $a, array $b) {
                $secA = (int) ($a['start_seconds'] ?? $a['startSeconds'] ?? 0);
                $secB = (int) ($b['start_seconds'] ?? $b['startSeconds'] ?? 0);
                return $secA <=> $secB;
            });

            foreach ($segments as $idx => $seg) {
                $startSecs = max(0, (int) ($seg['start_seconds'] ?? $seg['startSeconds'] ?? 0));
                $speaker = trim(strip_tags((string) ($seg['speaker'] ?? $teacher->name ?? 'المعلم')));
                $textAr = trim(strip_tags((string) ($seg['text_ar'] ?? $seg['textAr'] ?? $seg['text'] ?? '')));
                $textEn = isset($seg['text_en']) || isset($seg['textEn'])
                    ? trim(strip_tags((string) ($seg['text_en'] ?? $seg['textEn'])))
                    : null;
                $timestamp = trim((string) ($seg['timestamp'] ?? $seg['timestamp_label'] ?? $this->formatTimestamp($startSecs)));

                LessonTranscript::create([
                    'lesson_id'       => $lesson->id,
                    'teacher_id'      => $teacher->id,
                    'speaker'         => $speaker !== '' ? $speaker : 'المعلم',
                    'start_seconds'   => $startSecs,
                    'timestamp_label' => $timestamp,
                    'text_ar'         => $textAr,
                    'text_en'         => $textEn,
                    'display_order'   => $idx + 1,
                ]);
            }

            $this->logSecurityEvent(
                'teacher.workspace.transcript_updated',
                [
                    'lesson_uuid'    => $lesson->uuid,
                    'segments_count' => count($segments),
                ],
                'info',
                $lesson
            );

            return $lesson->transcriptSegments()->get();
        });
    }

    /**
     * Save or update the hierarchical NotebookLM-style Concept Graph for a lesson.
     */
    public function saveConceptGraph(
        User $teacher,
        string $lessonUuid,
        array $treeData,
        string $source = 'manual'
    ): LessonConceptGraph {
        // Enforce ownership BEFORE opening transaction
        $lesson = $this->lessonRepository->findByUuidForTeacher($lessonUuid, $teacher);

        $nodeCounter = 0;
        $sanitizedTree = $this->sanitizeAndValidateGraphNode($treeData, 1, $nodeCounter);

        return $this->transaction(function () use ($teacher, $lesson, $sanitizedTree, $source, $nodeCounter) {
            /** @var LessonConceptGraph $graph */
            $graph = LessonConceptGraph::updateOrCreate(
                ['lesson_id' => $lesson->id],
                [
                    'teacher_id' => $teacher->id,
                    'tree_data'  => $sanitizedTree,
                    'source'     => in_array($source, ['manual', 'ai'], true) ? $source : 'manual',
                ]
            );

            $this->logSecurityEvent(
                'teacher.workspace.concept_graph_updated',
                [
                    'lesson_uuid' => $lesson->uuid,
                    'nodes_count' => $nodeCounter,
                    'source'      => $graph->source,
                ],
                'info',
                $graph
            );

            return $graph;
        });
    }

    /**
     * Recursively sanitize mind-map nodes and enforce depth & total node limits (Anti-JSON-Bomb).
     */
    protected function sanitizeAndValidateGraphNode(array $node, int $depth, int &$nodeCounter): array
    {
        if ($depth > self::MAX_GRAPH_DEPTH) {
            throw ValidationException::withMessages([
                'tree_data' => [__('messages.concept_graph_depth_exceeded')],
            ]);
        }

        $nodeCounter++;
        if ($nodeCounter > self::MAX_GRAPH_NODES) {
            throw ValidationException::withMessages([
                'tree_data' => [__('messages.concept_graph_nodes_exceeded')],
            ]);
        }

        $labelAr = trim(strip_tags((string) ($node['labelAr'] ?? $node['label_ar'] ?? $node['label'] ?? 'مفهوم علمي')));
        $labelEn = trim(strip_tags((string) ($node['labelEn'] ?? $node['label_en'] ?? $node['label'] ?? $labelAr)));
        $summaryAr = isset($node['summaryAr']) || isset($node['summary_ar']) || isset($node['detailsAr'])
            ? trim(strip_tags((string) ($node['summaryAr'] ?? $node['summary_ar'] ?? $node['detailsAr'])))
            : null;
        $startSecs = isset($node['startSeconds']) || isset($node['start_seconds'])
            ? max(0, (int) ($node['startSeconds'] ?? $node['start_seconds']))
            : null;
        $timestamp = isset($node['timestamp'])
            ? trim(strip_tags((string) $node['timestamp']))
            : ($startSecs !== null ? $this->formatTimestamp($startSecs) : null);

        $children = [];
        if (!empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $children[] = $this->sanitizeAndValidateGraphNode($child, $depth + 1, $nodeCounter);
                }
            }
        }

        return array_filter([
            'id'           => isset($node['id']) ? trim(strip_tags((string) $node['id'])) : 'node-' . $nodeCounter,
            'label'        => $labelAr,
            'labelAr'      => $labelAr,
            'labelEn'      => $labelEn,
            'summaryAr'    => $summaryAr,
            'startSeconds' => $startSecs,
            'timestamp'    => $timestamp,
            'children'     => $children,
        ], fn ($val) => $val !== null);
    }

    protected function formatTimestamp(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return sprintf('%02d:%02d', $m, $s);
    }
}

