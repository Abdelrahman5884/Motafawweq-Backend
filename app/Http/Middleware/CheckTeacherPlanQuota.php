<?php

namespace App\Http\Middleware;

use App\Enums\TeacherPlanTier;
use App\Services\V1\Teacher\TeacherBillingService;
use App\Traits\ApiResponse;
use App\Traits\HasSecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class CheckTeacherPlanQuota
{
    use ApiResponse, HasSecurityAudit;

    public function __construct(
        protected TeacherBillingService $billingService
    ) {}

    /**
     * Enforce teacher subscription plan feature & capacity quotas.
     *
     * Usage:
     *   ->middleware('teacher.quota:courses')
     *   ->middleware('teacher.quota:lessons')
     *   ->middleware('teacher.quota:groups')
     *   ->middleware('teacher.quota:students')
     *   ->middleware('teacher.quota:certificates')
     *   ->middleware('teacher.quota:leagues')
     *   ->middleware('teacher.quota:whatsapp_alerts')
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isTeacher()) {
            return $this->forbiddenResponse(__('messages.forbidden'));
        }

        $tier = $this->billingService->resolveActivePlanTier($user);

        switch ($feature) {
            case 'courses':
                $maxCourses = $tier->maxCourses();
                if ($maxCourses !== null && Schema::hasTable('courses')) {
                    $activeCoursesCount = DB::table('courses')
                        ->where('teacher_id', $user->id)
                        ->whereNull('deleted_at')
                        ->count();

                    if ($activeCoursesCount >= $maxCourses) {
                        return $this->quotaExceededResponse(
                            $user->id,
                            $tier,
                            TeacherPlanTier::PLUS,
                            $feature,
                            $maxCourses,
                            $activeCoursesCount,
                            __('messages.quota_courses_exceeded', [
                                'max' => $maxCourses,
                                'plan' => $tier->label(),
                            ])
                        );
                    }
                }
                break;

            case 'lessons':
                $maxLessons = $tier->maxLessons();
                if ($maxLessons !== null && Schema::hasTable('lessons')) {
                    $activeLessonsCount = DB::table('lessons')
                        ->where('teacher_id', $user->id)
                        ->whereNull('deleted_at')
                        ->count();

                    if ($activeLessonsCount >= $maxLessons) {
                        return $this->quotaExceededResponse(
                            $user->id,
                            $tier,
                            TeacherPlanTier::PLUS,
                            $feature,
                            $maxLessons,
                            $activeLessonsCount,
                            __('messages.quota_lessons_exceeded', [
                                'max' => $maxLessons,
                                'plan' => $tier->label(),
                            ])
                        );
                    }
                }
                break;

            case 'groups':
                $maxGroups = $tier->maxGroups();
                if ($maxGroups !== null && Schema::hasTable('study_groups')) {
                    $activeGroupsCount = DB::table('study_groups')
                        ->where('teacher_id', $user->id)
                        ->whereNull('deleted_at')
                        ->count();

                    if ($activeGroupsCount >= $maxGroups) {
                        $nextTier = $tier === TeacherPlanTier::FREE ? TeacherPlanTier::PLUS : TeacherPlanTier::PRO;

                        return $this->quotaExceededResponse(
                            $user->id,
                            $tier,
                            $nextTier,
                            $feature,
                            $maxGroups,
                            $activeGroupsCount,
                            __('messages.quota_groups_exceeded', [
                                'max' => $maxGroups,
                                'plan' => $tier->label(),
                            ])
                        );
                    }
                }
                break;

            case 'students':
                $maxStudents = $tier->maxStudents();
                if ($maxStudents !== null && Schema::hasTable('group_enrollments') && Schema::hasTable('study_groups')) {
                    $enrolledCount = DB::table('group_enrollments')
                        ->join('study_groups', 'study_groups.id', '=', 'group_enrollments.study_group_id')
                        ->where('study_groups.teacher_id', $user->id)
                        ->whereNull('study_groups.deleted_at')
                        ->whereNull('group_enrollments.deleted_at')
                        ->where('group_enrollments.status', 'enrolled')
                        ->count();

                    if ($enrolledCount >= $maxStudents) {
                        return $this->quotaExceededResponse(
                            $user->id,
                            $tier,
                            TeacherPlanTier::PLUS,
                            $feature,
                            $maxStudents,
                            $enrolledCount,
                            __('messages.quota_students_exceeded', [
                                'max' => $maxStudents,
                                'plan' => $tier->label(),
                            ])
                        );
                    }
                }
                break;

            case 'certificates':
                if (! $tier->allowsCertificates()) {
                    return $this->quotaExceededResponse(
                        $user->id,
                        $tier,
                        TeacherPlanTier::PLUS,
                        $feature,
                        null,
                        null,
                        __('messages.quota_certificates_not_allowed', [
                            'plan' => $tier->label(),
                        ])
                    );
                }
                break;

            case 'leagues':
                if (! $tier->allowsCourseLeagues()) {
                    return $this->quotaExceededResponse(
                        $user->id,
                        $tier,
                        TeacherPlanTier::PLUS,
                        $feature,
                        null,
                        null,
                        __('messages.quota_leagues_not_allowed', [
                            'plan' => $tier->label(),
                        ])
                    );
                }
                break;

            case 'whatsapp_alerts':
                if (! $tier->allowsWhatsappParentAlerts()) {
                    return $this->quotaExceededResponse(
                        $user->id,
                        $tier,
                        TeacherPlanTier::PLUS,
                        $feature,
                        null,
                        null,
                        __('messages.quota_whatsapp_not_allowed', [
                            'plan' => $tier->label(),
                        ])
                    );
                }
                break;
        }

        return $next($request);
    }

    protected function quotaExceededResponse(
        int $userId,
        TeacherPlanTier $tier,
        TeacherPlanTier $requiredTier,
        string $feature,
        ?int $limit,
        ?int $currentUsage,
        string $message
    ): Response {
        $this->logSecurityEvent('teacher.plan_quota_exceeded', [
            'user_id' => $userId,
            'plan_tier' => $tier->value,
            'required_plan' => $requiredTier->value,
            'feature' => $feature,
            'limit' => $limit,
            'current_usage' => $currentUsage,
        ], 'info');

        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'PLAN_QUOTA_EXCEEDED',
            'current_plan' => $tier->value,
            'required_plan' => $requiredTier->value,
            'required_plan_name_ar' => $requiredTier->labelAr(),
            'required_plan_name_en' => $requiredTier->labelEn(),
            'feature' => $feature,
            'limit' => $limit,
            'current_usage' => $currentUsage,
        ], Response::HTTP_FORBIDDEN);
    }
}
