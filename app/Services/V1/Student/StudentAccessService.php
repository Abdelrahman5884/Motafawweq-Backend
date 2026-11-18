<?php

namespace App\Services\V1\Student;

use App\Enums\CourseStatus;
use App\Enums\DirectPaymentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\CourseLeague;
use App\Models\Exam;
use App\Models\Homework;
use App\Models\Lesson;
use App\Models\StudentDirectPayment;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Single source of truth for student multi-tenant access control.
 * Ensures a student can ONLY access courses, lessons, exams, homeworks, and leagues
 * belonging to teachers/courses where the student holds an active enrollment or confirmed payment.
 */
class StudentAccessService extends BaseService
{
    /** @var array<int, array<string, array<int>>> */
    protected array $entitlementCache = [];

    /**
     * Compute and cache all teacher IDs, group IDs, and course IDs the student is entitled to access.
     *
     * @return array{group_ids: array<int>, teacher_ids: array<int>, course_ids: array<int>}
     */
    public function getEntitlements(User $student): array
    {
        if (isset($this->entitlementCache[$student->id])) {
            return $this->entitlementCache[$student->id];
        }

        $enrolledGroups = StudyGroup::query()
            ->where('status', 'active')
            ->whereHas('enrollments', function ($q) use ($student) {
                $q->where('student_id', $student->id)
                    ->where('status', EnrollmentStatus::ENROLLED);
            })
            ->get(['id', 'teacher_id', 'course_id']);

        $confirmedPayments = StudentDirectPayment::query()
            ->where('student_id', $student->id)
            ->where('status', DirectPaymentStatus::CONFIRMED)
            ->get(['teacher_id', 'course_id', 'study_group_id']);

        $groupIds = array_values(array_unique(array_filter(array_merge(
            $enrolledGroups->pluck('id')->all(),
            $confirmedPayments->pluck('study_group_id')->all()
        ))));

        $teacherIds = array_values(array_unique(array_filter(array_merge(
            $enrolledGroups->pluck('teacher_id')->all(),
            $confirmedPayments->pluck('teacher_id')->all()
        ))));

        $directCourseIds = array_filter(array_merge(
            $enrolledGroups->pluck('course_id')->all(),
            $confirmedPayments->pluck('course_id')->all()
        ));

        $teacherCourseIds = ! empty($teacherIds)
            ? Course::query()
                ->whereIn('teacher_id', $teacherIds)
                ->where('status', CourseStatus::PUBLISHED)
                ->pluck('id')
                ->all()
            : [];

        $courseIds = array_values(array_unique(array_merge($directCourseIds, $teacherCourseIds)));

        return $this->entitlementCache[$student->id] = [
            'group_ids' => array_map('intval', $groupIds),
            'teacher_ids' => array_map('intval', $teacherIds),
            'course_ids' => array_map('intval', $courseIds),
        ];
    }

    /**
     * Clear cached entitlements for a student after joining/leaving or payment confirmation.
     */
    public function clearCache(User $student): void
    {
        unset($this->entitlementCache[$student->id]);
    }

    public function canAccessTeacherContent(User $student, int $teacherId, ?int $courseId = null): bool
    {
        if (! $student->isStudent() || ! $student->isActive()) {
            return false;
        }

        $entitlements = $this->getEntitlements($student);

        if ($courseId !== null && in_array((int) $courseId, $entitlements['course_ids'], true)) {
            return true;
        }

        if ($courseId !== null && Course::where('id', $courseId)->where('status', CourseStatus::PUBLISHED)->where('price_egp', '<=', 0)->exists()) {
            return true;
        }

        return in_array((int) $teacherId, $entitlements['teacher_ids'], true);
    }

    public function canAccessCourse(User $student, Course $course): bool
    {
        if ($course->status !== CourseStatus::PUBLISHED) {
            return false;
        }

        return $this->canAccessTeacherContent($student, (int) $course->teacher_id, (int) $course->id);
    }

    public function canAccessLesson(User $student, Lesson $lesson): bool
    {
        if ($lesson->status !== LessonStatus::PUBLISHED) {
            return false;
        }

        return $this->canAccessTeacherContent($student, (int) $lesson->teacher_id, (int) $lesson->course_id);
    }

    public function canAccessExam(User $student, Exam $exam): bool
    {
        return $this->canAccessTeacherContent($student, (int) $exam->teacher_id, $exam->course_id ? (int) $exam->course_id : null);
    }

    public function canAccessHomework(User $student, Homework $homework): bool
    {
        return $this->canAccessTeacherContent($student, (int) $homework->teacher_id, $homework->course_id ? (int) $homework->course_id : null);
    }

    public function canAccessLeague(User $student, CourseLeague $league): bool
    {
        return $this->canAccessTeacherContent($student, (int) $league->teacher_id, $league->course_id ? (int) $league->course_id : null);
    }

    /**
     * Assert that the student is entitled to access a specific teacher/course resource.
     * Logs a security audit event and throws AuthorizationException on violation.
     *
     * @throws AuthorizationException
     */
    public function assertCanAccess(User $student, Model $resource, int $teacherId, ?int $courseId = null): void
    {
        if (! $this->canAccessTeacherContent($student, $teacherId, $courseId)) {
            $this->logSecurityEvent(
                'student.idor_attempt_blocked',
                [
                    'attempted_by_user_id' => $student->id,
                    'resource_owner_id' => $teacherId,
                    'course_id' => $courseId,
                    'resource_type' => get_class($resource),
                    'resource_uuid' => $resource->uuid ?? $resource->getKey(),
                ],
                'warning',
                $resource
            );

            throw new AuthorizationException(__('messages.forbidden_resource_owner'));
        }
    }
}
