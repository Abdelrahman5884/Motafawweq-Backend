<?php

namespace App\Http\Middleware;

use App\Enums\CenterPlanTier;
use App\Services\V1\Center\CenterBillingService;
use App\Traits\ApiResponse;
use App\Traits\HasSecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCenterPlanQuota
{
    use ApiResponse, HasSecurityAudit;

    public function __construct(
        protected CenterBillingService $billingService
    ) {}

    /**
     * Enforce center subscription plan capacity quotas.
     *
     * Usage:
     *   ->middleware('center.quota:branches')
     *   ->middleware('center.quota:rooms')
     *   ->middleware('center.quota:teachers')
     *   ->middleware('center.quota:staff')
     *   ->middleware('center.quota:users')
     */
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isCenter()) {
            return $this->forbiddenResponse(__('messages.forbidden'));
        }

        $tier = $this->billingService->resolveActivePlanTier($user);
        $usage = $this->billingService->getUsageStats($user);
        $nextTier = $tier === CenterPlanTier::FREE ? CenterPlanTier::PLUS : CenterPlanTier::PRO;

        $check = match ($resource) {
            'branches' => [
                'limit' => $tier->maxBranches(),
                'current' => $usage['branchesCount'],
                'key' => 'messages.center.quota_branches_exceeded',
            ],
            'rooms' => [
                'limit' => $tier->maxRooms(),
                'current' => $usage['roomsCount'],
                'key' => 'messages.center.quota_rooms_exceeded',
            ],
            'groups' => [
                'limit' => $tier->maxGroups(),
                'current' => $usage['groupsCount'],
                'key' => 'messages.center.quota_groups_exceeded',
            ],
            'teachers' => [
                'limit' => $tier->maxTeachers(),
                'current' => $usage['teachersCount'],
                'key' => 'messages.center.quota_teachers_exceeded',
            ],
            'staff' => [
                'limit' => $tier->maxStaff(),
                'current' => $usage['staffCount'],
                'key' => 'messages.center.quota_staff_exceeded',
            ],
            'users' => [
                'limit' => $tier->maxUsers(),
                'current' => $usage['activeUsersCount'],
                'key' => 'messages.center.quota_users_exceeded',
            ],
            default => null,
        };

        if ($check !== null && $check['limit'] !== null && $check['current'] >= $check['limit']) {
            $this->logSecurityEvent('center.plan_quota_exceeded', [
                'user_id' => $user->id,
                'plan_tier' => $tier->value,
                'required_plan' => $nextTier->value,
                'resource' => $resource,
                'limit' => $check['limit'],
                'current_usage' => $check['current'],
            ], 'info');

            return response()->json([
                'success' => false,
                'message' => __($check['key'], [
                    'max' => $check['limit'],
                    'plan' => $tier->label(),
                ]),
                'error_code' => 'PLAN_QUOTA_EXCEEDED',
                'current_plan' => $tier->value,
                'required_plan' => $nextTier->value,
                'required_plan_name_ar' => $nextTier->labelAr(),
                'required_plan_name_en' => $nextTier->labelEn(),
                'feature' => $resource,
                'limit' => $check['limit'],
                'current_usage' => $check['current'],
            ], Response::HTTP_FORBIDDEN);
        }

        // Also enforce overall users cap when adding teachers or staff
        if (in_array($resource, ['teachers', 'staff'], true)) {
            $maxUsers = $tier->maxUsers();
            if ($maxUsers !== null && $usage['activeUsersCount'] >= $maxUsers) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.center.quota_users_exceeded', [
                        'max' => $maxUsers,
                        'plan' => $tier->label(),
                    ]),
                    'error_code' => 'PLAN_QUOTA_EXCEEDED',
                    'current_plan' => $tier->value,
                    'required_plan' => $nextTier->value,
                    'required_plan_name_ar' => $nextTier->labelAr(),
                    'required_plan_name_en' => $nextTier->labelEn(),
                    'feature' => 'users',
                    'limit' => $maxUsers,
                    'current_usage' => $usage['activeUsersCount'],
                ], Response::HTTP_FORBIDDEN);
            }
        }

        return $next($request);
    }
}
