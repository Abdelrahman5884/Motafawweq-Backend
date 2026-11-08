<?php

namespace App\Http\Middleware;

use App\Enums\TeacherPlanTier;
use App\Traits\ApiResponse;
use App\Traits\HasSecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class CheckTeacherPlanQuota
{
    use ApiResponse, HasSecurityAudit;

    /**
     * Enforce teacher subscription plan feature & capacity quotas.
     *
     * Usage:
     *   ->middleware('teacher.quota:courses')
     *   ->middleware('teacher.quota:students')
     *   ->middleware('teacher.quota:certificates')
     *   ->middleware('teacher.quota:leagues')
     *   ->middleware('teacher.quota:whatsapp_alerts')
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (!$user || !$user->isTeacher()) {
            return $this->forbiddenResponse(__('messages.forbidden'));
        }

        // Resolve teacher's current plan tier (defaults to PLUS during initial setup, or reads from teacher_profiles)
        $tierValue = 'plus';
        if (Schema::hasTable('teacher_profiles') && $user->relationLoaded('teacherProfile') && $user->teacherProfile) {
            $tierValue = $user->teacherProfile->plan_tier?->value ?? $user->teacherProfile->plan_tier ?? 'plus';
        } elseif (Schema::hasTable('teacher_profiles')) {
            $profileTier = \Illuminate\Support\Facades\DB::table('teacher_profiles')
                ->where('user_id', $user->id)
                ->value('plan_tier');
            if ($profileTier) {
                $tierValue = $profileTier;
            }
        }

        $tier = TeacherPlanTier::tryFrom((string) $tierValue) ?? TeacherPlanTier::PLUS;

        switch ($feature) {
            case 'courses':
                $maxCourses = $tier->maxCourses();
                if ($maxCourses !== null && Schema::hasTable('courses')) {
                    $activeCoursesCount = \Illuminate\Support\Facades\DB::table('courses')
                        ->where('teacher_id', $user->id)
                        ->whereNull('deleted_at')
                        ->count();

                    if ($activeCoursesCount >= $maxCourses) {
                        return $this->quotaExceededResponse($user->id, $tier, $feature, __('messages.quota_courses_exceeded', [
                            'max'  => $maxCourses,
                            'plan' => $tier->label(),
                        ]));
                    }
                }
                break;

            case 'students':
                $maxStudents = $tier->maxStudents();
                if ($maxStudents !== null && Schema::hasTable('group_enrollments') && Schema::hasTable('study_groups')) {
                    $enrolledCount = \Illuminate\Support\Facades\DB::table('group_enrollments')
                        ->join('study_groups', 'study_groups.id', '=', 'group_enrollments.study_group_id')
                        ->where('study_groups.teacher_id', $user->id)
                        ->where('group_enrollments.status', 'enrolled')
                        ->count();

                    if ($enrolledCount >= $maxStudents) {
                        return $this->quotaExceededResponse($user->id, $tier, $feature, __('messages.quota_students_exceeded', [
                            'max'  => $maxStudents,
                            'plan' => $tier->label(),
                        ]));
                    }
                }
                break;

            case 'certificates':
                if (!$tier->allowsCertificates()) {
                    return $this->quotaExceededResponse($user->id, $tier, $feature, __('messages.quota_certificates_not_allowed', [
                        'plan' => $tier->label(),
                    ]));
                }
                break;

            case 'leagues':
                if (!$tier->allowsCourseLeagues()) {
                    return $this->quotaExceededResponse($user->id, $tier, $feature, __('messages.quota_leagues_not_allowed', [
                        'plan' => $tier->label(),
                    ]));
                }
                break;

            case 'whatsapp_alerts':
                if (!$tier->allowsWhatsappParentAlerts()) {
                    return $this->quotaExceededResponse($user->id, $tier, $feature, __('messages.quota_whatsapp_not_allowed', [
                        'plan' => $tier->label(),
                    ]));
                }
                break;
        }

        return $next($request);
    }

    protected function quotaExceededResponse(int $userId, TeacherPlanTier $tier, string $feature, string $message): Response
    {
        $this->logSecurityEvent('teacher.plan_quota_exceeded', [
            'user_id'   => $userId,
            'plan_tier' => $tier->value,
            'feature'   => $feature,
        ], 'info');

        return response()->json([
            'success'      => false,
            'message'      => $message,
            'error_code'   => 'PLAN_QUOTA_EXCEEDED',
            'current_plan' => $tier->value,
            'feature'      => $feature,
        ], Response::HTTP_FORBIDDEN);
    }
}

