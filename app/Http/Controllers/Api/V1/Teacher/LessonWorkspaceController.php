<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\SaveLessonConceptGraphRequest;
use App\Http\Requests\V1\Teacher\SyncLessonChaptersRequest;
use App\Http\Requests\V1\Teacher\SyncLessonTranscriptRequest;
use App\Http\Resources\V1\Teacher\LessonChapterResource;
use App\Http\Resources\V1\Teacher\LessonTranscriptResource;
use App\Http\Resources\V1\Teacher\TeacherLessonResource;
use App\Services\V1\Teacher\LessonWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonWorkspaceController extends BaseApiController
{
    public function __construct(
        protected LessonWorkspaceService $workspaceService
    ) {}

    /**
     * GET /api/v1/teacher/workspace
     * Fetch the full LessonWorkspace overview (KPI stats + all lessons).
     */
    public function index(Request $request): JsonResponse
    {
        $overview = $this->workspaceService->getWorkspaceOverview(
            $this->authenticatedUser(),
            $request->only(['course_id', 'course_uuid', 'status', 'search', 'q'])
        );

        return $this->successResponse(
            [
                'stats'   => $overview['stats'],
                'lessons' => TeacherLessonResource::collection($overview['lessons'])->resolve($request),
            ],
            __('messages.workspace_overview_fetched')
        );
    }

    /**
     * GET /api/v1/teacher/workspace/lessons/{uuid}
     * Fetch a single lesson's complete workspace (media, chapters, transcript, mind map, attachments).
     */
    public function show(string $uuid): JsonResponse
    {
        $lesson = $this->workspaceService->getLessonWorkspace(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new TeacherLessonResource($lesson),
            __('messages.lesson_workspace_fetched')
        );
    }

    /**
     * PUT /api/v1/teacher/workspace/lessons/{uuid}/chapters
     * Sync timestamped chapters for a lesson.
     */
    public function syncChapters(SyncLessonChaptersRequest $request, string $uuid): JsonResponse
    {
        $chapters = $this->workspaceService->syncChapters(
            $this->authenticatedUser(),
            $uuid,
            $request->validated('chapters')
        );

        return $this->successResponse(
            LessonChapterResource::collection($chapters),
            __('messages.lesson_chapters_updated')
        );
    }

    /**
     * PUT /api/v1/teacher/workspace/lessons/{uuid}/transcript
     * Sync timestamped Speech-to-Text transcript segments for a lesson.
     */
    public function syncTranscript(SyncLessonTranscriptRequest $request, string $uuid): JsonResponse
    {
        $segments = $this->workspaceService->syncTranscript(
            $this->authenticatedUser(),
            $uuid,
            $request->validated('segments')
        );

        return $this->successResponse(
            LessonTranscriptResource::collection($segments),
            __('messages.lesson_transcript_updated')
        );
    }

    /**
     * PUT /api/v1/teacher/workspace/lessons/{uuid}/concept-graph
     * Save or update the hierarchical NotebookLM-style Concept Graph.
     */
    public function saveConceptGraph(SaveLessonConceptGraphRequest $request, string $uuid): JsonResponse
    {
        $graph = $this->workspaceService->saveConceptGraph(
            $this->authenticatedUser(),
            $uuid,
            $request->validated('tree_data'),
            (string) ($request->validated('source') ?? 'manual')
        );

        return $this->successResponse(
            $graph->tree_data,
            __('messages.lesson_concept_graph_updated')
        );
    }
}

