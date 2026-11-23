<?php

namespace App\Services\V1\Student;

use App\Enums\AttendanceStatus;
use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Enums\JoinMethod;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupEnrollment;
use App\Models\GroupScheduleSlot;
use App\Models\StudentProfile;
use App\Models\StudentScheduleNote;
use App\Models\StudentStudySession;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class StudentGroupScheduleService extends BaseService
{
    private const DAY_LABELS = [
        'saturday' => ['ar' => 'السبت', 'en' => 'Saturday'],
        'sunday' => ['ar' => 'الأحد', 'en' => 'Sunday'],
        'monday' => ['ar' => 'الاثنين', 'en' => 'Monday'],
        'tuesday' => ['ar' => 'الثلاثاء', 'en' => 'Tuesday'],
        'wednesday' => ['ar' => 'الأربعاء', 'en' => 'Wednesday'],
        'thursday' => ['ar' => 'الخميس', 'en' => 'Thursday'],
        'friday' => ['ar' => 'الجمعة', 'en' => 'Friday'],
    ];

    public function __construct(
        protected StudentAccessService $accessService
    ) {}

    /**
     * List all study groups the student has enrolled in or requested to join.
     */
    public function listMyGroups(User $student): array
    {
        $enrollments = GroupEnrollment::query()
            ->where('student_id', $student->id)
            ->whereIn('status', [EnrollmentStatus::ENROLLED, EnrollmentStatus::PENDING])
            ->whereHas('group', fn ($q) => $q->where('status', 'active'))
            ->with([
                'group' => fn ($q) => $q->withCount('enrolledEnrollments'),
                'group.scheduleSlots',
                'group.teacher:id,name',
                'group.centerTeacher:id,name_ar',
                'group.course:id,uuid,title_ar,title_en',
            ])
            ->latest()
            ->get();

        return $enrollments->map(function (GroupEnrollment $enrollment) {
            $group = $enrollment->group;
            $status = $enrollment->status instanceof EnrollmentStatus
                ? $enrollment->status->value
                : (string) $enrollment->status;
            $isEnrolled = $status === EnrollmentStatus::ENROLLED->value;
            $joinMethodEnum = $enrollment->join_method instanceof JoinMethod
                ? $enrollment->join_method
                : JoinMethod::tryFrom((string) $enrollment->join_method);
            $joinMethodValue = $joinMethodEnum?->value ?? (string) $enrollment->join_method;
            $isTeacherInvite = $status === EnrollmentStatus::PENDING->value
                && $joinMethodValue === JoinMethod::TEACHER_INVITE->value;
            $resolvedTeacherName = $group?->centerTeacher?->name_ar
                ?: ($group?->teacher_name_override ?: ($group?->teacher?->name ?? ''));

            return [
                'enrollmentId' => $enrollment->uuid,
                'groupId' => $group?->uuid,
                'courseId' => $group?->course?->uuid,
                'nameAr' => $group?->name_ar,
                'nameEn' => $group?->name_en ?: $group?->name_ar,
                'subjectAr' => $group?->subject_ar,
                'subjectEn' => $group?->subject_en ?: $group?->subject_ar,
                'gradeAr' => $group?->grade_ar,
                'gradeEn' => $group?->grade_en ?: $group?->grade_ar,
                'centerName' => $group?->center_name,
                'hallName' => $group?->hall_name,
                'scheduleAr' => $group?->schedule_ar,
                'scheduleEn' => $group?->schedule_en ?: $group?->schedule_ar,
                'priceEgp' => (float) ($group?->price_egp ?? 0),
                'joinCode' => strtoupper(trim((string) ($group?->join_code ?? ''))),
                'maxStudents' => (int) ($group?->max_students ?? 50),
                'enrolledCount' => (int) ($group?->enrolled_enrollments_count ?? 0),
                'teacherName' => $resolvedTeacherName,
                'teacherNameAr' => $resolvedTeacherName,
                'teacherNameEn' => $resolvedTeacherName,
                'enrollmentStatus' => $status,
                'joinMethod' => $joinMethodValue,
                'joinMethodLabelAr' => $joinMethodEnum?->labelAr() ?? 'كود الانضمام',
                'isTeacherInvite' => $isTeacherInvite,
                'studentBarcode' => $isEnrolled ? $enrollment->student_barcode : null,
                'studentPasscode' => $isEnrolled ? $enrollment->student_passcode : null,
                'attendanceRate' => (int) $enrollment->attendance_rate,
                'attendedSessions' => (int) $enrollment->attended_sessions,
                'totalSessions' => (int) $enrollment->total_sessions,
                'enrolledAt' => $enrollment->enrolled_at?->toIso8601String(),
                'requestedAt' => ($enrollment->requested_at ?? $enrollment->created_at)?->toIso8601String(),
                'scheduleSlots' => ($group?->scheduleSlots ?? collect())->map(function (GroupScheduleSlot $s) use ($group) {
                    $dayKey = $s->day_of_week instanceof DayOfWeek ? $s->day_of_week->value : strtolower((string) $s->day_of_week);
                    $dayMeta = self::DAY_LABELS[$dayKey] ?? ['ar' => $dayKey, 'en' => ucfirst($dayKey)];

                    return [
                        'id' => $s->uuid,
                        'day' => $dayKey,
                        'dayAr' => $dayMeta['ar'],
                        'dayEn' => $dayMeta['en'],
                        'startTime' => $s->start_time,
                        'endTime' => $s->end_time,
                        'hall' => $s->hall_name ?: $group?->hall_name,
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Leave or cancel a pending join request for a study group.
     */
    public function leaveGroup(User $student, string $groupUuid): bool
    {
        /** @var StudyGroup|null $group */
        $group = StudyGroup::where('uuid', $groupUuid)->first();
        if (! $group) {
            throw (new ModelNotFoundException)->setModel(StudyGroup::class, [$groupUuid]);
        }

        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('student_id', $student->id)
            ->first();

        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$groupUuid]);
        }

        return $this->transaction(function () use ($student, $group, $enrollment) {
            $enrollment->update(['status' => EnrollmentStatus::REMOVED]);
            $enrollment->delete();

            $this->accessService->clearCache($student);

            $this->logSecurityEvent(
                'student.group.left',
                [
                    'group_uuid' => $group->uuid,
                    'enrollment_uuid' => $enrollment->uuid,
                ],
                'info',
                $enrollment
            );

            return true;
        });
    }

    /**
     * Get the student's aggregated weekly timetable (confirmed enrolled group classes + personal study sessions + notes + summary).
     */
    public function getWeeklySchedule(User $student): array
    {
        $enrolledGroups = StudyGroup::query()
            ->where('status', 'active')
            ->whereHas('enrollments', function ($q) use ($student) {
                $q->where('student_id', $student->id)
                    ->where('status', EnrollmentStatus::ENROLLED);
            })
            ->with([
                'scheduleSlots',
                'teacher:id,name',
                'enrollments' => fn ($q) => $q
                    ->where('student_id', $student->id)
                    ->where('status', EnrollmentStatus::ENROLLED)
                    ->select(['id', 'study_group_id', 'student_id', 'attendance_rate', 'status']),
            ])
            ->get();

        $studySessions = StudentStudySession::forStudent($student->id)
            ->orderBy('start_time')
            ->get();

        $classSessions = [];
        foreach ($enrolledGroups as $group) {
            $enrollment = $group->enrollments->first();
            foreach ($group->scheduleSlots as $slot) {
                $day = $slot->day_of_week instanceof DayOfWeek ? $slot->day_of_week->value : strtolower((string) $slot->day_of_week);
                $classSessions[] = [
                    'id' => $slot->uuid,
                    'type' => 'group_class',
                    'groupId' => $group->uuid,
                    'groupName' => $group->name_ar,
                    'groupNameEn' => $group->name_en ?: $group->name_ar,
                    'subject' => $group->subject_ar,
                    'subjectEn' => $group->subject_en ?: $group->subject_ar,
                    'teacherName' => $group->teacher_name_override ?: ($group->teacher?->name ?? ''),
                    'centerName' => $group->center_name,
                    'hallName' => $slot->hall_name ?: $group->hall_name,
                    'joinCode' => strtoupper(trim((string) ($group->join_code ?? ''))),
                    'attendanceRate' => (int) ($enrollment?->attendance_rate ?? 100),
                    'day' => $day,
                    'startTime' => $slot->start_time,
                    'endTime' => $slot->end_time,
                ];
            }
        }

        $personalSessions = $studySessions->map(fn (StudentStudySession $s) => [
            'id' => $s->uuid,
            'uuid' => $s->uuid,
            'type' => 'study_session',
            'day' => $s->day_of_week instanceof DayOfWeek ? $s->day_of_week->value : strtolower((string) $s->day_of_week),
            'subject' => $s->subject_ar,
            'title' => $s->title_ar,
            'startTime' => $s->start_time,
            'endTime' => $s->end_time,
            'durationMinutes' => (int) $s->duration_minutes,
            'isCompleted' => (bool) $s->is_completed,
            'color' => $s->color ?: '#1588C7',
            'notes' => $s->notes,
        ])->values()->all();

        $notesMap = [
            'saturday' => [],
            'sunday' => [],
            'monday' => [],
            'tuesday' => [],
            'wednesday' => [],
            'thursday' => [],
            'friday' => [],
        ];

        $notes = StudentScheduleNote::forStudent($student->id)
            ->orderBy('created_at')
            ->get();

        $completedNotes = 0;
        foreach ($notes as $n) {
            $d = strtolower((string) $n->day_of_week);
            if (! isset($notesMap[$d])) {
                $notesMap[$d] = [];
            }
            if ($n->is_done) {
                $completedNotes++;
            }
            $notesMap[$d][] = $this->formatScheduleNote($n);
        }

        $totalStudySessions = $studySessions->count();
        $completedStudySessions = $studySessions->where('is_completed', true)->count();
        $totalStudyMinutes = (int) $studySessions->sum('duration_minutes');
        $completionRate = $totalStudySessions > 0
            ? (int) round(($completedStudySessions / $totalStudySessions) * 100)
            : 0;

        return [
            'classSessions' => $classSessions,
            'personalSessions' => $personalSessions,
            'scheduleNotes' => $notesMap,
            'summary' => [
                'totalCenterSessions' => count($classSessions),
                'totalStudySessions' => $totalStudySessions,
                'completedStudySessions' => $completedStudySessions,
                'totalStudyMinutes' => $totalStudyMinutes,
                'completionRate' => $completionRate,
                'totalNotes' => $notes->count(),
                'completedNotes' => $completedNotes,
            ],
        ];
    }

    /**
     * Create a daily schedule note for the student.
     */
    public function createScheduleNote(User $student, array $data): array
    {
        $day = strtolower(trim((string) ($data['day_of_week'] ?? $data['day'] ?? 'saturday')));
        if (! in_array($day, DayOfWeek::values(), true)) {
            throw ValidationException::withMessages(['day_of_week' => ['اليوم المحدد غير صالح.']]);
        }

        $text = trim(strip_tags((string) ($data['text'] ?? '')));
        if ($text === '') {
            throw ValidationException::withMessages(['text' => ['يرجى إدخال نص الملحوظة.']]);
        }

        $rawTime = isset($data['time']) && trim((string) $data['time']) !== ''
            ? $this->normalizeTime(trim(strip_tags((string) $data['time'])))
            : null;

        $note = StudentScheduleNote::create([
            'student_id' => $student->id,
            'day_of_week' => $day,
            'text' => mb_substr($text, 0, 500),
            'time' => $rawTime,
            'priority' => in_array($data['priority'] ?? 'medium', ['low', 'medium', 'high'], true) ? $data['priority'] : 'medium',
            'is_done' => false,
        ]);

        return $this->formatScheduleNote($note);
    }

    /**
     * Update an existing daily schedule note (text, day, time, priority) with strict ownership check.
     */
    public function updateScheduleNote(User $student, string $uuid, array $data): array
    {
        /** @var StudentScheduleNote|null $note */
        $note = StudentScheduleNote::where('uuid', $uuid)->first();
        if (! $note) {
            throw (new ModelNotFoundException)->setModel(StudentScheduleNote::class, [$uuid]);
        }
        $note->assertOwnedByStudent($student);

        $previousDay = strtolower((string) $note->day_of_week);
        $day = strtolower(trim((string) ($data['day_of_week'] ?? $data['day'] ?? $previousDay)));
        if (! in_array($day, DayOfWeek::values(), true)) {
            throw ValidationException::withMessages(['day_of_week' => ['اليوم المحدد غير صالح.']]);
        }

        $text = array_key_exists('text', $data)
            ? trim(strip_tags((string) $data['text']))
            : $note->text;
        if ($text === '') {
            throw ValidationException::withMessages(['text' => ['يرجى إدخال نص الملحوظة.']]);
        }

        $time = $note->time;
        if (array_key_exists('time', $data)) {
            $clean = trim(strip_tags((string) ($data['time'] ?? '')));
            $time = $clean === '' ? null : $this->normalizeTime($clean);
        }

        $priority = isset($data['priority']) && in_array($data['priority'], ['low', 'medium', 'high'], true)
            ? $data['priority']
            : $note->priority;

        $note->update([
            'day_of_week' => $day,
            'text' => mb_substr($text, 0, 500),
            'time' => $time,
            'priority' => $priority,
        ]);

        return array_merge($this->formatScheduleNote($note->fresh()), [
            'previousDay' => $previousDay,
        ]);
    }

    /**
     * Toggle a daily schedule note completion status.
     */
    public function toggleScheduleNote(User $student, string $uuid): array
    {
        /** @var StudentScheduleNote|null $note */
        $note = StudentScheduleNote::where('uuid', $uuid)->first();
        if (! $note) {
            throw (new ModelNotFoundException)->setModel(StudentScheduleNote::class, [$uuid]);
        }
        $note->assertOwnedByStudent($student);
        $note->update(['is_done' => ! $note->is_done]);

        return $this->formatScheduleNote($note->fresh());
    }

    /**
     * Normalize a schedule note record for consistent JSON responses.
     */
    protected function formatScheduleNote(StudentScheduleNote $note): array
    {
        return [
            'id' => $note->uuid,
            'day' => strtolower((string) $note->day_of_week),
            'text' => $note->text,
            'time' => $note->time,
            'priority' => $note->priority ?: 'medium',
            'isDone' => (bool) $note->is_done,
        ];
    }

    /**
     * Delete a daily schedule note.
     */
    public function deleteScheduleNote(User $student, string $uuid): bool
    {
        /** @var StudentScheduleNote|null $note */
        $note = StudentScheduleNote::where('uuid', $uuid)->first();
        if (! $note) {
            throw (new ModelNotFoundException)->setModel(StudentScheduleNote::class, [$uuid]);
        }
        $note->assertOwnedByStudent($student);
        $note->delete();

        return true;
    }

    /**
     * Create a personal study session with strict overlap prevention against both group classes and other study sessions.
     */
    public function createStudySession(User $student, array $data): StudentStudySession
    {
        return $this->transaction(function () use ($student, $data) {
            $day = strtolower(trim((string) $data['day_of_week']));
            $startTime = $this->normalizeTime((string) $data['start_time']);
            $endTime = $this->normalizeTime((string) $data['end_time']);

            $durationMinutes = $this->assertNoStudySessionOverlap($student, $day, $startTime, $endTime);

            return StudentStudySession::create([
                'student_id' => $student->id,
                'day_of_week' => $day,
                'subject_ar' => trim(strip_tags((string) $data['subject_ar'])),
                'title_ar' => trim(strip_tags((string) $data['title_ar'])),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_minutes' => $durationMinutes,
                'is_completed' => false,
                'color' => $data['color'] ?? '#1588C7',
                'notes' => isset($data['notes']) ? trim(strip_tags((string) $data['notes'])) : null,
            ]);
        });
    }

    /**
     * Update an existing personal study session with ownership & overlap enforcement.
     */
    public function updateStudySession(User $student, string $uuid, array $data): StudentStudySession
    {
        /** @var StudentStudySession|null $session */
        $session = StudentStudySession::where('uuid', $uuid)->first();
        if (! $session) {
            throw (new ModelNotFoundException)->setModel(StudentStudySession::class, [$uuid]);
        }

        $session->assertOwnedByStudent($student);

        return $this->transaction(function () use ($student, $session, $data) {
            $day = strtolower(trim((string) ($data['day_of_week'] ?? ($session->day_of_week instanceof DayOfWeek ? $session->day_of_week->value : $session->day_of_week))));
            $startTime = $this->normalizeTime((string) ($data['start_time'] ?? $session->start_time));
            $endTime = $this->normalizeTime((string) ($data['end_time'] ?? $session->end_time));

            $durationMinutes = $this->assertNoStudySessionOverlap($student, $day, $startTime, $endTime, $session->id);

            $session->update([
                'day_of_week' => $day,
                'subject_ar' => isset($data['subject_ar']) ? trim(strip_tags((string) $data['subject_ar'])) : $session->subject_ar,
                'title_ar' => isset($data['title_ar']) ? trim(strip_tags((string) $data['title_ar'])) : $session->title_ar,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_minutes' => $durationMinutes,
                'color' => $data['color'] ?? $session->color,
                'notes' => array_key_exists('notes', $data) ? ($data['notes'] ? trim(strip_tags((string) $data['notes'])) : null) : $session->notes,
            ]);

            return $session->fresh();
        });
    }

    /**
     * Toggle completion of a personal study session.
     */
    public function toggleStudySession(User $student, string $uuid): StudentStudySession
    {
        /** @var StudentStudySession|null $session */
        $session = StudentStudySession::where('uuid', $uuid)->first();
        if (! $session) {
            throw (new ModelNotFoundException)->setModel(StudentStudySession::class, [$uuid]);
        }

        $session->assertOwnedByStudent($student);
        $session->update(['is_completed' => ! $session->is_completed]);

        return $session->fresh();
    }

    /**
     * Delete a personal study session.
     */
    public function deleteStudySession(User $student, string $uuid): bool
    {
        /** @var StudentStudySession|null $session */
        $session = StudentStudySession::where('uuid', $uuid)->first();
        if (! $session) {
            throw (new ModelNotFoundException)->setModel(StudentStudySession::class, [$uuid]);
        }

        $session->assertOwnedByStudent($student);
        $session->delete();

        return true;
    }

    /**
     * Retrieve student's digital & printable center ID pass data, attendance summary,
     * permanent scannable QR/Barcode codes, and signed live attendance token.
     */
    public function getAttendanceAndBarcode(User $student): array
    {
        $profile = StudentProfile::where('student_id', $student->id)->first();

        $enrollments = GroupEnrollment::query()
            ->where('student_id', $student->id)
            ->where('status', EnrollmentStatus::ENROLLED)
            ->whereHas('group', fn ($q) => $q->where('status', 'active'))
            ->with(['group.teacher:id,name'])
            ->latest('enrolled_at')
            ->get();

        $records = GroupAttendanceRecord::query()
            ->where('student_id', $student->id)
            ->with(['session.group:id,uuid,name_ar'])
            ->latest('recorded_at')
            ->limit(60)
            ->get();

        $presentCount = 0;
        $lateCount = 0;
        $absentCount = 0;
        foreach ($records as $r) {
            $statusVal = $r->status instanceof AttendanceStatus ? $r->status->value : (string) $r->status;
            if ($statusVal === AttendanceStatus::PRESENT->value) {
                $presentCount++;
            } elseif ($statusVal === AttendanceStatus::LATE->value) {
                $lateCount++;
            } elseif ($statusVal === AttendanceStatus::ABSENT->value) {
                $absentCount++;
            }
        }
        $totalRecords = $records->count();
        $overallRate = $totalRecords > 0
            ? (int) round((($presentCount + $lateCount) / $totalRecords) * 100)
            : ($enrollments->isNotEmpty() ? (int) round((float) $enrollments->avg('attendance_rate')) : 100);

        $canonicalStudentCode = strtoupper(trim((string) ($student->student_code ?: "STD-{$student->id}")));
        $firstEnrollment = $enrollments->first();
        $primaryBarcode = $firstEnrollment?->student_barcode
            ? strtoupper(trim((string) $firstEnrollment->student_barcode))
            : $canonicalStudentCode;
        $primaryPasscode = $firstEnrollment?->student_passcode;

        $timestamp = now()->timestamp;
        $secret = (string) config('app.key', 'motafawweq-secret');
        $signature = substr(hash_hmac('sha256', "{$student->id}|{$primaryBarcode}|{$timestamp}", $secret), 0, 16);

        return [
            'studentName' => $student->name,
            'studentPhone' => $student->phone,
            'studentAvatar' => $student->avatar,
            'gradeAr' => $profile?->grade_ar ?: 'الصف الثالث الثانوي',
            'trackAr' => $profile?->track_ar ?: '',
            'schoolName' => $profile?->school_name ?: '',
            'centerName' => $firstEnrollment?->group?->center_name ?: ($profile?->center_name ?: ''),
            'studentCode' => $canonicalStudentCode,
            'primaryBarcode' => $primaryBarcode,
            'primaryPasscode' => $primaryPasscode,
            // Permanent QR payload that works across all active groups even when printed on physical paper/plastic cards
            'permanentQrPayload' => "MOTAFAWWEQ:STUDENT:{$canonicalStudentCode}",
            // Live HMAC-signed QR token for on-screen gate check-in
            'rotatingQrPayload' => "MOTAFAWWEQ:ATT:{$student->id}:{$primaryBarcode}:{$timestamp}:{$signature}",
            'qrExpiresInSec' => 300,
            'issuedAt' => now()->format('Y-m-d'),
            'enrolledGroups' => $enrollments->map(fn (GroupEnrollment $e) => [
                'id' => $e->group?->uuid,
                'enrollmentId' => $e->uuid,
                'nameAr' => $e->group?->name_ar,
                'nameEn' => $e->group?->name_en ?: $e->group?->name_ar,
                'subjectAr' => $e->group?->subject_ar,
                'teacherName' => $e->group?->teacher_name_override ?: ($e->group?->teacher?->name ?? ''),
                'centerName' => $e->group?->center_name,
                'hallName' => $e->group?->hall_name,
                'scheduleAr' => $e->group?->schedule_ar,
                'joinCode' => strtoupper(trim((string) ($e->group?->join_code ?? ''))),
                'barcode' => $e->student_barcode ?: $canonicalStudentCode,
                'passcode' => $e->student_passcode,
                'attendanceRate' => (int) $e->attendance_rate,
                'attendedSessions' => (int) $e->attended_sessions,
                'totalSessions' => (int) $e->total_sessions,
            ])->values()->all(),
            'attendanceSummary' => [
                'totalRecords' => $totalRecords,
                'presentCount' => $presentCount,
                'lateCount' => $lateCount,
                'absentCount' => $absentCount,
                'overallRate' => min(100, max(0, $overallRate)),
            ],
            'records' => $records->map(fn (GroupAttendanceRecord $r) => [
                'id' => $r->uuid,
                'groupNameAr' => $r->session?->group?->name_ar,
                'sessionDate' => $r->session?->session_date?->format('Y-m-d'),
                'status' => $r->status instanceof AttendanceStatus ? $r->status->value : (string) $r->status,
                'recordedAt' => $r->recorded_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Validate that a personal study session does not overlap with any other study session
     * or any enrolled group class on the same day.
     */
    protected function assertNoStudySessionOverlap(
        User $student,
        string $day,
        string $startTime,
        string $endTime,
        ?int $excludeSessionId = null
    ): int {
        $startMin = $this->parseTimeToMinutes($startTime);
        $endMin = $this->parseTimeToMinutes($endTime);

        if ($startMin < 0 || $endMin < 0 || $endMin <= $startMin) {
            throw ValidationException::withMessages([
                'start_time' => ['وقت انتهاء جلسة المذاكرة يجب أن يكون بعد وقت البداية.'],
            ]);
        }

        // 1. Check against other personal study sessions on the same day
        $existingSessions = StudentStudySession::forStudent($student->id)
            ->where('day_of_week', $day)
            ->when($excludeSessionId !== null, fn ($q) => $q->where('id', '!=', $excludeSessionId))
            ->get();

        foreach ($existingSessions as $existing) {
            $exStart = $this->parseTimeToMinutes((string) $existing->start_time);
            $exEnd = $this->parseTimeToMinutes((string) $existing->end_time);
            if ($exStart >= 0 && $exEnd > $exStart && $startMin < $exEnd && $endMin > $exStart) {
                throw ValidationException::withMessages([
                    'start_time' => ["يتعارض هذا الموعد مع جلسة مذاكرة أخرى «{$existing->title_ar}» ({$existing->start_time} - {$existing->end_time})."],
                ]);
            }
        }

        // 2. Check against enrolled group class slots on the same day
        $enrolledGroupSlots = GroupScheduleSlot::query()
            ->where('day_of_week', $day)
            ->whereHas('studyGroup', function ($q) use ($student) {
                $q->where('status', 'active')
                    ->whereHas('enrollments', function ($eq) use ($student) {
                        $eq->where('student_id', $student->id)
                            ->where('status', EnrollmentStatus::ENROLLED);
                    });
            })
            ->with('studyGroup:id,name_ar')
            ->get();

        foreach ($enrolledGroupSlots as $slot) {
            $slotStart = $this->parseTimeToMinutes((string) $slot->start_time);
            $slotEnd = $this->parseTimeToMinutes((string) $slot->end_time);
            if ($slotStart >= 0 && $slotEnd > $slotStart && $startMin < $slotEnd && $endMin > $slotStart) {
                $groupName = $slot->studyGroup?->name_ar ?? 'حصة مجموعة';
                throw ValidationException::withMessages([
                    'start_time' => ["يتعارض هذا الموعد مع حصة مجموعة «{$groupName}» ({$slot->start_time} - {$slot->end_time})."],
                ]);
            }
        }

        return $endMin - $startMin;
    }

    protected function normalizeTime(string $time): string
    {
        $parts = explode(':', trim($time));
        if (count($parts) !== 2) {
            return trim($time);
        }

        return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
    }

    protected function parseTimeToMinutes(string $time): int
    {
        $parts = explode(':', trim($time));
        if (count($parts) !== 2) {
            return -1;
        }

        $h = (int) $parts[0];
        $m = (int) $parts[1];
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return -1;
        }

        return ($h * 60) + $m;
    }
}
