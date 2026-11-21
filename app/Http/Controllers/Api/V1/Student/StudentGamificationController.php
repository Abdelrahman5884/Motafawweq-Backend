<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Student\StudentGamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentGamificationController extends BaseApiController
{
    public function __construct(
        protected StudentGamificationService $gamificationService
    ) {}

    public function workspace(Request $request): JsonResponse
    {
        $timezone = $request->query('timezone');
        $data = $this->gamificationService->getGamificationWorkspace(
            $this->authenticatedUser(),
            is_string($timezone) ? $timezone : null
        );

        return $this->successResponse($data);
    }

    public function summary(Request $request): JsonResponse
    {
        $timezone = $request->query('timezone');
        $summary = $this->gamificationService->getGamificationSummary(
            $this->authenticatedUser(),
            is_string($timezone) ? $timezone : null
        );

        return $this->successResponse($summary);
    }

    public function leagues(): JsonResponse
    {
        $leagues = $this->gamificationService->listLeagues($this->authenticatedUser());

        return $this->successResponse($leagues);
    }

    public function generalLeaderboard(): JsonResponse
    {
        $data = $this->gamificationService->getGeneralLeaderboard($this->authenticatedUser());

        return $this->successResponse($data);
    }

    public function leaderboard(string $uuid): JsonResponse
    {
        $data = $this->gamificationService->getLeagueLeaderboard($this->authenticatedUser(), $uuid);

        return $this->successResponse($data);
    }

    public function certificates(): JsonResponse
    {
        $certs = $this->gamificationService->listCertificates($this->authenticatedUser());

        return $this->successResponse($certs);
    }

    public function certificateEligibility(): JsonResponse
    {
        return $this->successResponse($this->gamificationService->getCertificateEligibility($this->authenticatedUser()));
    }

    public function certificatesWorkspace(): JsonResponse
    {
        return $this->successResponse($this->gamificationService->getCertificatesWorkspace($this->authenticatedUser()));
    }

    public function claimCourseCertificate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'uuid'],
        ]);

        $cert = $this->gamificationService->claimCourseCertificate(
            $this->authenticatedUser(),
            (string) $validated['course_id']
        );

        return $this->createdResponse($cert, 'تم إصدار وتوثيق شهادة إتمام المقرر بنجاح.');
    }

    public function activityHeatmap(Request $request): JsonResponse
    {
        $timezone = $request->query('timezone');

        return $this->successResponse(
            $this->gamificationService->getActivityHeatmap(
                $this->authenticatedUser(),
                is_string($timezone) ? $timezone : null
            )
        );
    }

    public function weeklyChallenges(Request $request): JsonResponse
    {
        $timezone = $request->query('timezone');

        return $this->successResponse(
            $this->gamificationService->getWeeklyChallenges(
                $this->authenticatedUser(),
                is_string($timezone) ? $timezone : null
            )
        );
    }

    public function pastLeagues(): JsonResponse
    {
        return $this->successResponse($this->gamificationService->getPastLeagues($this->authenticatedUser()));
    }
}
