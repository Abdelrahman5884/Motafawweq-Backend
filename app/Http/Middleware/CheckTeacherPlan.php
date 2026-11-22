<?php

namespace App\Http\Middleware;

use App\Enums\TeacherPlanTier;
use App\Services\V1\Teacher\TeacherBillingService;
use App\Traits\ApiResponse;
use App\Traits\HasSecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTeacherPlan
{
    use ApiResponse, HasSecurityAudit;

    public function __construct(
        protected TeacherBillingService $billingService
    ) {}

    /**
     * Enforce teacher subscription tier entitlements on gated platform features.
     *
     * Usage:
     *   ->middleware('teacher.plan:schedule')
     *   ->middleware('teacher.plan:exams')
     *   ->middleware('teacher.plan:homework')
     *   ->middleware('teacher.plan:leagues')
     *   ->middleware('teacher.plan:certificates')
     *   ->middleware('teacher.plan:financials')
     *   ->middleware('teacher.plan:ai')
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isTeacher()) {
            return $this->forbiddenResponse(__('messages.forbidden'));
        }

        $currentTier = $this->billingService->resolveActivePlanTier($user);
        $requiredTier = TeacherPlanTier::requiredTierForFeature($feature);

        if (! $currentTier->meets($requiredTier)) {
            $this->logSecurityEvent('teacher.plan_feature_blocked', [
                'user_id' => $user->id,
                'feature' => $feature,
                'current_plan' => $currentTier->value,
                'required_plan' => $requiredTier->value,
            ], 'info');

            $isEn = app()->getLocale() === 'en';
            $requiredName = $isEn ? $requiredTier->labelEn() : $requiredTier->labelAr();

            return response()->json([
                'success' => false,
                'message' => __('messages.teacher_subscription_required', ['plan' => $requiredName]),
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
