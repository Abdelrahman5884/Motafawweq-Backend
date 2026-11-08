<?php

namespace App\Services\V1\Teacher;

use App\Enums\AcademicStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\JoinMethod;
use App\Enums\TeacherPlanTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\GroupEnrollment;
use App\Models\StudyGroup;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Repositories\Contracts\GroupEnrollmentRepositoryInterface;
use App\Repositories\Contracts\StudyGroupRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherRosterService extends BaseService
{
    public function __construct(
        protected StudyGroupRepositoryInterface $groupRepository,
        protected GroupEnrollmentRepositoryInterface $enrollmentRepository
    ) {}

    /**
     * Retrieve the full student roster for a specific study group (enrolled + pending).
     *
     * @return array{group: StudyGroup, enrolled: Collection, pending: Collection, stats: array<string, mixed>}
     */
    public function getRoster(User $teacher, string $groupUuid, ?string $search = null): array
    {
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        $enrolled = $this->enrollmentRepository->getEnrolledForGroup($group->id, $search);
        $pending = $this->enrollmentRepository->getPendingForGroup($group->id, $search);

        return [
            'group'    => $group,
            'enrolled' => $enrolled,
            'pending'  => $pending,
            'stats'    => [
                'enrolledCount' => $enrolled->count(),
                'pendingCount'  => $pending->count(),
                'maxStudents'   => (int) $group->max_students,
                'isFull'        => $enrolled->count() >= $group->max_students,
            ],
        ];
    }

    /**
     * Search registered students in the platform for teacher invitation.
     */
    public function searchStudents(User $teacher, string $query, int $limit = 20): Collection
    {
        $clean = trim($query);
        if ($clean === '') {
            return new Collection();
        }

        return User::query()
            ->where('role', UserRole::STUDENT->value)
            ->where('status', UserStatus::ACTIVE->value)
            ->where(function ($q) use ($clean) {
                $q->where('name', 'like', "%{$clean}%")
                    ->orWhere('email', 'like', "%{$clean}%")
                    ->orWhere('phone', 'like', "%{$clean}%")
                    ->orWhere('student_code', 'like', "%{$clean}%");
            })
            ->with(['parents'])
            ->limit(min(50, max(1, $limit)))
            ->get();
    }

    /**
     * Invite a student directly to the study group.
     */
    public function inviteStudent(User $teacher, string $groupUuid, array $data): GroupEnrollment
    {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        // Check group capacity
        $currentEnrolled = $this->enrollmentRepository->countEnrolledInGroup($group->id);
        if ($currentEnrolled >= $group->max_students) {
            throw ValidationException::withMessages([
                'group' => [__('messages.group_capacity_reached', ['max' => $group->max_students])],
            ]);
        }

        // Check teacher plan quota
        $this->assertTeacherPlanStudentQuota($teacher);

        // Resolve target student user
        $studentUser = $this->resolveStudentUser($data);

        return $this->transaction(function () use ($teacher, $group, $studentUser, $data) {
            /** @var GroupEnrollment|null $existing */
            $existing = GroupEnrollment::withTrashed()
                ->where('study_group_id', $group->id)
                ->where('student_id', $studentUser->id)
                ->first();

            if ($existing) {
                if ($existing->status === EnrollmentStatus::ENROLLED) {
                    throw ValidationException::withMessages([
                        'student' => [__('messages.student_already_enrolled')],
                    ]);
                }
                if ($existing->status === EnrollmentStatus::PENDING) {
                    throw ValidationException::withMessages([
                        'student' => [__('messages.student_already_pending')],
                    ]);
                }

                // Restore soft-deleted or rejected enrollment back to pending
                $existing->restore();
                $existing->update([
                    'status'                 => EnrollmentStatus::PENDING,
                    'join_method'            => JoinMethod::TEACHER_INVITE,
                    'student_name_override'  => $data['name_ar'] ?? $studentUser->name,
                    'student_phone_override' => $data['phone'] ?? $studentUser->phone,
                    'parent_name_ar'         => $data['parent_name_ar'] ?? $existing->parent_name_ar,
                    'parent_phone'           => $data['parent_phone'] ?? $existing->parent_phone,
                    'requested_at'           => now(),
                ]);

                $enrollment = $existing;
            } else {
                $enrollment = GroupEnrollment::create([
                    'study_group_id'         => $group->id,
                    'student_id'             => $studentUser->id,
                    'status'                 => EnrollmentStatus::PENDING,
                    'join_method'            => JoinMethod::TEACHER_INVITE,
                    'student_name_override'  => $data['name_ar'] ?? $studentUser->name,
                    'student_phone_override' => $data['phone'] ?? $studentUser->phone,
                    'parent_name_ar'         => $data['parent_name_ar'] ?? null,
                    'parent_phone'           => $data['parent_phone'] ?? null,
                    'requested_at'           => now(),
                ]);
            }

            $this->logSecurityEvent('teacher.roster.student_invited', [
                'group_uuid'   => $group->uuid,
                'student_id'   => $studentUser->id,
                'student_code' => $studentUser->student_code,
            ], 'info', $enrollment);

            return $enrollment->load('student');
        });
    }

    /**
     * Accept a pending student into the group and generate unique barcode + passcode.
     */
    public function acceptStudent(User $teacher, string $groupUuid, string $enrollmentUuid): GroupEnrollment
    {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        $enrollment = $this->enrollmentRepository->findInGroup($group->id, $enrollmentUuid);
        if (!$enrollment) {
            throw (new ModelNotFoundException())->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        // Check group capacity
        $currentEnrolled = $this->enrollmentRepository->countEnrolledInGroup($group->id);
        if ($currentEnrolled >= $group->max_students) {
            throw ValidationException::withMessages([
                'group' => [__('messages.group_capacity_reached', ['max' => $group->max_students])],
            ]);
        }

        // Check teacher plan quota
        $this->assertTeacherPlanStudentQuota($teacher);

        return $this->transaction(function () use ($teacher, $group, $enrollment) {
            $barcode = GroupEnrollment::generateUniqueBarcode($group->id, $enrollment->student_id);
            $passcode = GroupEnrollment::generatePasscode();

            $enrollment->update([
                'status'            => EnrollmentStatus::ENROLLED,
                'student_barcode'   => $barcode,
                'student_passcode'  => $passcode,
                'enrolled_at'       => now(),
                'attendance_rate'   => 100,
                'attended_sessions' => 0,
                'total_sessions'    => 0,
                'academic_status'   => AcademicStatus::GOOD,
            ]);

            $this->logSecurityEvent('teacher.roster.student_accepted', [
                'group_uuid'      => $group->uuid,
                'enrollment_uuid' => $enrollment->uuid,
                'student_barcode' => $barcode,
            ], 'info', $enrollment);

            return $enrollment->load('student');
        });
    }

    /**
     * Reject a pending student join request.
     */
    public function rejectStudent(User $teacher, string $groupUuid, string $enrollmentUuid): GroupEnrollment
    {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        $enrollment = $this->enrollmentRepository->findInGroup($group->id, $enrollmentUuid);
        if (!$enrollment) {
            throw (new ModelNotFoundException())->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        return $this->transaction(function () use ($teacher, $group, $enrollment) {
            $enrollment->update([
                'status' => EnrollmentStatus::REJECTED,
            ]);

            $this->logSecurityEvent('teacher.roster.student_rejected', [
                'group_uuid'      => $group->uuid,
                'enrollment_uuid' => $enrollment->uuid,
            ], 'info', $enrollment);

            return $enrollment;
        });
    }

    /**
     * Remove an enrolled student from the study group.
     */
    public function removeStudent(User $teacher, string $groupUuid, string $enrollmentUuid): bool
    {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        $enrollment = $this->enrollmentRepository->findInGroup($group->id, $enrollmentUuid);
        if (!$enrollment) {
            throw (new ModelNotFoundException())->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        return $this->transaction(function () use ($teacher, $group, $enrollment) {
            $enrollment->update([
                'status' => EnrollmentStatus::REMOVED,
            ]);
            $enrollment->delete();

            $this->logSecurityEvent('teacher.roster.student_removed', [
                'group_uuid'      => $group->uuid,
                'enrollment_uuid' => $enrollment->uuid,
            ], 'warning', $enrollment);

            return true;
        });
    }

    /**
     * Allow an authenticated student to request joining a group using the join code or QR scan.
     */
    public function studentJoinByCode(User $student, string $joinCode, array $extraData = []): GroupEnrollment
    {
        $cleanCode = strtoupper(trim($joinCode));

        /** @var StudyGroup|null $group */
        $group = StudyGroup::where('join_code', $cleanCode)
            ->where('status', 'active')
            ->first();

        if (!$group) {
            throw ValidationException::withMessages([
                'join_code' => [__('messages.invalid_join_code')],
            ]);
        }

        // Check group capacity
        $currentEnrolled = $this->enrollmentRepository->countEnrolledInGroup($group->id);
        if ($currentEnrolled >= $group->max_students) {
            throw ValidationException::withMessages([
                'join_code' => [__('messages.group_capacity_reached', ['max' => $group->max_students])],
            ]);
        }

        return $this->transaction(function () use ($student, $group, $extraData) {
            /** @var GroupEnrollment|null $existing */
            $existing = GroupEnrollment::withTrashed()
                ->where('study_group_id', $group->id)
                ->where('student_id', $student->id)
                ->first();

            if ($existing) {
                if ($existing->status === EnrollmentStatus::ENROLLED) {
                    throw ValidationException::withMessages([
                        'join_code' => [__('messages.student_already_enrolled')],
                    ]);
                }
                if ($existing->status === EnrollmentStatus::PENDING) {
                    throw ValidationException::withMessages([
                        'join_code' => [__('messages.student_already_pending')],
                    ]);
                }

                $existing->restore();
                $existing->update([
                    'status'                 => EnrollmentStatus::PENDING,
                    'join_method'            => JoinMethod::JOIN_CODE,
                    'student_name_override'  => $student->name,
                    'student_phone_override' => $student->phone,
                    'parent_name_ar'         => $extraData['parent_name_ar'] ?? $existing->parent_name_ar,
                    'parent_phone'           => $extraData['parent_phone'] ?? $existing->parent_phone,
                    'requested_at'           => now(),
                ]);

                $enrollment = $existing;
            } else {
                $enrollment = GroupEnrollment::create([
                    'study_group_id'         => $group->id,
                    'student_id'             => $student->id,
                    'status'                 => EnrollmentStatus::PENDING,
                    'join_method'            => JoinMethod::JOIN_CODE,
                    'student_name_override'  => $student->name,
                    'student_phone_override' => $student->phone,
                    'parent_name_ar'         => $extraData['parent_name_ar'] ?? null,
                    'parent_phone'           => $extraData['parent_phone'] ?? null,
                    'requested_at'           => now(),
                ]);
            }

            $this->logSecurityEvent('student.group_join_requested', [
                'group_uuid'   => $group->uuid,
                'student_id'   => $student->id,
                'student_code' => $student->student_code,
                'join_code'    => $group->join_code,
            ], 'info', $enrollment);

            return $enrollment;
        });
    }

    /**
     * Resolve target student user from either ID or contact credentials.
     */
    protected function resolveStudentUser(array $data): User
    {
        $studentIdentifier = $data['student_id'] ?? null;
        if ($studentIdentifier) {
            $user = User::where('id', is_numeric($studentIdentifier) ? (int) $studentIdentifier : 0)
                ->orWhere('student_code', (string) $studentIdentifier)
                ->first();

            if ($user && $user->role === UserRole::STUDENT) {
                return $user;
            }
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone !== '') {
            $user = User::where('phone', $phone)->first();
            if ($user) {
                return $user;
            }
        }

        // If no user exists, auto-provision a platform student account for them
        $name = trim((string) ($data['name_ar'] ?? 'طالب جديد'));
        $safeEmail = 'std_' . time() . '_' . Str::random(5) . '@student.motafawweq.local';

        return User::create([
            'name'              => $name,
            'email'             => $safeEmail,
            'phone'             => $phone !== '' ? $phone : '010' . random_int(10000000, 99999999),
            'password'          => Hash::make(Str::random(16)),
            'role'              => UserRole::STUDENT,
            'status'            => UserStatus::ACTIVE,
            'student_code'      => 'STD-' . random_int(100000, 999999),
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Enforce teacher subscription plan capacity for enrolled students across all groups.
     */
    protected function assertTeacherPlanStudentQuota(User $teacher): void
    {
        $profile = TeacherProfile::where('user_id', $teacher->id)->first();
        $tier = $profile?->plan_tier ?? TeacherPlanTier::PLUS;

        $maxStudents = $tier->maxStudents();
        if ($maxStudents === null) {
            return;
        }

        $totalEnrolled = GroupEnrollment::query()
            ->join('study_groups', 'study_groups.id', '=', 'group_enrollments.study_group_id')
            ->where('study_groups.teacher_id', $teacher->id)
            ->where('group_enrollments.status', EnrollmentStatus::ENROLLED->value)
            ->count();

        if ($totalEnrolled >= $maxStudents) {
            throw ValidationException::withMessages([
                'quota' => [__('messages.quota_students_exceeded', [
                    'max'  => $maxStudents,
                    'plan' => $tier->label(),
                ])],
            ]);
        }
    }
}

