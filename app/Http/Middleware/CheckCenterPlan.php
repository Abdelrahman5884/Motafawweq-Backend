<?php

namespace App\Http\Middleware;

use App\Enums\CenterPlanTier;
use App\Services\V1\Center\CenterBillingService;
use App\Traits\ApiResponse;
use App\Traits\HasSecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCenterPlan
{
    use ApiResponse, HasSecurityAudit;

    public function __construct(
        protected CenterBillingService $billingService
    ) {}

    /**
     * Enforce center subscription tier entitlements on gated platform features.
     *
     * Usage:
     *   ->middleware('center.plan:qr_scanner')
     *   ->middleware('center.plan:financials')
     *   ->middleware('center.plan:whatsapp_alerts')
     *   ->middleware('center.plan:cloud_backup')
     *   ->middleware('center.plan:white_label')
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isCenter()) {
            return $this->forbiddenResponse(__('messages.forbidden'));
        }

        $currentTier = $this->billingService->resolveActivePlanTier($user);
        $requiredTier = CenterPlanTier::requiredTierForFeature($feature);

        if (! $currentTier->meets($requiredTier)) {
            $this->logSecurityEvent('center.plan_feature_blocked', [
                'user_id' => $user->id,
                'feature' => $feature,
                'current_plan' => $currentTier->value,
                'required_plan' => $requiredTier->value,
            ], 'info');

            $isEn = app()->getLocale() === 'en';
            $requiredName = $isEn ? $requiredTier->labelEn() : $requiredTier->labelAr();

            return response()->json([
                'success' => false,
                'message' => __('messages.center.subscription_required', ['plan' => $requiredName]),
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
