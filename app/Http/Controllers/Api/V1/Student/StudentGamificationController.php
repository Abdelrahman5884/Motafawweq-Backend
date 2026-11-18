<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Student\StudentGamificationService;
use Illuminate\Http\JsonResponse;

class StudentGamificationController extends BaseApiController
{
    public function __construct(
        protected StudentGamificationService $gamificationService
    ) {}

    public function summary(): JsonResponse
    {
        $summary = $this->gamificationService->getGamificationSummary($this->authenticatedUser());

        return $this->successResponse($summary);
    }

    public function leagues(): JsonResponse
    {
        $leagues = $this->gamificationService->listLeagues($this->authenticatedUser());

        return $this->successResponse($leagues);
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

    public function activityHeatmap(): JsonResponse
    {
        return $this->successResponse($this->gamificationService->getActivityHeatmap($this->authenticatedUser()));
    }

    public function weeklyChallenges(): JsonResponse
    {
        return $this->successResponse($this->gamificationService->getWeeklyChallenges($this->authenticatedUser()));
    }

    public function pastLeagues(): JsonResponse
    {
        return $this->successResponse($this->gamificationService->getPastLeagues($this->authenticatedUser()));
    }
}
