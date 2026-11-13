<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\AddLeagueParticipantRequest;
use App\Http\Requests\V1\Teacher\AwardLeagueXpRequest;
use App\Http\Requests\V1\Teacher\StoreCourseLeagueRequest;
use App\Http\Requests\V1\Teacher\UpdateCourseLeagueRequest;
use App\Http\Resources\V1\Teacher\CourseLeagueDetailResource;
use App\Http\Resources\V1\Teacher\CourseLeagueResource;
use App\Services\V1\Teacher\TeacherLeagueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherLeagueController extends BaseApiController
{
    public function __construct(
        protected TeacherLeagueService $leagueService
    ) {}

    /**
     * GET /api/v1/teacher/leagues
     * List all course leagues created by the teacher.
     */
    public function index(Request $request): JsonResponse
    {
        $leagues = $this->leagueService->listLeagues(
            $this->authenticatedUser(),
            $request->only(['search', 'q', 'course_id', 'is_active'])
        );

        return $this->successResponse(
            CourseLeagueResource::collection($leagues),
            __('messages.leagues_fetched'),
            meta: ['total' => $leagues->count()]
        );
    }

    /**
     * POST /api/v1/teacher/leagues
     * Create a new course league season.
     */
    public function store(StoreCourseLeagueRequest $request): JsonResponse
    {
        $league = $this->leagueService->createLeague(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse(
            new CourseLeagueDetailResource($league),
            __('messages.league_created')
        );
    }

    /**
     * GET /api/v1/teacher/leagues/{uuid}
     * Show league details with podium (top 3) and full leaderboard.
     */
    public function show(string $uuid): JsonResponse
    {
        $league = $this->leagueService->getLeague(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new CourseLeagueDetailResource($league),
            __('messages.league_fetched')
        );
    }

    /**
     * PUT/PATCH /api/v1/teacher/leagues/{uuid}
     * Update an existing league's details, duration, target group, or active status.
     */
    public function update(UpdateCourseLeagueRequest $request, string $uuid): JsonResponse
    {
        $league = $this->leagueService->updateLeague(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse(
            new CourseLeagueDetailResource($league),
            'تم تحديث بيانات الدوري بنجاح.'
        );
    }

    /**
     * POST /api/v1/teacher/leagues/{uuid}/participants
     * Add a student participant to an existing league after it has started.
     */
    public function addParticipant(AddLeagueParticipantRequest $request, string $uuid): JsonResponse
    {
        $league = $this->leagueService->addParticipant(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->createdResponse(
            new CourseLeagueDetailResource($league),
            'تم إضافة الطالب إلى الدوري بنجاح.'
        );
    }

    /**
     * POST /api/v1/teacher/leagues/{uuid}/award-xp
     * Award bonus XP to a participant inside the league.
     */
    public function awardXp(AwardLeagueXpRequest $request, string $uuid): JsonResponse
    {
        $league = $this->leagueService->awardXp(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse(
            new CourseLeagueDetailResource($league),
            __('messages.league_xp_awarded')
        );
    }

    /**
     * POST /api/v1/teacher/leagues/{uuid}/recalculate
     * Recalculate participant ranks and movement arrows.
     */
    public function recalculate(string $uuid): JsonResponse
    {
        $league = $this->leagueService->recalculateLeague(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            new CourseLeagueDetailResource($league),
            __('messages.league_recalculated')
        );
    }

    /**
     * DELETE /api/v1/teacher/leagues/{uuid}
     * Delete a league.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $this->leagueService->deleteLeague(
            $this->authenticatedUser(),
            $uuid
        );

        return $this->successResponse(
            null,
            __('messages.league_deleted')
        );
    }
}

