<?php

namespace App\Services\V1\Teacher;

use App\Enums\AcademicStatus;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\JoinMethod;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ExamSubmission;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\StudentProfile;
use App\Models\StudyGroup;
use App\Models\User;
use App\Repositories\Contracts\GroupEnrollmentRepositoryInterface;
use App\Repositories\Contracts\StudyGroupRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherRosterService extends BaseService
{
    public function __construct(
        protected StudyGroupRepositoryInterface $groupRepository,
        protected GroupEnrollmentRepositoryInterface $enrollmentRepository,
        protected TeacherBillingService $billingService
    ) {}

    /**
     * Retrieve the full student roster for a specific study group (enrolled + pending)
     * hydrated with live graded exam averages and streaks (zero N+1).
     *
     * @return array{group: StudyGroup, enrolled: Collection, pending: Collection, stats: array<string, mixed>}
     */
    public function getRoster(User $teacher, string $groupUuid, ?string $search = null, ?string $attendanceDate = null): array
    {
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        $enrolled = $this->enrollmentRepository->getEnrolledForGroup($group->id, $search);
        $pending = $this->enrollmentRepository->getPendingForGroup($group->id, $search);

        $targetDate = $attendanceDate ?: now()->toDateString();
        $dateSession = GroupAttendanceSession::where('study_group_id', $group->id)
            ->whereDate('session_date', $targetDate)
            ->first();

        $dateRecordsByEnrollment = $dateSession
            ? GroupAttendanceRecord::where('attendance_session_id', $dateSession->id)
                ->get(['group_enrollment_id', 'status', 'scanned_at'])
                ->keyBy('group_enrollment_id')
            : collect();

        $todayPresentCount = 0;
        $todayAbsentCount = 0;

        $studentIds = $enrolled->pluck('student_id')->filter()->unique()->values()->all();
        if (! empty($studentIds)) {
            $streaksByStudent = StudentProfile::whereIn('student_id', $studentIds)
                ->pluck('current_streak_days', 'student_id');

            $examStatsByStudent = ExamSubmission::query()
                ->join('exams', 'exams.id', '=', 'exam_submissions.exam_id')
                ->where('exams.teacher_id', $teacher->id)
                ->whereIn('exam_submissions.student_id', $studentIds)
                ->where('exam_submissions.status', ExamSubmissionStatus::GRADED->value)
                ->select([
                    'exam_submissions.student_id',
                    DB::raw('AVG(exam_submissions.percentage) as avg_pct'),
                    DB::raw('COUNT(*) as graded_count'),
                ])
                ->groupBy('exam_submissions.student_id')
                ->get()
                ->keyBy('student_id');

            foreach ($enrolled as $item) {
                $sid = (int) $item->student_id;
                $liveStreak = max((int) $item->streak_days, (int) ($streaksByStudent[$sid] ?? 0));
                $examRow = $examStatsByStudent->get($sid);
                $gradedCount = (int) ($examRow?->graded_count ?? 0);
                $liveAvgQuiz = $gradedCount > 0
                    ? (int) round((float) $examRow->avg_pct)
                    : (int) $item->avg_quiz_score;

                $attendance = (int) $item->attendance_rate;
                $liveStatus = match (true) {
                    $gradedCount > 0 && $liveAvgQuiz >= 95 && $attendance >= 85 => AcademicStatus::TOP_1_PERCENT->value,
                    $gradedCount > 0 && $liveAvgQuiz >= 85 && $attendance >= 75 => AcademicStatus::EXCELLENCE->value,
                    ($gradedCount > 0 && $liveAvgQuiz < 60) || ($item->total_sessions > 0 && $attendance < 65) => AcademicStatus::NEEDS_SUPPORT->value,
                    default => ($item->academic_status instanceof AcademicStatus ? $item->academic_status->value : AcademicStatus::GOOD->value),
                };

                $dateRec = $dateRecordsByEnrollment->get($item->id);
                $todayStatusVal = $dateRec
                    ? ($dateRec->status instanceof AttendanceStatus ? $dateRec->status->value : (string) $dateRec->status)
                    : null;

                if (in_array($todayStatusVal, [AttendanceStatus::PRESENT->value, AttendanceStatus::LATE->value], true)) {
                    $todayPresentCount++;
                } elseif (in_array($todayStatusVal, [AttendanceStatus::ABSENT->value, AttendanceStatus::EXCUSED->value], true)) {
                    $todayAbsentCount++;
                }

                $item->setAttribute('live_streak_days', $liveStreak);
                $item->setAttribute('live_avg_quiz_score', $liveAvgQuiz);
                $item->setAttribute('live_graded_exams_count', $gradedCount);
                $item->setAttribute('live_academic_status', $liveStatus);
                $item->setAttribute('today_attendance_status', $todayStatusVal);
                $item->setAttribute('today_scanned_at', $dateRec?->scanned_at);
            }
        }

        $totalEnrolled = $enrolled->count();
        $avgAttendance = $totalEnrolled > 0
            ? (int) round((float) $enrolled->avg('attendance_rate'))
            : 0;

        return [
            'group' => $group,
            'enrolled' => $enrolled,
            'pending' => $pending,
            'stats' => [
                'enrolledCount' => $totalEnrolled,
                'pendingCount' => $pending->count(),
                'maxStudents' => (int) $group->max_students,
                'isFull' => $totalEnrolled >= $group->max_students,
                'avgAttendanceRate' => $avgAttendance,
                'attendanceDate' => $targetDate,
                'todayPresentCount' => $todayPresentCount,
                'todayAbsentCount' => $todayAbsentCount,
                'todayUnmarkedCount' => max(0, $totalEnrolled - $todayPresentCount - $todayAbsentCount),
            ],
        ];
    }

    /**
     * Search registered students in the platform for teacher invitation.
     * When query is empty, returns the most recent active students up to $limit.
     */
    public function searchStudents(User $teacher, string $query, int $limit = 20): Collection
    {
        $clean = trim($query);
        $safeLimit = min(50, max(1, $limit));

        $builder = User::query()
            ->where('role', UserRole::STUDENT->value)
            ->where('status', UserStatus::ACTIVE->value)
            ->with(['parents', 'studentProfile:student_id,grade_ar']);

        if ($clean !== '') {
            $builder->where(function ($q) use ($clean) {
                $q->where('name', 'like', "%{$clean}%")
                    ->orWhere('email', 'like', "%{$clean}%")
                    ->orWhere('phone', 'like', "%{$clean}%")
                    ->orWhere('student_code', 'like', "%{$clean}%");
            });
        }

        return $builder
            ->orderByDesc('id')
            ->limit($safeLimit)
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

        return $this->transaction(function () use ($group, $studentUser, $data) {
            /** @var GroupEnrollment|null $existing */
            $existing = GroupEnrollment::withTrashed()
                ->where('study_group_id', $group->id)
                ->where('student_id', $studentUser->id)
                ->first();

            if ($existing) {
                if (! $existing->trashed()) {
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
                }

                // Restore soft-deleted or rejected enrollment back to pending
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update([
                    'status' => EnrollmentStatus::PENDING,
                    'join_method' => JoinMethod::TEACHER_INVITE,
                    'student_name_override' => $data['name_ar'] ?? $studentUser->name,
                    'student_phone_override' => $data['phone'] ?? $studentUser->phone,
                    'parent_name_ar' => $data['parent_name_ar'] ?? $existing->parent_name_ar,
                    'parent_phone' => $data['parent_phone'] ?? $existing->parent_phone,
                    'requested_at' => now(),
                ]);

                $enrollment = $existing;
            } else {
                $enrollment = GroupEnrollment::create([
                    'study_group_id' => $group->id,
                    'student_id' => $studentUser->id,
                    'status' => EnrollmentStatus::PENDING,
                    'join_method' => JoinMethod::TEACHER_INVITE,
                    'student_name_override' => $data['name_ar'] ?? $studentUser->name,
                    'student_phone_override' => $data['phone'] ?? $studentUser->phone,
                    'parent_name_ar' => $data['parent_name_ar'] ?? null,
                    'parent_phone' => $data['parent_phone'] ?? null,
                    'requested_at' => now(),
                ]);
            }

            $this->logSecurityEvent('teacher.roster.student_invited', [
                'group_uuid' => $group->uuid,
                'student_id' => $studentUser->id,
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
        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        // Idempotency guard: never reset attendance/barcode on an already enrolled student
        if ($enrollment->status === EnrollmentStatus::ENROLLED) {
            return $enrollment->load('student');
        }

        if ($enrollment->status !== EnrollmentStatus::PENDING) {
            throw ValidationException::withMessages([
                'enrollment' => ['يمكن قبول طلبات الانضمام المعلقة فقط.'],
            ]);
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

        return $this->transaction(function () use ($group, $enrollment) {
            $barcode = $enrollment->student_barcode ?: GroupEnrollment::generateUniqueBarcode($group->id, $enrollment->student_id);
            $passcode = $enrollment->student_passcode ?: GroupEnrollment::generatePasscode();

            $enrollment->update([
                'status' => EnrollmentStatus::ENROLLED,
                'student_barcode' => $barcode,
                'student_passcode' => $passcode,
                'enrolled_at' => now(),
                'attendance_rate' => 100,
                'attended_sessions' => 0,
                'total_sessions' => 0,
                'academic_status' => AcademicStatus::GOOD,
            ]);

            $this->logSecurityEvent('teacher.roster.student_accepted', [
                'group_uuid' => $group->uuid,
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
        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        if ($enrollment->status !== EnrollmentStatus::PENDING) {
            throw ValidationException::withMessages([
                'enrollment' => ['يمكن رفض طلبات الانضمام المعلقة فقط.'],
            ]);
        }

        return $this->transaction(function () use ($group, $enrollment) {
            $enrollment->update([
                'status' => EnrollmentStatus::REJECTED,
            ]);

            $this->logSecurityEvent('teacher.roster.student_rejected', [
                'group_uuid' => $group->uuid,
                'enrollment_uuid' => $enrollment->uuid,
            ], 'info', $enrollment);

            return $enrollment->load('student');
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
        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        return $this->transaction(function () use ($group, $enrollment) {
            $enrollment->update([
                'status' => EnrollmentStatus::REMOVED,
            ]);
            $enrollment->delete();

            $this->logSecurityEvent('teacher.roster.student_removed', [
                'group_uuid' => $group->uuid,
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
        $group = StudyGroup::query()
            ->whereRaw('UPPER(join_code) = ?', [$cleanCode])
            ->where('status', 'active')
            ->first();

        if (! $group) {
            throw ValidationException::withMessages([
                'join_code' => [__('messages.invalid_join_code')],
            ]);
        }

        return $this->transaction(function () use ($student, $group, $extraData) {
            /** @var GroupEnrollment|null $existing */
            $existing = GroupEnrollment::withTrashed()
                ->where('study_group_id', $group->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($existing && ! $existing->trashed()) {
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
            }

            // Check group capacity after verifying student is not already enrolled/pending
            $currentEnrolled = $this->enrollmentRepository->countEnrolledInGroup($group->id);
            if ($currentEnrolled >= $group->max_students) {
                throw ValidationException::withMessages([
                    'join_code' => [__('messages.group_capacity_reached', ['max' => $group->max_students])],
                ]);
            }

            // Enforce teacher's active plan student capacity limit
            $groupTeacher = User::find($group->teacher_id);
            if ($groupTeacher) {
                $this->assertTeacherPlanStudentQuota($groupTeacher);
            }

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update([
                    'status' => EnrollmentStatus::PENDING,
                    'join_method' => JoinMethod::JOIN_CODE,
                    'student_barcode' => null,
                    'student_passcode' => null,
                    'enrolled_at' => null,
                    'student_name_override' => $student->name,
                    'student_phone_override' => $student->phone,
                    'parent_name_ar' => $extraData['parent_name_ar'] ?? $existing->parent_name_ar,
                    'parent_phone' => $extraData['parent_phone'] ?? $existing->parent_phone,
                    'requested_at' => now(),
                ]);

                $enrollment = $existing;
            } else {
                $enrollment = GroupEnrollment::create([
                    'study_group_id' => $group->id,
                    'student_id' => $student->id,
                    'status' => EnrollmentStatus::PENDING,
                    'join_method' => JoinMethod::JOIN_CODE,
                    'student_name_override' => $student->name,
                    'student_phone_override' => $student->phone,
                    'parent_name_ar' => $extraData['parent_name_ar'] ?? null,
                    'parent_phone' => $extraData['parent_phone'] ?? null,
                    'requested_at' => now(),
                ]);
            }

            $this->logSecurityEvent('student.group_join_requested', [
                'group_uuid' => $group->uuid,
                'student_id' => $student->id,
                'student_code' => $student->student_code,
                'join_code' => $group->join_code,
            ], 'info', $enrollment);

            return $enrollment->load(['student', 'group']);
        });
    }

    /**
     * Resolve target student user from either ID or contact credentials.
     */
    protected function resolveStudentUser(array $data): User
    {
        $studentIdentifier = trim((string) ($data['student_id'] ?? ''));
        if ($studentIdentifier !== '') {
            $user = User::query()
                ->where('role', UserRole::STUDENT->value)
                ->where(function ($q) use ($studentIdentifier) {
                    if (is_numeric($studentIdentifier)) {
                        $q->where('id', (int) $studentIdentifier);
                    } else {
                        $q->where('student_code', $studentIdentifier)
                            ->orWhere('uuid', $studentIdentifier);
                    }
                })
                ->first();

            if ($user) {
                return $user;
            }

            if (empty($data['name_ar']) && empty($data['phone'])) {
                throw ValidationException::withMessages([
                    'student_id' => [__('messages.student_not_found')],
                ]);
            }
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone !== '') {
            $existingByPhone = User::where('phone', $phone)->first();
            if ($existingByPhone) {
                if ($existingByPhone->role === UserRole::STUDENT) {
                    return $existingByPhone;
                }

                throw ValidationException::withMessages([
                    'phone' => ['رقم الهاتف مسجل بالفعل لحساب غير طالب على المنصة.'],
                ]);
            }
        }

        // If no student user exists, auto-provision a platform student account for them
        $name = trim((string) ($data['name_ar'] ?? 'طالب جديد'));
        $safeEmail = 'std_'.time().'_'.Str::random(5).'@student.motafawweq.local';

        do {
            $studentCode = 'STD-'.random_int(100000, 999999);
        } while (User::where('student_code', $studentCode)->exists());

        return User::create([
            'name' => $name,
            'email' => $safeEmail,
            'phone' => $phone !== '' ? $phone : '010'.random_int(10000000, 99999999),
            'password' => Hash::make(Str::random(16)),
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'student_code' => $studentCode,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Enforce teacher subscription plan capacity for enrolled students across all groups.
     */
    protected function assertTeacherPlanStudentQuota(User $teacher): void
    {
        $tier = $this->billingService->resolveActivePlanTier($teacher);

        $maxStudents = $tier->maxStudents();
        if ($maxStudents === null) {
            return;
        }

        $totalEnrolled = GroupEnrollment::query()
            ->join('study_groups', 'study_groups.id', '=', 'group_enrollments.study_group_id')
            ->whereNull('study_groups.deleted_at')
            ->whereNull('group_enrollments.deleted_at')
            ->where('study_groups.teacher_id', $teacher->id)
            ->where('group_enrollments.status', EnrollmentStatus::ENROLLED->value)
            ->count();

        if ($totalEnrolled >= $maxStudents) {
            throw ValidationException::withMessages([
                'quota' => [__('messages.quota_students_exceeded', [
                    'max' => $maxStudents,
                    'plan' => $tier->label(),
                ])],
            ]);
        }
    }
}
