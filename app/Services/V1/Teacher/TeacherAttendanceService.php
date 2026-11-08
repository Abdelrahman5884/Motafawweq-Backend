<?php

namespace App\Services\V1\Teacher;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\StudyGroup;
use App\Models\User;
use App\Repositories\Contracts\StudyGroupRepositoryInterface;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherAttendanceService extends BaseService
{
    public function __construct(
        protected StudyGroupRepositoryInterface $groupRepository
    ) {}

    /**
     * Scan attendance via Barcode, Passcode, signed QR Token, or Student Code.
     *
     * @return array{session: GroupAttendanceSession, record: GroupAttendanceRecord, enrollment: GroupEnrollment, alreadyRecorded: bool}
     */
    public function scanAttendance(
        User $teacher,
        string $groupUuid,
        string $codeOrToken,
        string $method = 'qr_scan',
        ?string $notes = null
    ): array {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        $enrollment = $this->resolveEnrollmentByCode($group, $codeOrToken);
        if (!$enrollment) {
            throw ValidationException::withMessages([
                'code' => [__('messages.attendance_student_not_found')],
            ]);
        }

        $today = now()->toDateString();
        $session = $this->getOrCreateSessionForGroup($group, $teacher, $today);

        return $this->transaction(function () use ($teacher, $group, $session, $enrollment, $method, $notes) {
            /** @var GroupAttendanceRecord|null $existingRecord */
            $existingRecord = GroupAttendanceRecord::where('attendance_session_id', $session->id)
                ->where('group_enrollment_id', $enrollment->id)
                ->first();

            $alreadyRecorded = false;
            $recordMethodEnum = AttendanceRecordMethod::tryFrom($method) ?? AttendanceRecordMethod::QR_SCAN;

            if ($existingRecord && in_array($existingRecord->status, [AttendanceStatus::PRESENT, AttendanceStatus::LATE], true)) {
                $alreadyRecorded = true;
                $record = $existingRecord;
            } else {
                $record = GroupAttendanceRecord::updateOrCreate(
                    [
                        'attendance_session_id' => $session->id,
                        'group_enrollment_id'   => $enrollment->id,
                    ],
                    [
                        'study_group_id'  => $group->id,
                        'student_id'      => $enrollment->student_id,
                        'status'          => AttendanceStatus::PRESENT,
                        'recorded_method' => $recordMethodEnum,
                        'scanned_at'      => now(),
                        'recorded_by'     => $teacher->id,
                        'notes'           => $notes ?? $existingRecord?->notes,
                    ]
                );

                $this->recalculateEnrollmentMetrics($enrollment, $group);
                $session->recalculateSummary();
            }

            $this->logSecurityEvent('teacher.attendance.scanned', [
                'group_uuid'       => $group->uuid,
                'session_uuid'     => $session->uuid,
                'enrollment_uuid'  => $enrollment->uuid,
                'student_id'       => $enrollment->student_id,
                'already_recorded' => $alreadyRecorded,
                'method'           => $recordMethodEnum->value,
            ], 'info', $record);

            return [
                'session'         => $session,
                'record'          => $record->load('student'),
                'enrollment'      => $enrollment,
                'alreadyRecorded' => $alreadyRecorded,
            ];
        });
    }

    /**
     * Manually toggle attendance status (present / absent) for a student in a session.
     */
    public function toggleAttendance(
        User $teacher,
        string $groupUuid,
        string $enrollmentUuid,
        ?string $status = null,
        ?string $date = null,
        ?string $notes = null
    ): GroupAttendanceRecord {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('uuid', $enrollmentUuid)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->first();

        if (!$enrollment) {
            throw (new ModelNotFoundException())->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        $targetDate = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
        $session = $this->getOrCreateSessionForGroup($group, $teacher, $targetDate);

        return $this->transaction(function () use ($teacher, $group, $session, $enrollment, $status, $notes) {
            /** @var GroupAttendanceRecord|null $record */
            $record = GroupAttendanceRecord::where('attendance_session_id', $session->id)
                ->where('group_enrollment_id', $enrollment->id)
                ->first();

            if ($status !== null) {
                $newStatus = AttendanceStatus::tryFrom($status) ?? AttendanceStatus::PRESENT;
            } elseif ($record && $record->status === AttendanceStatus::PRESENT) {
                $newStatus = AttendanceStatus::ABSENT;
            } else {
                $newStatus = AttendanceStatus::PRESENT;
            }

            if ($record) {
                $record->update([
                    'status'          => $newStatus,
                    'recorded_method' => AttendanceRecordMethod::MANUAL_TOGGLE,
                    'scanned_at'      => $newStatus === AttendanceStatus::PRESENT ? now() : null,
                    'recorded_by'     => $teacher->id,
                    'notes'           => $notes ?? $record->notes,
                ]);
            } else {
                $record = GroupAttendanceRecord::create([
                    'attendance_session_id' => $session->id,
                    'study_group_id'        => $group->id,
                    'group_enrollment_id'   => $enrollment->id,
                    'student_id'            => $enrollment->student_id,
                    'status'                => $newStatus,
                    'recorded_method'       => AttendanceRecordMethod::MANUAL_TOGGLE,
                    'scanned_at'            => $newStatus === AttendanceStatus::PRESENT ? now() : null,
                    'recorded_by'           => $teacher->id,
                    'notes'                 => $notes,
                ]);
            }

            $this->recalculateEnrollmentMetrics($enrollment, $group);
            $session->recalculateSummary();

            $this->logSecurityEvent('teacher.attendance.toggled', [
                'group_uuid'      => $group->uuid,
                'session_uuid'    => $session->uuid,
                'enrollment_uuid' => $enrollment->uuid,
                'new_status'      => $newStatus->value,
            ], 'info', $record);

            return $record->load('student', 'enrollment');
        });
    }

    /**
     * List attendance sessions history for a study group.
     */
    public function listSessions(User $teacher, string $groupUuid): Collection
    {
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        return GroupAttendanceSession::where('study_group_id', $group->id)
            ->withCount('records')
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Retrieve full session details with each enrolled student's attendance status.
     *
     * @return array{session: GroupAttendanceSession, group: StudyGroup, roster: array}
     */
    public function getSessionDetails(User $teacher, string $groupUuid, string $sessionUuid): array
    {
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        /** @var GroupAttendanceSession|null $session */
        $session = GroupAttendanceSession::where('study_group_id', $group->id)
            ->where('uuid', $sessionUuid)
            ->with(['records.student'])
            ->first();

        if (!$session) {
            throw (new ModelNotFoundException())->setModel(GroupAttendanceSession::class, [$sessionUuid]);
        }

        $recordsMap = $session->records->keyBy('group_enrollment_id');
        $enrolledStudents = $group->enrolledEnrollments()->with('student')->get();

        $roster = $enrolledStudents->map(function (GroupEnrollment $enrollment) use ($recordsMap) {
            $record = $recordsMap->get($enrollment->id);
            $status = $record ? $record->status->value : AttendanceStatus::ABSENT->value;

            return [
                'enrollmentId'   => $enrollment->uuid,
                'studentId'      => $enrollment->student_id,
                'studentCode'    => $enrollment->student?->student_code,
                'nameAr'         => $enrollment->student_name_override ?: ($enrollment->student?->name ?? 'طالب'),
                'phone'          => $enrollment->student_phone_override ?: ($enrollment->student?->phone ?? ''),
                'status'         => $status,
                'scannedAt'      => $record?->scanned_at?->toIso8601String(),
                'recordedMethod' => $record?->recorded_method?->value,
                'notes'          => $record?->notes,
            ];
        })->values()->all();

        return [
            'session' => $session,
            'group'   => $group,
            'roster'  => $roster,
        ];
    }

    /**
     * Find or create the attendance session for the given date.
     */
    public function getOrCreateSessionForGroup(StudyGroup $group, User $teacher, string $date): GroupAttendanceSession
    {
        $sessionDate = Carbon::parse($date)->toDateString();

        /** @var GroupAttendanceSession|null $session */
        $session = GroupAttendanceSession::where('study_group_id', $group->id)
            ->whereDate('session_date', $sessionDate)
            ->first();

        if (!$session) {
            $session = GroupAttendanceSession::create([
                'study_group_id'  => $group->id,
                'session_date'    => $sessionDate,
                'teacher_id'      => $teacher->id,
                'title_ar'        => 'حصة ' . Carbon::parse($sessionDate)->locale('ar')->isoFormat('dddd D MMMM Y'),
                'status'          => 'open',
                'total_enrolled'  => (int) $group->enrolledEnrollments()->count(),
                'attendance_rate' => 0.0,
            ]);
        }

        return $session;
    }

    /**
     * Resolve target enrolled student by Barcode, Passcode, signed QR Token, or Student Code.
     */
    protected function resolveEnrollmentByCode(StudyGroup $group, string $rawCode): ?GroupEnrollment
    {
        $trimmed = trim($rawCode);
        $clean = strtoupper($trimmed);
        if ($clean === '') {
            return null;
        }

        // 1. Direct Barcode Match (e.g. STD-001-0001-A1B2)
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->where('student_barcode', $clean)
            ->first();
        if ($enrollment) {
            return $enrollment;
        }

        // 2. Direct Passcode Match (e.g. 4821 or STU-TD-1)
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->where('student_passcode', $clean)
            ->first();
        if ($enrollment) {
            return $enrollment;
        }

        // 3. Signed QR Token verification: MOTAFAWWEQ:ATTEND:{groupId}:{enrollmentUuid}:{timestamp}:{hmac}
        if (str_starts_with($clean, 'MOTAFAWWEQ:ATTEND:')) {
            $parts = explode(':', $trimmed);
            if (count($parts) >= 6) {
                $tokenGroupId = (int) $parts[2];
                $tokenEnrollmentUuid = strtolower($parts[3]);
                $tokenTimestamp = (int) $parts[4];
                $tokenHmac = $parts[5];

                if ($tokenGroupId === $group->id && abs(time() - $tokenTimestamp) < 86400) {
                    $expectedHmac = hash_hmac('sha256', "{$tokenGroupId}:{$tokenEnrollmentUuid}:{$tokenTimestamp}", (string) config('app.key'));
                    if (hash_equals(strtolower($expectedHmac), strtolower($tokenHmac))) {
                        return GroupEnrollment::where('study_group_id', $group->id)
                            ->where('status', EnrollmentStatus::ENROLLED->value)
                            ->where('uuid', $tokenEnrollmentUuid)
                            ->first();
                    }
                }
            }
        }

        // 4. Structured QR Token: MOTAFAWWEQ:STUDENT:{code}
        if (str_starts_with($clean, 'MOTAFAWWEQ:STUDENT:')) {
            $subCode = trim(substr($clean, strlen('MOTAFAWWEQ:STUDENT:')));
            return $this->resolveEnrollmentByCode($group, $subCode);
        }

        // 5. Match by student_code on User model
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->whereHas('student', function ($sq) use ($clean) {
                $sq->where('student_code', $clean)
                    ->orWhere('phone', $clean);
            })
            ->first();
        if ($enrollment) {
            return $enrollment;
        }

        // 6. Match by enrollment UUID or student ID
        return GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->where(function ($q) use ($clean) {
                $q->where('uuid', $clean)
                    ->orWhere('student_id', is_numeric($clean) ? (int) $clean : 0);
            })
            ->first();
    }

    /**
     * Recalculate attended sessions, total group sessions, attendance rate, and streak for an enrollment.
     */
    protected function recalculateEnrollmentMetrics(GroupEnrollment $enrollment, StudyGroup $group): void
    {
        $totalSessionsCount = GroupAttendanceSession::where('study_group_id', $group->id)->count();

        $attendedCount = GroupAttendanceRecord::where('study_group_id', $group->id)
            ->where('group_enrollment_id', $enrollment->id)
            ->whereIn('status', [AttendanceStatus::PRESENT->value, AttendanceStatus::LATE->value])
            ->count();

        $rate = $totalSessionsCount > 0
            ? (int) round(($attendedCount / $totalSessionsCount) * 100)
            : 100;

        // Calculate consecutive streak days: recent sessions where student was present
        $recentRecords = GroupAttendanceRecord::join('group_attendance_sessions', 'group_attendance_sessions.id', '=', 'group_attendance_records.attendance_session_id')
            ->where('group_attendance_records.study_group_id', $group->id)
            ->where('group_attendance_records.group_enrollment_id', $enrollment->id)
            ->orderByDesc('group_attendance_sessions.session_date')
            ->select('group_attendance_records.status')
            ->limit(30)
            ->get();

        $streak = 0;
        foreach ($recentRecords as $r) {
            $statusVal = $r->status instanceof AttendanceStatus ? $r->status->value : (string) $r->status;
            if (in_array($statusVal, ['present', 'late'], true)) {
                $streak++;
            } else {
                break;
            }
        }

        $enrollment->update([
            'attended_sessions' => $attendedCount,
            'total_sessions'    => $totalSessionsCount,
            'attendance_rate'   => min(100, max(0, $rate)),
            'streak_days'       => max(1, $streak),
        ]);
    }
}
