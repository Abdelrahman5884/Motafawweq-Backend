<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Admin\AdminRepublicLeagueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRepublicLeagueController extends BaseApiController
{
    public function __construct(
        protected AdminRepublicLeagueService $leagueService
    ) {}

    /**
     * GET /api/v1/admin/leagues/republic
     * Show Republic League overview, grade catalog (1st Prep → 3rd Sec), active/recent rounds with day progress, and KPIs.
     */
    public function show(Request $request): JsonResponse
    {
        $grade = $request->query('grade');
        $overview = $this->leagueService->getOverview(is_string($grade) ? $grade : null);

        // Preserve backwards-compatible top-level league fields alongside full overview structure
        $payload = array_merge(
            $overview['league'] ?? [
                'id' => null,
                'dbId' => null,
                'titleAr' => 'دوري الجمهورية العام',
                'gradeAr' => null,
                'gradeLabelAr' => 'جميع الصفوف (أولى إعدادي — ثالثة ثانوي)',
                'gradeLabelEn' => 'All Grades (1st Prep – 3rd Sec)',
                'durationType' => 'monthly',
                'durationLabelAr' => 'شهري',
                'durationDays' => 30,
                'totalDays' => 30,
                'currentDay' => 0,
                'daysElapsed' => 0,
                'daysRemaining' => 0,
                'progressPercent' => 0,
                'endedEarly' => false,
                'dayProgressLabelAr' => 'لم تبدأ جولة بعد',
                'dayProgressLabelEn' => 'No active round yet',
                'isActive' => false,
                'startsAt' => null,
                'endsAt' => null,
            ],
            $overview
        );

        return $this->successResponse($payload);
    }

    /**
     * GET /api/v1/admin/leagues/republic/leaderboard
     * Paginated, searchable, grade-filterable Republic League standings.
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'grade' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'league_id' => ['nullable', 'uuid'],
            'only_scored' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $result = $this->leagueService->getLeaderboard($validated);

        return $this->successResponse(
            $result['items'],
            __('messages.admin.republic_league_leaderboard_fetched'),
            200,
            $result['meta']
        );
    }

    /**
     * GET /api/v1/admin/leagues/republic/nominees
     * Automatically surface Top-2 (#1 and #2 per grade) Republic League achievers with contact info for admin approval.
     */
    public function nominees(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'grade' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:all,pending,approved'],
            'league_id' => ['nullable', 'uuid'],
            'top_per_grade' => ['nullable', 'integer', 'min:1', 'max:2'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:2', 'max:30'],
        ]);

        $result = $this->leagueService->getAutoNominees($validated);

        return $this->successResponse(
            $result['items'],
            __('messages.admin.republic_league_nominees_fetched'),
            200,
            $result['meta']
        );
    }

    /**
     * POST /api/v1/admin/leagues/republic/start
     * Start a fresh Republic League round (all 6 grades or a specific grade from 1st Prep to 3rd Sec).
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title_ar' => ['nullable', 'string', 'max:250'],
            'grade_ar' => ['nullable', 'string', 'max:100'],
            'duration' => ['nullable', 'string', 'in:weekly,monthly,half_year,full_year'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $league = $this->leagueService->startLeague($this->authenticatedUser(), $validated);

        return $this->createdResponse($league, 'تم بدء دوري الجمهورية بنجاح.');
    }

    /**
     * POST /api/v1/admin/leagues/republic/stop
     * Stop active Republic League round(s) (global, grade-specific, or explicit round UUID).
     */
    public function stop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'grade_ar' => ['nullable', 'string', 'max:100'],
            'league_id' => ['nullable', 'uuid'],
        ]);

        $result = $this->leagueService->stopLeague(
            $this->authenticatedUser(),
            $validated['grade_ar'] ?? null,
            $validated['league_id'] ?? null
        );

        return $this->successResponse($result, 'تم إيقاف دوري الجمهورية بنجاح.');
    }

    /**
     * POST /api/v1/admin/leagues/republic/announce-end
     * End a Republic League round (even ahead of schedule) and broadcast an official Platform Announcement for honoring.
     */
    public function announceEnd(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'league_id' => ['nullable', 'uuid'],
            'grade_ar' => ['nullable', 'string', 'max:100'],
            'end_now' => ['nullable', 'boolean'],
            'publish_announcement' => ['nullable', 'boolean'],
            'announcement_title_ar' => ['nullable', 'string', 'max:200'],
            'announcement_title_en' => ['nullable', 'string', 'max:200'],
            'announcement_content_ar' => ['nullable', 'string', 'max:1500'],
            'announcement_content_en' => ['nullable', 'string', 'max:1500'],
            'audience' => ['nullable', 'string', 'in:all,students,teachers,parents,centers'],
            'priority' => ['nullable', 'string', 'in:normal,important,urgent'],
            'display_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $result = $this->leagueService->announceLeagueEnd($this->authenticatedUser(), $validated);

        return $this->successResponse($result, __('messages.admin.republic_league_end_announced'));
    }
}
