<?php

namespace App\Http\Middleware;

use App\Enums\StudentPlanTier;
use App\Services\V1\Student\StudentBillingService;
use App\Traits\ApiResponse;
use App\Traits\HasSecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckStudentPlan
{
    use ApiResponse, HasSecurityAudit;

    public function __construct(
        protected StudentBillingService $billingService
    ) {}

    /**
     * Enforce student subscription tier entitlements on gated platform features.
     *
     * Usage:
     *   ->middleware('student.plan:leagues')
     *   ->middleware('student.plan:gamification')
     *   ->middleware('student.plan:certificates')
     *   ->middleware('student.plan:analytics')
     *   ->middleware('student.plan:converted_lectures')
     *   ->middleware('student.plan:revision')
     *   ->middleware('student.plan:weak_areas')
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isStudent()) {
            return $this->forbiddenResponse(__('messages.forbidden'));
        }

        $currentTier = $this->billingService->resolveActivePlanTier($user);
        $requiredTier = StudentPlanTier::requiredTierForFeature($feature);

        if (! $currentTier->meets($requiredTier)) {
            $this->logSecurityEvent('student.plan_feature_blocked', [
                'user_id' => $user->id,
                'feature' => $feature,
                'current_plan' => $currentTier->value,
                'required_plan' => $requiredTier->value,
            ], 'info');

            $isEn = app()->getLocale() === 'en';
            $requiredName = $isEn ? $requiredTier->labelEn() : $requiredTier->labelAr();

            return response()->json([
                'success' => false,
                'message' => __('messages.student_subscription_required', ['plan' => $requiredName]),
                'error_code' => 'SUBSCRIPTION_REQUIRED',
                'feature' => $feature,
                'current_plan' => $currentTier->value,
                'required_plan' => $requiredTier->value,
                'required_plan_name_ar' => $requiredTier->labelAr(),
                'required_plan_name_en' => $requiredTier->labelEn(),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
