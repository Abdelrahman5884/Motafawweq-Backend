<?php

namespace App\Services\V1\Student;

use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupEnrollment;
use App\Models\GroupScheduleSlot;
use App\Models\StudentScheduleNote;
use App\Models\StudentStudySession;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class StudentGroupScheduleService extends BaseService
{
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
            ->with(['group.scheduleSlots', 'group.teacher:id,name'])
            ->latest()
            ->get();

        return $enrollments->map(function (GroupEnrollment $enrollment) {
            $group = $enrollment->group;
            $status = $enrollment->status instanceof EnrollmentStatus
                ? $enrollment->status->value
                : (string) $enrollment->status;

            return [
                'enrollmentId' => $enrollment->uuid,
                'groupId' => $group?->uuid,
                'nameAr' => $group?->name_ar,
                'nameEn' => $group?->name_en ?? $group?->name_ar,
                'subjectAr' => $group?->subject_ar,
                'gradeAr' => $group?->grade_ar,
                'centerName' => $group?->center_name,
                'hallName' => $group?->hall_name,
                'scheduleAr' => $group?->schedule_ar,
                'priceEgp' => (float) ($group?->price_egp ?? 0),
                'joinCode' => $group?->join_code,
                'teacherName' => $group?->teacher?->name,
                'enrollmentStatus' => $status,
                'studentBarcode' => $status === EnrollmentStatus::ENROLLED->value ? $enrollment->student_barcode : null,
                'studentPasscode' => $status === EnrollmentStatus::ENROLLED->value ? $enrollment->student_passcode : null,
                'attendanceRate' => (int) $enrollment->attendance_rate,
                'attendedSessions' => (int) $enrollment->attended_sessions,
                'totalSessions' => (int) $enrollment->total_sessions,
                'scheduleSlots' => ($group?->scheduleSlots ?? collect())->map(fn (GroupScheduleSlot $s) => [
                    'id' => $s->uuid,
                    'day' => $s->day_of_week instanceof DayOfWeek ? $s->day_of_week->value : (string) $s->day_of_week,
                    'startTime' => $s->start_time,
                    'endTime' => $s->end_time,
                    'hall' => $s->hall_name ?: $group?->hall_name,
                ])->values()->all(),
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
     * Get the student's aggregated weekly timetable (enrolled group classes + personal study sessions).
     */
    public function getWeeklySchedule(User $student): array
    {
        $enrolledGroups = StudyGroup::query()
            ->where('status', 'active')
            ->whereHas('enrollments', function ($q) use ($student) {
                $q->where('student_id', $student->id)
                    ->whereIn('status', [EnrollmentStatus::ENROLLED, EnrollmentStatus::PENDING]);
            })
            ->with(['scheduleSlots', 'teacher:id,name'])
            ->get();

        $studySessions = StudentStudySession::forStudent($student->id)
            ->orderBy('start_time')
            ->get();

        $classSessions = [];
        foreach ($enrolledGroups as $group) {
            foreach ($group->scheduleSlots as $slot) {
                $day = $slot->day_of_week instanceof DayOfWeek ? $slot->day_of_week->value : (string) $slot->day_of_week;
                $classSessions[] = [
                    'id' => $slot->uuid,
                    'type' => 'group_class',
                    'groupId' => $group->uuid,
                    'groupName' => $group->name_ar,
                    'subject' => $group->subject_ar,
                    'teacherName' => $group->teacher?->name,
                    'centerName' => $group->center_name,
                    'hallName' => $slot->hall_name ?: $group->hall_name,
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
            'day' => $s->day_of_week instanceof DayOfWeek ? $s->day_of_week->value : (string) $s->day_of_week,
            'subject' => $s->subject_ar,
            'title' => $s->title_ar,
            'startTime' => $s->start_time,
            'endTime' => $s->end_time,
            'durationMinutes' => (int) $s->duration_minutes,
            'isCompleted' => (bool) $s->is_completed,
            'color' => $s->color,
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

        foreach ($notes as $n) {
            $d = strtolower((string) $n->day_of_week);
            if (! isset($notesMap[$d])) {
                $notesMap[$d] = [];
            }
            $notesMap[$d][] = [
                'id' => $n->uuid,
                'text' => $n->text,
                'time' => $n->time,
                'priority' => $n->priority,
                'isDone' => (bool) $n->is_done,
            ];
        }

        return [
            'classSessions' => $classSessions,
            'personalSessions' => $personalSessions,
            'scheduleNotes' => $notesMap,
        ];
    }

    /**
     * Create a daily schedule note for the student.
     */
    public function createScheduleNote(User $student, array $data): array
    {
        $day = strtolower(trim((string) ($data['day_of_week'] ?? $data['day'] ?? 'saturday')));
        $text = trim(strip_tags((string) ($data['text'] ?? '')));
        if ($text === '') {
            throw ValidationException::withMessages(['text' => ['يرجى إدخال نص الملحوظة.']]);
        }

        $note = StudentScheduleNote::create([
            'student_id' => $student->id,
            'day_of_week' => $day,
            'text' => $text,
            'time' => isset($data['time']) ? trim(strip_tags((string) $data['time'])) : null,
            'priority' => in_array($data['priority'] ?? 'medium', ['low', 'medium', 'high'], true) ? $data['priority'] : 'medium',
            'is_done' => false,
        ]);

        return [
            'id' => $note->uuid,
            'day' => $note->day_of_week,
            'text' => $note->text,
            'time' => $note->time,
            'priority' => $note->priority,
            'isDone' => (bool) $note->is_done,
        ];
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

        return [
            'id' => $note->uuid,
            'day' => $note->day_of_week,
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
     * Retrieve student's attendance records and a rotating HMAC-signed QR token (60s TTL).
     */
    public function getAttendanceAndBarcode(User $student): array
    {
        $enrollments = GroupEnrollment::query()
            ->where('student_id', $student->id)
            ->where('status', EnrollmentStatus::ENROLLED)
            ->with('group:id,uuid,name_ar,center_name')
            ->get();

        $records = GroupAttendanceRecord::query()
            ->where('student_id', $student->id)
            ->with(['session.group:id,uuid,name_ar'])
            ->latest('recorded_at')
            ->limit(60)
            ->get()
            ->map(fn (GroupAttendanceRecord $r) => [
                'id' => $r->uuid,
                'groupNameAr' => $r->session?->group?->name_ar,
                'sessionDate' => $r->session?->session_date?->format('Y-m-d'),
                'status' => $r->status?->value ?? (string) $r->status,
                'recordedAt' => $r->recorded_at?->toIso8601String(),
            ])->values()->all();

        $primaryBarcode = $enrollments->first()?->student_barcode ?? ($student->student_code ?: "STD-{$student->id}");
        $timestamp = now()->timestamp;
        $secret = (string) config('app.key', 'motafawweq-secret');
        $signature = substr(hash_hmac('sha256', "{$student->id}|{$primaryBarcode}|{$timestamp}", $secret), 0, 16);

        return [
            'studentCode' => $student->student_code,
            'primaryBarcode' => $primaryBarcode,
            'rotatingQrPayload' => "MOTAFAWWEQ:ATT:{$primaryBarcode}:{$timestamp}:{$signature}",
            'qrExpiresInSec' => 60,
            'records' => $records,
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
