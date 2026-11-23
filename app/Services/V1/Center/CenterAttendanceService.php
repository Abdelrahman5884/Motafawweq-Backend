<?php

namespace App\Services\V1\Center;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Models\CenterAbsenceAlert;
use App\Models\CenterProfile;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\GroupScheduleSlot;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class CenterAttendanceService extends BaseService
{
    public function __construct(
        protected CenterGroupService $groupService,
        protected CenterBranchService $branchService,
        protected CenterScheduleService $scheduleService
    ) {}

    /**
     * Scan a student's QR/Barcode/Passcode/Phone inside a specific center study group.
     *
     * @return array<string, mixed>
     */
    public function scanInGroup(
        User $center,
        string $groupUuid,
        string $rawCode,
        string $method = 'qr_scan',
        ?string $date = null,
        ?string $notes = null
    ): array {
        $group = $this->groupService->findOwnedGroup($center, $groupUuid);
        $enrollment = $this->resolveEnrollmentInGroup($group, $rawCode);

        if (! $enrollment) {
            throw ValidationException::withMessages([
                'code' => [__('messages.attendance_student_not_found')],
            ]);
        }

        $targetDate = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $this->recordAttendance($center, $group, $enrollment, $targetDate, $method, null, $notes);
    }

    /**
     * Manually toggle a student's attendance status (present / absent / late / excused) in a center group.
     *
     * @return array<string, mixed>
     */
    public function toggleInGroup(
        User $center,
        string $groupUuid,
        string $enrollmentUuid,
        ?string $status = null,
        ?string $date = null,
        ?string $notes = null
    ): array {
        $group = $this->groupService->findOwnedGroup($center, $groupUuid);

        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->where('uuid', $enrollmentUuid)
            ->with('student:id,name,phone,student_code,avatar')
            ->first();

        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        $targetDate = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();
        $session = $this->getOrCreateSession($center, $group, $targetDate);

        return $this->transaction(function () use ($center, $group, $session, $enrollment, $status, $notes) {
            /** @var GroupAttendanceRecord|null $existing */
            $existing = GroupAttendanceRecord::where('attendance_session_id', $session->id)
                ->where('group_enrollment_id', $enrollment->id)
                ->first();

            if ($status !== null) {
                $newStatus = AttendanceStatus::tryFrom($status) ?? AttendanceStatus::PRESENT;
            } elseif ($existing && in_array($existing->status, [AttendanceStatus::PRESENT, AttendanceStatus::LATE], true)) {
                $newStatus = AttendanceStatus::ABSENT;
            } else {
                $newStatus = AttendanceStatus::PRESENT;
            }

            $isPresent = in_array($newStatus, [AttendanceStatus::PRESENT, AttendanceStatus::LATE], true);

            $record = GroupAttendanceRecord::updateOrCreate(
                [
                    'attendance_session_id' => $session->id,
                    'group_enrollment_id' => $enrollment->id,
                ],
                [
                    'study_group_id' => $group->id,
                    'student_id' => $enrollment->student_id,
                    'status' => $newStatus,
                    'recorded_method' => AttendanceRecordMethod::MANUAL_TOGGLE,
                    'scanned_at' => $isPresent ? now() : null,
                    'recorded_by' => $center->id,
                    'notes' => $notes ?? $existing?->notes,
                ]
            );

            $this->recalculateEnrollmentMetrics($enrollment, $group);
            $session->recalculateSummary();

            $this->logSecurityEvent('center.attendance.toggled', [
                'group_uuid' => $group->uuid,
                'enrollment_uuid' => $enrollment->uuid,
                'new_status' => $newStatus->value,
            ], 'info', $record);

            return [
                'recordId' => $record->uuid,
                'enrollmentId' => $enrollment->uuid,
                'studentNameAr' => $enrollment->student_name_override ?: ($enrollment->student?->name ?? 'طالب'),
                'status' => $newStatus->value,
                'scannedAt' => $record->scanned_at?->toIso8601String(),
                'sessionSummary' => [
                    'sessionId' => $session->uuid,
                    'presentCount' => (int) $session->present_count,
                    'absentCount' => (int) $session->absent_count,
                    'lateCount' => (int) $session->late_count,
                    'attendanceRate' => (float) $session->attendance_rate,
                ],
            ];
        });
    }

    /**
     * SMART GATE QR AUTO-MATCHER:
     * Identifies the student across all active groups in this center, automatically matches the current
     * (or simulated) day & time slot, checks late grace period, and registers attendance immediately.
     *
     * @return array<string, mixed>
     */
    public function smartGateScan(
        User $center,
        string $rawPayload,
        ?string $simulatedDay = null,
        ?string $simulatedTime = null
    ): array {
        $centerGroups = StudyGroup::where('center_id', $center->id)
            ->where('status', 'active')
            ->with(['scheduleSlots.centerRoom:id,uuid,name_ar', 'centerBranch:id,uuid,name_ar', 'centerTeacher:id,uuid,name_ar'])
            ->get();

        if ($centerGroups->isEmpty()) {
            throw ValidationException::withMessages([
                'code' => [__('messages.center.no_active_groups_for_scan')],
            ]);
        }

        // Find all enrollments of this student across this center's active groups
        $matchedEnrollments = [];
        foreach ($centerGroups as $grp) {
            $enr = $this->resolveEnrollmentInGroup($grp, $rawPayload);
            if ($enr) {
                $matchedEnrollments[] = ['group' => $grp, 'enrollment' => $enr];
            }
        }

        if (empty($matchedEnrollments)) {
            throw ValidationException::withMessages([
                'code' => [__('messages.center.smart_scan_student_not_enrolled')],
            ]);
        }

        $dayKey = strtolower(trim((string) ($simulatedDay ?: now()->format('l'))));
        if (! in_array($dayKey, DayOfWeek::values(), true)) {
            $dayKey = strtolower(now()->format('l'));
        }

        $currentTime = $simulatedTime
            ? $this->scheduleService->normalizeTime($simulatedTime)
            : now()->format('H:i');
        $currentMin = $this->scheduleService->parseTimeToMinutes($currentTime);

        // Select best matching (group, slot) for the current day & time window
        $bestMatch = null;
        $bestSlot = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($matchedEnrollments as $candidate) {
            /** @var StudyGroup $grp */
            $grp = $candidate['group'];
            foreach ($grp->scheduleSlots as $slot) {
                $slotDay = $slot->day_of_week instanceof DayOfWeek ? $slot->day_of_week->value : strtolower((string) $slot->day_of_week);
                if ($slotDay !== $dayKey) {
                    continue;
                }

                $sStart = $this->scheduleService->parseTimeToMinutes((string) $slot->start_time);
                $sEnd = $this->scheduleService->parseTimeToMinutes((string) $slot->end_time);
                if ($sStart < 0 || $sEnd < 0) {
                    continue;
                }

                // Prefer slots within [start - 60min, end + 30min], otherwise closest same-day slot
                $distance = abs($currentMin - $sStart);
                $inWindow = ($currentMin >= ($sStart - 60)) && ($currentMin <= ($sEnd + 30));
                $weightedDistance = $inWindow ? $distance : ($distance + 10000);

                if ($weightedDistance < $bestDistance) {
                    $bestDistance = $weightedDistance;
                    $bestMatch = $candidate;
                    $bestSlot = $slot;
                }
            }
        }

        // Fallback to student's primary enrolled group in this center if no slot matched today
        if (! $bestMatch) {
            $bestMatch = $matchedEnrollments[0];
            $bestSlot = $bestMatch['group']->scheduleSlots->first();
        }

        /** @var StudyGroup $matchedGroup */
        $matchedGroup = $bestMatch['group'];
        /** @var GroupEnrollment $matchedEnrollment */
        $matchedEnrollment = $bestMatch['enrollment'];

        // Determine if student arrived past the center's late_margin_minutes policy
        $statusOverride = AttendanceStatus::PRESENT;
        if ($bestSlot) {
            $lateMargin = (int) (CenterProfile::where('user_id', $center->id)->value('late_margin_minutes') ?? 15);
            $slotStartMin = $this->scheduleService->parseTimeToMinutes((string) $bestSlot->start_time);
            if ($slotStartMin >= 0 && $currentMin > ($slotStartMin + $lateMargin)) {
                $statusOverride = AttendanceStatus::LATE;
            }
        }

        $result = $this->recordAttendance(
            $center,
            $matchedGroup,
            $matchedEnrollment,
            now()->toDateString(),
            AttendanceRecordMethod::QR_SCAN->value,
            $bestSlot,
            null,
            $statusOverride
        );

        $result['matchedSlot'] = $bestSlot ? [
            'id' => $bestSlot->uuid,
            'day' => $bestSlot->day_of_week instanceof DayOfWeek ? $bestSlot->day_of_week->value : (string) $bestSlot->day_of_week,
            'startTime' => $bestSlot->start_time,
            'endTime' => $bestSlot->end_time,
            'hall' => $bestSlot->centerRoom?->name_ar ?: ($bestSlot->hall_name ?: $matchedGroup->hall_name),
        ] : null;

        return $result;
    }

    /**
     * List live attendance records across the center with filters + today's KPIs + absent list.
     *
     * @return array<string, mixed>
     */
    public function listAttendanceRecords(User $center, array $filters = []): array
    {
        $targetDate = ! empty($filters['date']) ? Carbon::parse((string) $filters['date'])->toDateString() : now()->toDateString();

        $groupsQuery = StudyGroup::where('center_id', $center->id)->where('status', 'active');
        if (! empty($filters['branch_id']) && $filters['branch_id'] !== 'all') {
            $branch = $this->branchService->findOwnedBranch($center, (string) $filters['branch_id']);
            $groupsQuery->where('center_branch_id', $branch->id);
        }
        if (! empty($filters['group_id']) && $filters['group_id'] !== 'all') {
            $grp = $this->groupService->findOwnedGroup($center, (string) $filters['group_id']);
            $groupsQuery->where('id', $grp->id);
        }

        $groupIds = $groupsQuery->pluck('id')->all();

        $recordsQuery = GroupAttendanceRecord::query()
            ->whereIn('study_group_id', empty($groupIds) ? [0] : $groupIds)
            ->with([
                'student:id,name,phone,student_code',
                'enrollment:id,uuid,student_name_override,student_phone_override,parent_phone,payment_status,due_amount_egp',
                'group:id,uuid,name_ar,subject_ar,hall_name,center_name,teacher_name_override,center_teacher_id',
                'group.centerTeacher:id,name_ar',
                'session:id,uuid,session_date',
            ])
            ->orderByDesc('scanned_at')
            ->orderByDesc('id');

        if (! empty($filters['date'])) {
            $recordsQuery->whereHas('session', fn ($q) => $q->whereDate('session_date', $targetDate));
        }

        if (! empty($filters['status'])) {
            $recordsQuery->where('status', trim((string) $filters['status']));
        }

        if (! empty($filters['search'])) {
            $q = trim((string) $filters['search']);
            $recordsQuery->where(function ($sub) use ($q) {
                $sub->whereHas('student', fn ($sq) => $sq->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
                    ->orWhereHas('enrollment', fn ($eq) => $eq->where('student_name_override', 'like', "%{$q}%")->orWhere('student_phone_override', 'like', "%{$q}%"))
                    ->orWhereHas('group', fn ($gq) => $gq->where('name_ar', 'like', "%{$q}%"));
            });
        }

        $records = $recordsQuery->limit(200)->get();

        $formattedRecords = $records->map(function (GroupAttendanceRecord $r) {
            $statusVal = $r->status instanceof AttendanceStatus ? $r->status->value : (string) $r->status;
            $payStatus = $r->enrollment?->payment_status ?: 'paid';
            $dueAmt = (float) ($r->enrollment?->due_amount_egp ?? 0);

            return [
                'id' => $r->uuid,
                'enrollmentId' => $r->enrollment?->uuid,
                'studentNameAr' => $r->enrollment?->student_name_override ?: ($r->student?->name ?? 'طالب'),
                'studentPhone' => $r->enrollment?->student_phone_override ?: ($r->student?->phone ?? ''),
                'groupId' => $r->group?->uuid,
                'groupNameAr' => $r->group?->name_ar ?? '',
                'subjectAr' => $r->group?->subject_ar ?? '',
                'teacherNameAr' => $r->group?->centerTeacher?->name_ar ?: ($r->group?->teacher_name_override ?? ''),
                'hallName' => $r->group?->hall_name ?? '',
                'branchNameAr' => $r->group?->center_name ?? '',
                'date' => $r->session?->session_date?->toDateString() ?? $r->created_at?->toDateString(),
                'timestamp' => ($r->scanned_at ?? $r->created_at)?->format('Y-m-d H:i'),
                'status' => $statusVal,
                'statusAr' => match ($statusVal) {
                    'present' => 'حاضر',
                    'late' => 'متأخر',
                    'excused' => 'غياب بعذر',
                    default => 'غائب',
                },
                'method' => $r->recorded_method instanceof AttendanceRecordMethod ? $r->recorded_method->value : (string) $r->recorded_method,
                'paymentStatus' => $payStatus,
                'paymentStatusAr' => $payStatus === 'paid' ? 'مسدد بالكامل' : "متبقي {$dueAmt} ج.م",
            ];
        })->values()->all();

        $presentTotal = collect($formattedRecords)->whereIn('status', ['present', 'late'])->count();
        $absentTotal = collect($formattedRecords)->where('status', 'absent')->count();
        $totalCount = count($formattedRecords);
        $complianceRate = $totalCount > 0 ? round(($presentTotal / $totalCount) * 100, 1) : 0.0;

        return [
            'summary' => [
                'date' => $targetDate,
                'scannedPresentCount' => $presentTotal,
                'absentCount' => $absentTotal,
                'complianceRate' => $complianceRate,
                'totalRecords' => $totalCount,
            ],
            'records' => $formattedRecords,
        ];
    }

    /**
     * List attendance sessions history for a specific center group.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listGroupSessions(User $center, string $groupUuid): array
    {
        $group = $this->groupService->findOwnedGroup($center, $groupUuid);

        return GroupAttendanceSession::where('study_group_id', $group->id)
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (GroupAttendanceSession $s) => [
                'id' => $s->uuid,
                'sessionDate' => $s->session_date?->toDateString(),
                'titleAr' => $s->title_ar,
                'status' => $s->status,
                'totalEnrolled' => (int) $s->total_enrolled,
                'presentCount' => (int) $s->present_count,
                'absentCount' => (int) $s->absent_count,
                'lateCount' => (int) $s->late_count,
                'attendanceRate' => (float) $s->attendance_rate,
            ])
            ->values()
            ->all();
    }

    /**
     * Retrieve a single attendance session's full student breakdown.
     *
     * @return array<string, mixed>
     */
    public function getSessionDetails(User $center, string $groupUuid, string $sessionUuid): array
    {
        $group = $this->groupService->findOwnedGroup($center, $groupUuid);

        /** @var GroupAttendanceSession|null $session */
        $session = GroupAttendanceSession::where('study_group_id', $group->id)
            ->where('uuid', $sessionUuid)
            ->with('records.student:id,name,phone,student_code')
            ->first();

        if (! $session) {
            throw (new ModelNotFoundException)->setModel(GroupAttendanceSession::class, [$sessionUuid]);
        }

        $recordsMap = $session->records->keyBy('group_enrollment_id');
        $enrolled = $group->enrolledEnrollments()->with('student:id,name,phone,student_code')->get();

        $roster = $enrolled->map(function (GroupEnrollment $e) use ($recordsMap) {
            $rec = $recordsMap->get($e->id);
            $statusVal = $rec
                ? ($rec->status instanceof AttendanceStatus ? $rec->status->value : (string) $rec->status)
                : AttendanceStatus::ABSENT->value;

            return [
                'enrollmentId' => $e->uuid,
                'studentId' => $e->student_id,
                'studentCode' => $e->student?->student_code,
                'nameAr' => $e->student_name_override ?: ($e->student?->name ?? 'طالب'),
                'phone' => $e->student_phone_override ?: ($e->student?->phone ?? ''),
                'parentPhone' => $e->parent_phone ?? '',
                'status' => $statusVal,
                'scannedAt' => $rec?->scanned_at?->toIso8601String(),
                'recordedMethod' => $rec?->recorded_method?->value,
                'notes' => $rec?->notes,
            ];
        })->values()->all();

        return [
            'session' => [
                'id' => $session->uuid,
                'sessionDate' => $session->session_date?->toDateString(),
                'titleAr' => $session->title_ar,
                'totalEnrolled' => (int) $session->total_enrolled,
                'presentCount' => (int) $session->present_count,
                'absentCount' => (int) $session->absent_count,
                'lateCount' => (int) $session->late_count,
                'attendanceRate' => (float) $session->attendance_rate,
            ],
            'roster' => $roster,
        ];
    }

    /**
     * Dispatch and record an absence alert notification to the student's guardian.
     *
     * @return array<string, mixed>
     */
    public function sendAbsenceAlert(User $center, array $data): array
    {
        $group = $this->groupService->findOwnedGroup($center, (string) $data['group_id']);

        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('uuid', (string) $data['enrollment_id'])
            ->with('student:id,name,phone')
            ->first();

        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [(string) $data['enrollment_id']]);
        }

        $sessionDate = ! empty($data['session_date'])
            ? Carbon::parse((string) $data['session_date'])->toDateString()
            : now()->toDateString();

        $channel = in_array($data['channel'] ?? 'whatsapp', ['whatsapp', 'sms', 'in_app'], true)
            ? (string) ($data['channel'] ?? 'whatsapp')
            : 'whatsapp';

        $studentName = $enrollment->student_name_override ?: ($enrollment->student?->name ?? 'الطالب');
        $parentPhone = trim(strip_tags((string) ($data['parent_phone'] ?? $enrollment->parent_phone ?? $enrollment->student_phone_override ?? '')));
        $defaultMessage = "ولي أمر الطالب «{$studentName}» المحترم، نحيطكم علماً بتغيب الطالب عن حصة «{$group->name_ar}» بتاريخ {$sessionDate}.";
        $message = trim(strip_tags((string) ($data['message_ar'] ?? $defaultMessage)));

        $alert = $this->transaction(function () use ($center, $group, $enrollment, $sessionDate, $parentPhone, $channel, $message) {
            /** @var CenterAbsenceAlert $created */
            $created = CenterAbsenceAlert::create([
                'center_id' => $center->id,
                'study_group_id' => $group->id,
                'group_enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'session_date' => $sessionDate,
                'parent_phone' => $parentPhone,
                'channel' => $channel,
                'message_ar' => $message,
                'status' => 'sent',
                'sent_by' => $center->id,
                'sent_at' => now(),
            ]);

            $this->logSecurityEvent('center.attendance.absence_alert_sent', [
                'alert_uuid' => $created->uuid,
                'group_uuid' => $group->uuid,
                'enrollment_uuid' => $enrollment->uuid,
                'channel' => $channel,
            ], 'info', $created);

            return $created;
        });

        return [
            'id' => $alert->uuid,
            'groupId' => $group->uuid,
            'enrollmentId' => $enrollment->uuid,
            'studentNameAr' => $studentName,
            'parentPhone' => $alert->parent_phone,
            'channel' => $alert->channel,
            'messageAr' => $alert->message_ar,
            'status' => $alert->status,
            'sentAt' => $alert->sent_at?->toIso8601String(),
        ];
    }

    /**
     * Export center attendance register as formula-safe UTF-8 CSV.
     */
    public function exportAttendanceCsv(User $center, array $filters = []): string
    {
        $data = $this->listAttendanceRecords($center, $filters);
        $headers = ['اسم الطالب', 'الهاتف', 'المجموعة', 'المادة', 'المدرس', 'القاعة', 'التوقيت', 'الحالة', 'حالة السداد'];

        $escape = function (mixed $val): string {
            $s = (string) ($val ?? '');
            if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
                $s = "'".$s;
            }

            return '"'.str_replace('"', '""', $s).'"';
        };

        $lines = [implode(',', array_map($escape, $headers))];
        foreach ($data['records'] as $r) {
            $lines[] = implode(',', array_map($escape, [
                $r['studentNameAr'],
                $r['studentPhone'],
                $r['groupNameAr'],
                $r['subjectAr'],
                $r['teacherNameAr'],
                $r['hallName'],
                $r['timestamp'],
                $r['statusAr'],
                $r['paymentStatusAr'],
            ]));
        }

        return "\xEF\xBB\xBF".implode("\n", $lines);
    }

    /**
     * Core atomic attendance writer shared by Group Scan and Smart Gate Scan.
     *
     * @return array<string, mixed>
     */
    protected function recordAttendance(
        User $center,
        StudyGroup $group,
        GroupEnrollment $enrollment,
        string $targetDate,
        string $method,
        ?GroupScheduleSlot $slot = null,
        ?string $notes = null,
        AttendanceStatus $status = AttendanceStatus::PRESENT
    ): array {
        $session = $this->getOrCreateSession($center, $group, $targetDate, $slot);

        return $this->transaction(function () use ($center, $group, $session, $enrollment, $method, $notes, $status) {
            /** @var GroupAttendanceRecord|null $existing */
            $existing = GroupAttendanceRecord::where('attendance_session_id', $session->id)
                ->where('group_enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->first();

            $alreadyRecorded = false;
            $methodEnum = AttendanceRecordMethod::tryFrom($method) ?? AttendanceRecordMethod::QR_SCAN;

            if ($existing && in_array($existing->status, [AttendanceStatus::PRESENT, AttendanceStatus::LATE], true)) {
                $alreadyRecorded = true;
                $record = $existing;
            } else {
                $record = GroupAttendanceRecord::updateOrCreate(
                    [
                        'attendance_session_id' => $session->id,
                        'group_enrollment_id' => $enrollment->id,
                    ],
                    [
                        'study_group_id' => $group->id,
                        'student_id' => $enrollment->student_id,
                        'status' => $status,
                        'recorded_method' => $methodEnum,
                        'scanned_at' => now(),
                        'recorded_by' => $center->id,
                        'notes' => $notes ?? $existing?->notes,
                    ]
                );

                $this->recalculateEnrollmentMetrics($enrollment, $group);
                $session->recalculateSummary();
            }

            $studentName = $enrollment->student_name_override ?: ($enrollment->student?->name ?? 'طالب');

            $this->logSecurityEvent('center.attendance.scanned', [
                'group_uuid' => $group->uuid,
                'session_uuid' => $session->uuid,
                'enrollment_uuid' => $enrollment->uuid,
                'status' => $record->status instanceof AttendanceStatus ? $record->status->value : (string) $record->status,
                'already_recorded' => $alreadyRecorded,
            ], 'info', $record);

            $payStatus = $enrollment->payment_status ?: 'paid';
            $dueAmt = (float) ($enrollment->due_amount_egp ?? 0);

            return [
                'recordId' => $record->uuid,
                'sessionId' => $session->uuid,
                'enrollmentId' => $enrollment->uuid,
                'alreadyRecorded' => $alreadyRecorded,
                'isDuplicateNotice' => $alreadyRecorded,
                'status' => $record->status instanceof AttendanceStatus ? $record->status->value : (string) $record->status,
                'scannedAt' => $record->scanned_at?->toIso8601String(),
                'student' => [
                    'id' => $enrollment->student_id,
                    'enrollmentId' => $enrollment->uuid,
                    'nameAr' => $studentName,
                    'phone' => $enrollment->student_phone_override ?: ($enrollment->student?->phone ?? ''),
                    'studentCode' => $enrollment->student?->student_code,
                    'avatar' => $enrollment->student?->avatar ?: null,
                    'barcode' => $enrollment->student_barcode,
                    'paymentStatus' => $payStatus,
                    'paymentStatusAr' => $payStatus === 'paid' ? 'مسدد بالكامل' : "متبقي {$dueAmt} ج.م",
                    'dueAmountEgp' => $dueAmt,
                ],
                'group' => [
                    'id' => $group->uuid,
                    'nameAr' => $group->name_ar,
                    'subjectAr' => $group->subject_ar,
                    'teacherNameAr' => $group->centerTeacher?->name_ar ?: ($group->teacher_name_override ?? ''),
                    'hallName' => $group->hall_name,
                    'branchNameAr' => $group->center_name,
                ],
                'messageAr' => $alreadyRecorded
                    ? "تم تسجيل حضور الطالب «{$studentName}» مسبقاً في هذه الحصة."
                    : "تم تسجيل حضور «{$studentName}» بنجاح في «{$group->name_ar}» ({$group->hall_name}).",
            ];
        });
    }

    protected function getOrCreateSession(
        User $center,
        StudyGroup $group,
        string $date,
        ?GroupScheduleSlot $slot = null
    ): GroupAttendanceSession {
        $sessionDate = Carbon::parse($date)->toDateString();

        /** @var GroupAttendanceSession|null $session */
        $session = GroupAttendanceSession::where('study_group_id', $group->id)
            ->whereDate('session_date', $sessionDate)
            ->first();

        if (! $session) {
            $session = GroupAttendanceSession::create([
                'study_group_id' => $group->id,
                'teacher_id' => $center->id,
                'center_id' => $center->id,
                'schedule_slot_id' => $slot?->id,
                'center_room_id' => $slot?->center_room_id ?? $group->center_room_id,
                'session_date' => $sessionDate,
                'title_ar' => 'حصة '.Carbon::parse($sessionDate)->locale('ar')->isoFormat('dddd D MMMM Y'),
                'status' => 'open',
                'total_enrolled' => (int) $group->enrolledEnrollments()->count(),
                'attendance_rate' => 0.0,
            ]);
        }

        return $session;
    }

    /**
     * Resolve an enrolled student in a center group from Barcode, Passcode, Signed HMAC QR, JSON payload, Student Code, or Phone.
     */
    protected function resolveEnrollmentInGroup(StudyGroup $group, string $rawCode): ?GroupEnrollment
    {
        $trimmed = trim($rawCode);
        if ($trimmed === '') {
            return null;
        }

        // JSON QR payload support
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                foreach (['barcode', 'passcode', 'studentCode', 'phone', 'id'] as $key) {
                    if (! empty($decoded[$key]) && is_scalar($decoded[$key])) {
                        $hit = $this->resolveEnrollmentInGroup($group, (string) $decoded[$key]);
                        if ($hit) {
                            return $hit;
                        }
                    }
                }
            }
        }

        $clean = strtoupper($trimmed);

        // 1. Direct Barcode match
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->where('student_barcode', $clean)
            ->with('student:id,name,phone,student_code,avatar')
            ->first();
        if ($enrollment) {
            return $enrollment;
        }

        // 2. Direct Passcode match
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->where('student_passcode', $clean)
            ->with('student:id,name,phone,student_code,avatar')
            ->first();
        if ($enrollment) {
            return $enrollment;
        }

        // 3. HMAC-signed group attendance QR: MOTAFAWWEQ:ATTEND:{groupId}:{enrollmentUuid}:{timestamp}:{hmac}
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
                            ->with('student:id,name,phone,student_code,avatar')
                            ->first();
                    }
                }
            }

            return null;
        }

        // 4. HMAC-signed Student Center Pass QR: MOTAFAWWEQ:ATT:{studentId}:{barcode}:{timestamp}:{hmac16}
        if (str_starts_with($clean, 'MOTAFAWWEQ:ATT:')) {
            $parts = explode(':', $trimmed);
            $secret = (string) config('app.key', 'motafawweq-secret');

            if (count($parts) === 6 && is_numeric($parts[2])) {
                $tokenStudentId = (int) $parts[2];
                $tokenBarcode = strtoupper(trim($parts[3]));
                $tokenTimestamp = (int) $parts[4];
                $tokenHmac = strtolower(trim($parts[5]));

                if (abs(time() - $tokenTimestamp) < 86400) {
                    $expectedHmac = strtolower(substr(hash_hmac('sha256', "{$tokenStudentId}|{$tokenBarcode}|{$tokenTimestamp}", $secret), 0, 16));
                    if (hash_equals($expectedHmac, $tokenHmac)) {
                        return GroupEnrollment::where('study_group_id', $group->id)
                            ->where('status', EnrollmentStatus::ENROLLED->value)
                            ->where('student_id', $tokenStudentId)
                            ->with('student:id,name,phone,student_code,avatar')
                            ->first();
                    }
                }
            }

            return null;
        }

        // 5. Permanent Student Card QR: MOTAFAWWEQ:STUDENT:{code} or multi-segment MOTAFAWWEQ:STUDENT:{id}:{passcode}:{barcode}
        if (str_starts_with($clean, 'MOTAFAWWEQ:STUDENT:')) {
            $subCode = trim(substr($trimmed, strlen('MOTAFAWWEQ:STUDENT:')));
            if (str_contains($subCode, ':')) {
                $segments = array_reverse(array_values(array_filter(array_map('trim', explode(':', $subCode)))));
                foreach ($segments as $segment) {
                    $hit = $this->resolveEnrollmentInGroup($group, $segment);
                    if ($hit) {
                        return $hit;
                    }
                }

                return null;
            }

            return $this->resolveEnrollmentInGroup($group, $subCode);
        }

        // 6. Match by student_code, phone override, or User phone
        return GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->where(function ($q) use ($clean, $trimmed) {
                $q->where('student_phone_override', $trimmed)
                    ->orWhere('uuid', strtolower($trimmed))
                    ->orWhereHas('student', function ($sq) use ($clean, $trimmed) {
                        $sq->whereRaw('UPPER(student_code) = ?', [$clean])
                            ->orWhere('phone', $trimmed);
                    });
            })
            ->with('student:id,name,phone,student_code,avatar')
            ->first();
    }

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

        $enrollment->update([
            'attended_sessions' => $attendedCount,
            'total_sessions' => $totalSessionsCount,
            'attendance_rate' => min(100, max(0, $rate)),
        ]);
    }
}
