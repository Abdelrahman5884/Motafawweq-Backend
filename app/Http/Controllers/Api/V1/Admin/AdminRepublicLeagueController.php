<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\LeagueDuration;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\RepublicLeague;
use App\Services\V1\Admin\PlatformSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRepublicLeagueController extends BaseApiController
{
    public function __construct(
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * GET /api/v1/admin/leagues/republic
     * Show the active (or latest) Admin-controlled Republic League.
     */
    public function show(): JsonResponse
    {
        $active = RepublicLeague::currentActive();
        $latest = $active ?: RepublicLeague::query()->latest('starts_at')->first();

        return $this->successResponse($latest ? $this->formatLeague($latest) : null);
    }

    /**
     * POST /api/v1/admin/leagues/republic/start
     * Start a fresh General Republic League round (all students start at 0 points from starts_at).
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title_ar' => ['nullable', 'string', 'max:250'],
            'duration' => ['nullable', 'string', 'in:weekly,monthly,half_year,full_year'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        // Deactivate any previously running Republic League rounds
        RepublicLeague::where('is_active', true)->update(['is_active' => false]);

        $durationEnum = LeagueDuration::tryFrom((string) ($validated['duration'] ?? 'monthly')) ?? LeagueDuration::MONTHLY;
        $durationDays = isset($validated['duration_days'])
            ? (int) $validated['duration_days']
            : $durationEnum->durationDays();

        $startsAt = Carbon::now();
        $endsAt = $startsAt->copy()->addDays($durationDays);

        $league = RepublicLeague::create([
            'started_by' => $this->authenticatedUser()->id,
            'title_ar' => trim((string) ($validated['title_ar'] ?? '')) ?: 'دوري الجمهورية العام',
            'duration_type' => $durationEnum,
            'duration_days' => $durationDays,
            'is_active' => true,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $this->settingsService->bumpCacheEpoch();
        $this->logSecurityEvent('admin.republic_league.started', [
            'admin_id' => $this->authenticatedUser()->id,
            'league_uuid' => $league->uuid,
            'duration_days' => $durationDays,
        ], 'info', $league);

        return $this->createdResponse($this->formatLeague($league), 'تم بدء دوري الجمهورية العام بنجاح.');
    }

    /**
     * POST /api/v1/admin/leagues/republic/stop
     * Stop the currently active General Republic League round.
     */
    public function stop(): JsonResponse
    {
        $stoppedCount = RepublicLeague::where('is_active', true)->update([
            'is_active' => false,
            'ends_at' => Carbon::now(),
        ]);

        $this->settingsService->bumpCacheEpoch();
        $this->logSecurityEvent('admin.republic_league.stopped', [
            'admin_id' => $this->authenticatedUser()->id,
            'stopped_count' => $stoppedCount,
        ], 'warning');

        return $this->successResponse(
            ['stopped' => $stoppedCount > 0],
            'تم إيقاف دوري الجمهورية العام بنجاح.'
        );
    }

    private function formatLeague(RepublicLeague $league): array
    {
        $durationEnum = $league->duration_type instanceof LeagueDuration
            ? $league->duration_type
            : (LeagueDuration::tryFrom((string) $league->duration_type) ?? LeagueDuration::MONTHLY);

        return [
            'id' => $league->uuid,
            'titleAr' => $league->title_ar,
            'durationType' => $durationEnum->value,
            'durationLabelAr' => $durationEnum->labelAr(),
            'durationDays' => (int) $league->duration_days,
            'isActive' => (bool) $league->is_active,
            'startsAt' => $league->starts_at?->toIso8601String(),
            'endsAt' => $league->ends_at?->toIso8601String(),
            'daysRemaining' => $league->roundDaysRemaining(),
        ];
    }
}
