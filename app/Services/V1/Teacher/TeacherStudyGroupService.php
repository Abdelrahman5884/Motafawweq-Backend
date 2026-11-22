<?php

namespace App\Services\V1\Teacher;

use App\Enums\DayOfWeek;
use App\Models\Course;
use App\Models\GroupAnnouncement;
use App\Models\GroupScheduleSlot;
use App\Models\StudyGroup;
use App\Models\TeacherScheduleNote;
use App\Models\User;
use App\Repositories\Contracts\StudyGroupRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherStudyGroupService extends BaseService
{
    public function __construct(
        protected StudyGroupRepositoryInterface $groupRepository,
        protected TeacherBillingService $billingService
    ) {}

    /**
     * List all study groups owned by the authenticated teacher.
     */
    public function listGroups(User $teacher, array $filters = []): Collection
    {
        return $this->groupRepository->getForTeacher($teacher->id, $filters);
    }

    /**
     * Return plan quota & feature metadata for the study groups view.
     *
     * @return array<string, mixed>
     */
    public function getGroupsMeta(User $teacher, int $filteredTotal): array
    {
        $planTier = $this->billingService->resolveActivePlanTier($teacher);
        $activeGroupsCount = StudyGroup::where('teacher_id', $teacher->id)->count();

        return [
            'total' => $filteredTotal,
            'activeGroupsCount' => $activeGroupsCount,
            'maxGroups' => $planTier->maxGroups(),
            'maxStudentsPerGroup' => $planTier->maxStudentsPerGroup(),
            'planTier' => $planTier->value,
            'allowsSchedule' => $planTier->allowsSchedule(),
        ];
    }

    /**
     * Retrieve a single study group with security ownership enforcement.
     */
    public function getGroup(User $teacher, string $uuid): StudyGroup
    {
        return $this->groupRepository->findByUuidForTeacher($uuid, $teacher);
    }

    /**
     * Create a new study group with schedule slots and unique join code.
     */
    public function createGroup(User $teacher, array $data): StudyGroup
    {
        return $this->transaction(function () use ($teacher, $data) {
            $nameAr = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? $data['name'] ?? 'مجموعة دراسية جديدة')));
            $nameEn = trim(strip_tags((string) ($data['name_en'] ?? $data['nameEn'] ?? $nameAr)));
            $subjectAr = trim(strip_tags((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? $data['subject'] ?? 'المادة الدراسية')));
            $subjectEn = trim(strip_tags((string) ($data['subject_en'] ?? $data['subjectEn'] ?? $subjectAr)));
            $gradeAr = trim(strip_tags((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? $data['grade'] ?? 'الصف الثالث الثانوي')));
            $gradeEn = trim(strip_tags((string) ($data['grade_en'] ?? $data['gradeEn'] ?? '3rd Secondary')));
            $centerName = trim(strip_tags((string) ($data['center_name'] ?? $data['centerName'] ?? 'السنتر التعليمي')));
            $hallName = trim(strip_tags((string) ($data['hall_name'] ?? $data['hallName'] ?? 'القاعة الرئيسية')));
            $scheduleAr = trim(strip_tags((string) ($data['schedule_ar'] ?? $data['scheduleAr'] ?? '')));
            $scheduleEn = trim(strip_tags((string) ($data['schedule_en'] ?? $data['scheduleEn'] ?? $scheduleAr)));

            $maxStudents = max(1, (int) ($data['max_students'] ?? $data['maxStudents'] ?? 50));
            $this->assertGroupMaxStudentsWithinPlan($teacher, $maxStudents);
            $priceEgp = max(0.0, (float) ($data['price_egp'] ?? $data['priceEgp'] ?? 0.0));

            // Determine join code
            $joinCode = null;
            if (! empty($data['join_code']) || ! empty($data['joinCode'])) {
                $rawCode = strtoupper(trim((string) ($data['join_code'] ?? $data['joinCode'])));
                if (! StudyGroup::withTrashed()->where('join_code', $rawCode)->exists()) {
                    $joinCode = $rawCode;
                }
            }
            if (! $joinCode) {
                $prefix = ! empty($subjectAr) ? Str::substr($subjectAr, 0, 3) : 'GRP';
                $joinCode = StudyGroup::generateUniqueJoinCode($prefix);
            }

            // Link to course if specified
            $courseId = null;
            $courseUuid = $data['course_id'] ?? $data['courseId'] ?? $data['course_uuid'] ?? $data['courseUuid'] ?? null;
            if ($courseUuid && is_string($courseUuid)) {
                $course = Course::where('uuid', $courseUuid)->first();
                if ($course) {
                    $course->assertOwnedBy($teacher);
                    $courseId = $course->id;
                }
            } elseif (is_numeric($courseUuid)) {
                $course = Course::find((int) $courseUuid);
                if ($course) {
                    $course->assertOwnedBy($teacher);
                    $courseId = $course->id;
                }
            }

            /** @var StudyGroup $group */
            $group = StudyGroup::create([
                'teacher_id' => $teacher->id,
                'course_id' => $courseId,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'subject_ar' => $subjectAr,
                'subject_en' => $subjectEn,
                'grade_ar' => $gradeAr,
                'grade_en' => $gradeEn,
                'center_name' => $centerName,
                'hall_name' => $hallName,
                'schedule_ar' => $scheduleAr,
                'schedule_en' => $scheduleEn,
                'max_students' => $maxStudents,
                'price_egp' => $priceEgp,
                'join_code' => $joinCode,
                'status' => 'active',
            ]);

            // Save schedule slots
            $rawSlots = $data['schedule_slots'] ?? $data['scheduleSlots'] ?? $data['slots'] ?? [];
            if (is_array($rawSlots) && count($rawSlots) > 0) {
                $this->syncGroupSlots($group, $teacher, $rawSlots);
            }

            $this->logSecurityEvent('teacher.group.created', [
                'group_uuid' => $group->uuid,
                'join_code' => $group->join_code,
                'center' => $group->center_name,
            ], 'info', $group);

            return $group->load('scheduleSlots');
        });
    }

    /**
     * Update an existing study group and synchronize schedule slots.
     */
    public function updateGroup(User $teacher, string $uuid, array $data): StudyGroup
    {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $group, $data) {
            $fillableData = [];

            if (isset($data['name_ar']) || isset($data['nameAr']) || isset($data['name'])) {
                $fillableData['name_ar'] = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? $data['name'])));
            }
            if (isset($data['name_en']) || isset($data['nameEn'])) {
                $fillableData['name_en'] = trim(strip_tags((string) ($data['name_en'] ?? $data['nameEn'])));
            }
            if (isset($data['subject_ar']) || isset($data['subjectAr']) || isset($data['subject'])) {
                $fillableData['subject_ar'] = trim(strip_tags((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? $data['subject'])));
            }
            if (isset($data['subject_en']) || isset($data['subjectEn'])) {
                $fillableData['subject_en'] = trim(strip_tags((string) ($data['subject_en'] ?? $data['subjectEn'])));
            }
            if (isset($data['grade_ar']) || isset($data['gradeAr']) || isset($data['grade'])) {
                $fillableData['grade_ar'] = trim(strip_tags((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? $data['grade'])));
            }
            if (isset($data['grade_en']) || isset($data['gradeEn'])) {
                $fillableData['grade_en'] = trim(strip_tags((string) ($data['grade_en'] ?? $data['gradeEn'])));
            }
            if (isset($data['center_name']) || isset($data['centerName'])) {
                $fillableData['center_name'] = trim(strip_tags((string) ($data['center_name'] ?? $data['centerName'])));
            }
            if (isset($data['hall_name']) || isset($data['hallName'])) {
                $fillableData['hall_name'] = trim(strip_tags((string) ($data['hall_name'] ?? $data['hallName'])));
            }
            if (isset($data['schedule_ar']) || isset($data['scheduleAr'])) {
                $fillableData['schedule_ar'] = trim(strip_tags((string) ($data['schedule_ar'] ?? $data['scheduleAr'])));
            }
            if (isset($data['schedule_en']) || isset($data['scheduleEn'])) {
                $fillableData['schedule_en'] = trim(strip_tags((string) ($data['schedule_en'] ?? $data['scheduleEn'])));
            }
            if (isset($data['max_students']) || isset($data['maxStudents'])) {
                $requestedMax = max(1, (int) ($data['max_students'] ?? $data['maxStudents']));
                $this->assertGroupMaxStudentsWithinPlan($teacher, $requestedMax);
                $fillableData['max_students'] = $requestedMax;
            }
            if (isset($data['price_egp']) || isset($data['priceEgp'])) {
                $fillableData['price_egp'] = max(0.0, (float) ($data['price_egp'] ?? $data['priceEgp']));
            }
            if (isset($data['status'])) {
                $fillableData['status'] = in_array($data['status'], ['active', 'archived'], true) ? $data['status'] : 'active';
            }

            if (! empty($fillableData)) {
                $group->update($fillableData);
            }

            // Sync schedule slots if passed
            $rawSlots = $data['schedule_slots'] ?? $data['scheduleSlots'] ?? $data['slots'] ?? null;
            if (is_array($rawSlots)) {
                $this->syncGroupSlots($group, $teacher, $rawSlots);
            } elseif (($fillableData['status'] ?? null) === 'active') {
                $existingSlots = $group->scheduleSlots()->get()->map(fn ($s) => [
                    'day' => $s->day_of_week instanceof DayOfWeek ? $s->day_of_week->value : (string) $s->day_of_week,
                    'start_time' => $s->start_time,
                    'end_time' => $s->end_time,
                ])->all();
                if (! empty($existingSlots)) {
                    $this->assertNoScheduleOverlap($teacher, $existingSlots, $group->id, true);
                }
            }

            $this->logSecurityEvent('teacher.group.updated', [
                'group_uuid' => $group->uuid,
                'changes' => array_keys($fillableData),
            ], 'info', $group);

            return $group->fresh(['scheduleSlots'])?->loadCount([
                'enrolledEnrollments as enrolled_count',
                'pendingEnrollments as pending_count',
            ]) ?? $group;
        });
    }

    /**
     * Soft delete a study group.
     */
    public function deleteGroup(User $teacher, string $uuid): bool
    {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($group) {
            $groupUuid = $group->uuid;
            $group->delete();

            $this->logSecurityEvent('teacher.group.deleted', [
                'group_uuid' => $groupUuid,
            ], 'warning', $group);

            return true;
        });
    }

    /**
     * Invalidate old join code and generate a fresh unique code for the group (Anti-leakage).
     */
    public function regenerateJoinCode(User $teacher, string $uuid): StudyGroup
    {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($group) {
            $oldCode = $group->join_code;
            $prefix = ! empty($group->subject_ar) ? Str::substr($group->subject_ar, 0, 3) : 'GRP';
            $newCode = StudyGroup::generateUniqueJoinCode($prefix);

            $group->update(['join_code' => $newCode]);

            $this->logSecurityEvent('teacher.group.code_regenerated', [
                'group_uuid' => $group->uuid,
                'old_code' => substr($oldCode, 0, 4).'***',
                'new_code' => $newCode,
            ], 'info', $group);

            return $group->fresh(['scheduleSlots'])?->loadCount([
                'enrolledEnrollments as enrolled_count',
                'pendingEnrollments as pending_count',
            ]) ?? $group;
        });
    }

    /**
     * Sync schedule slots for a group after validating there are no time overlaps.
     */
    protected function syncGroupSlots(StudyGroup $group, User $teacher, array $slots): void
    {
        $this->assertNoScheduleOverlap(
            $teacher,
            $slots,
            $group->id,
            $group->status === 'active'
        );

        GroupScheduleSlot::where('study_group_id', $group->id)->delete();

        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $day = strtolower(trim((string) ($slot['day'] ?? $slot['day_of_week'] ?? '')));
            if (! in_array($day, DayOfWeek::values(), true)) {
                continue;
            }

            $startTime = $this->normalizeTime((string) ($slot['start_time'] ?? $slot['startTime'] ?? '16:00'));
            $endTime = $this->normalizeTime((string) ($slot['end_time'] ?? $slot['endTime'] ?? '18:00'));
            $hall = trim(strip_tags((string) ($slot['hall'] ?? $slot['hall_name'] ?? $group->hall_name ?? 'القاعة الرئيسية')));

            GroupScheduleSlot::create([
                'study_group_id' => $group->id,
                'teacher_id' => $teacher->id,
                'day_of_week' => $day,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'hall_name' => $hall,
            ]);
        }
    }

    /**
     * Validate that submitted schedule slots have valid ranges and do not overlap
     * with each other or with any other active group belonging to the teacher.
     *
     * @throws ValidationException
     */
    protected function assertNoScheduleOverlap(
        User $teacher,
        array $slots,
        ?int $excludeGroupId = null,
        bool $checkActiveGroups = true
    ): void {
        $dayNamesAr = [
            'saturday' => 'السبت',
            'sunday' => 'الأحد',
            'monday' => 'الاثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
        ];

        $normalized = [];
        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $day = strtolower(trim((string) ($slot['day'] ?? $slot['day_of_week'] ?? '')));
            if (! in_array($day, DayOfWeek::values(), true)) {
                continue;
            }

            $startTime = $this->normalizeTime((string) ($slot['start_time'] ?? $slot['startTime'] ?? '16:00'));
            $endTime = $this->normalizeTime((string) ($slot['end_time'] ?? $slot['endTime'] ?? '18:00'));
            $startMin = $this->parseTimeToMinutes($startTime);
            $endMin = $this->parseTimeToMinutes($endTime);

            if ($startMin < 0 || $endMin < 0 || $endMin <= $startMin) {
                $dayAr = $dayNamesAr[$day] ?? $day;
                throw ValidationException::withMessages([
                    'slots' => ["وقت انتهاء الحصة يجب أن يكون بعد وقت البداية يوم {$dayAr} ({$startTime} - {$endTime})."],
                ]);
            }

            $normalized[] = [
                'day' => $day,
                'day_ar' => $dayNamesAr[$day] ?? $day,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'start_min' => $startMin,
                'end_min' => $endMin,
            ];
        }

        if (empty($normalized)) {
            return;
        }

        // 1. Check intra-group overlap (two sessions within the same group on the same day)
        $count = count($normalized);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $normalized[$i];
                $b = $normalized[$j];
                if ($a['day'] === $b['day'] && $a['start_min'] < $b['end_min'] && $a['end_min'] > $b['start_min']) {
                    throw ValidationException::withMessages([
                        'slots' => ["يوجد تداخل في مواعيد الحصص داخل نفس المجموعة يوم {$a['day_ar']} ({$a['start_time']} - {$a['end_time']} مع {$b['start_time']} - {$b['end_time']})."],
                    ]);
                }
            }
        }

        if (! $checkActiveGroups) {
            return;
        }

        // 2. Check overlap against all other active groups owned by this teacher
        $days = array_values(array_unique(array_column($normalized, 'day')));
        $existingSlots = GroupScheduleSlot::query()
            ->where('teacher_id', $teacher->id)
            ->whereIn('day_of_week', $days)
            ->when($excludeGroupId !== null, fn ($q) => $q->where('study_group_id', '!=', $excludeGroupId))
            ->whereHas('studyGroup', fn ($q) => $q->where('status', 'active'))
            ->with('studyGroup:id,name_ar')
            ->get();

        foreach ($normalized as $slot) {
            foreach ($existingSlots as $existing) {
                $existingDay = $existing->day_of_week instanceof DayOfWeek
                    ? $existing->day_of_week->value
                    : (string) $existing->day_of_week;

                if ($existingDay !== $slot['day']) {
                    continue;
                }

                $existingStart = $this->parseTimeToMinutes((string) $existing->start_time);
                $existingEnd = $this->parseTimeToMinutes((string) $existing->end_time);

                if ($existingStart < 0 || $existingEnd < 0) {
                    continue;
                }

                if ($slot['start_min'] < $existingEnd && $slot['end_min'] > $existingStart) {
                    $groupName = $existing->studyGroup?->name_ar ?? 'مجموعة أخرى';
                    throw ValidationException::withMessages([
                        'slots' => ["يوجد تعارض في المواعيد يوم {$slot['day_ar']} ({$slot['start_time']} - {$slot['end_time']}) مع مجموعة «{$groupName}» ({$existing->start_time} - {$existing->end_time})."],
                    ]);
                }
            }
        }
    }

    /**
     * Normalize HH:MM time string (e.g. "4:00" -> "04:00").
     */
    protected function normalizeTime(string $time): string
    {
        $parts = explode(':', trim($time));
        if (count($parts) !== 2) {
            return trim($time);
        }

        return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
    }

    /**
     * Convert HH:MM time string to minutes since midnight, or -1 if invalid.
     */
    protected function parseTimeToMinutes(string $time): int
    {
        $parts = explode(':', trim($time));
        if (count($parts) !== 2) {
            return -1;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return -1;
        }

        return ($hours * 60) + $minutes;
    }

    /**
     * Get the aggregated weekly timetable (all 7 days, sessions, and daily notes) for TeacherWeeklySchedule.
     */
    public function getWeeklySchedule(User $teacher, ?string $centerFilter = null): array
    {
        $groupsQuery = StudyGroup::forTeacher($teacher->id)
            ->where('status', 'active')
            ->with(['scheduleSlots'])
            ->withCount([
                'enrolledEnrollments as enrolled_count',
                'pendingEnrollments as pending_count',
            ]);

        if ($centerFilter && $centerFilter !== 'all') {
            $groupsQuery->where('center_name', $centerFilter);
        }

        $groups = $groupsQuery->get();

        $notes = TeacherScheduleNote::forTeacher($teacher->id)
            ->orderBy('is_done')
            ->orderBy('note_time')
            ->get();

        $daysConfig = [
            'saturday' => ['dayIndex' => 6, 'nameAr' => 'السبت', 'nameEn' => 'Saturday'],
            'sunday' => ['dayIndex' => 0, 'nameAr' => 'الأحد', 'nameEn' => 'Sunday'],
            'monday' => ['dayIndex' => 1, 'nameAr' => 'الاثنين', 'nameEn' => 'Monday'],
            'tuesday' => ['dayIndex' => 2, 'nameAr' => 'الثلاثاء', 'nameEn' => 'Tuesday'],
            'wednesday' => ['dayIndex' => 3, 'nameAr' => 'الأربعاء', 'nameEn' => 'Wednesday'],
            'thursday' => ['dayIndex' => 4, 'nameAr' => 'الخميس', 'nameEn' => 'Thursday'],
            'friday' => ['dayIndex' => 5, 'nameAr' => 'الجمعة', 'nameEn' => 'Friday'],
        ];

        $todayDayMap = [
            0 => 'sunday',
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
        ];
        $todayKey = $todayDayMap[now()->dayOfWeek] ?? 'saturday';

        $days = [];
        $totalSessions = 0;
        $allCenters = $groups->pluck('center_name')->filter()->unique()->values()->all();

        foreach ($daysConfig as $dayKey => $cfg) {
            $daySessions = [];

            foreach ($groups as $group) {
                /** @var GroupScheduleSlot $slot */
                foreach ($group->scheduleSlots as $slot) {
                    $slotDay = $slot->day_of_week instanceof DayOfWeek ? $slot->day_of_week->value : (string) $slot->day_of_week;
                    if ($slotDay === $dayKey) {
                        $daySessions[] = [
                            'slotId' => $slot->uuid,
                            'groupId' => $group->uuid,
                            'groupName' => $group->name_ar,
                            'subject' => $group->subject_ar,
                            'grade' => $group->grade_ar,
                            'centerName' => $group->center_name,
                            'hallName' => $slot->hall_name ?: $group->hall_name,
                            'startTime' => $slot->start_time,
                            'endTime' => $slot->end_time,
                            'joinCode' => $group->join_code,
                            'enrolledCount' => (int) ($group->enrolled_count ?? 0),
                            'pendingCount' => (int) ($group->pending_count ?? 0),
                        ];
                    }
                }
            }

            // Sort sessions by start_time
            usort($daySessions, fn ($a, $b) => strcmp($a['startTime'], $b['startTime']));

            $dayNotes = $notes->filter(function (TeacherScheduleNote $note) use ($dayKey) {
                $noteDay = $note->day_of_week instanceof DayOfWeek ? $note->day_of_week->value : (string) $note->day_of_week;

                return $noteDay === $dayKey;
            })->map(fn (TeacherScheduleNote $note) => [
                'id' => $note->uuid,
                'uuid' => $note->uuid,
                'text' => $note->note_text,
                'time' => $note->note_time,
                'priority' => $note->priority,
                'isDone' => (bool) $note->is_done,
                'createdAt' => $note->created_at?->toDateString(),
            ])->values()->all();

            $totalSessions += count($daySessions);

            $days[$dayKey] = [
                'dayKey' => $dayKey,
                'dayName' => $cfg['nameAr'],
                'dayEn' => $cfg['nameEn'],
                'isToday' => $dayKey === $todayKey,
                'sessions' => $daySessions,
                'notes' => $dayNotes,
            ];
        }

        return [
            'days' => $days,
            'centers' => $allCenters,
            'todayDayKey' => $todayKey,
            'todaySessionsCount' => count($days[$todayKey]['sessions'] ?? []),
            'totalSessions' => $totalSessions,
            'activeGroupsCount' => $groups->count(),
        ];
    }

    /**
     * Create a new schedule note for a specific day.
     */
    public function createScheduleNote(User $teacher, array $data): TeacherScheduleNote
    {
        $day = strtolower(trim((string) ($data['day'] ?? $data['day_of_week'] ?? '')));
        $text = trim(strip_tags((string) ($data['text'] ?? $data['note_text'] ?? '')));
        $time = trim((string) ($data['time'] ?? $data['note_time'] ?? '16:00'));
        $priority = strtolower(trim((string) ($data['priority'] ?? 'medium')));

        if (! in_array($priority, ['high', 'medium', 'normal', 'low'], true)) {
            $priority = 'medium';
        }
        if ($priority === 'low') {
            $priority = 'normal';
        }

        return TeacherScheduleNote::create([
            'teacher_id' => $teacher->id,
            'day_of_week' => $day,
            'note_text' => $text,
            'note_time' => $time,
            'priority' => $priority,
            'is_done' => false,
        ]);
    }

    /**
     * Toggle the done status of a schedule note.
     */
    public function toggleScheduleNote(User $teacher, string $uuid): TeacherScheduleNote
    {
        /** @var TeacherScheduleNote|null $note */
        $note = TeacherScheduleNote::where('uuid', $uuid)->first();
        if (! $note) {
            throw (new ModelNotFoundException)->setModel(TeacherScheduleNote::class, [$uuid]);
        }

        $note->assertOwnedBy($teacher);

        $note->update(['is_done' => ! $note->is_done]);

        return $note;
    }

    /**
     * Delete a schedule note.
     */
    public function deleteScheduleNote(User $teacher, string $uuid): bool
    {
        /** @var TeacherScheduleNote|null $note */
        $note = TeacherScheduleNote::where('uuid', $uuid)->first();
        if (! $note) {
            throw (new ModelNotFoundException)->setModel(TeacherScheduleNote::class, [$uuid]);
        }

        $note->assertOwnedBy($teacher);
        $note->delete();

        return true;
    }

    /**
     * List recent announcements sent by the teacher to a specific study group.
     */
    public function listAnnouncements(User $teacher, string $groupUuid, int $limit = 15): Collection
    {
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        return GroupAnnouncement::where('study_group_id', $group->id)
            ->where('teacher_id', $teacher->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Persist and audit a cohort broadcast announcement to an owned study group.
     */
    public function sendAnnouncement(User $teacher, string $groupUuid, array $data): GroupAnnouncement
    {
        $group = $this->groupRepository->findByUuidForTeacher($groupUuid, $teacher);

        $type = strtolower(trim((string) ($data['type'] ?? 'announcement')));
        if (! in_array($type, GroupAnnouncement::TYPES, true)) {
            $type = 'announcement';
        }

        $message = trim(strip_tags((string) ($data['message'] ?? '')));
        $recipientsCount = (int) $group->enrolledEnrollments()->count();

        /** @var GroupAnnouncement $announcement */
        $announcement = GroupAnnouncement::create([
            'teacher_id' => $teacher->id,
            'study_group_id' => $group->id,
            'type' => $type,
            'message' => $message,
            'recipients_count' => $recipientsCount,
        ]);

        $this->logSecurityEvent(
            'teacher.group.announcement_sent',
            [
                'group_uuid' => $group->uuid,
                'announcement_uuid' => $announcement->uuid,
                'type' => $announcement->type,
                'recipients_count' => $recipientsCount,
            ],
            'info',
            $announcement
        );

        return $announcement;
    }

    /**
     * Ensure requested study group student capacity does not exceed the teacher's active plan limit.
     *
     * @throws ValidationException
     */
    protected function assertGroupMaxStudentsWithinPlan(User $teacher, int $requestedMaxStudents): void
    {
        $tier = $this->billingService->resolveActivePlanTier($teacher);
        $maxPerGroup = $tier->maxStudentsPerGroup();

        if ($maxPerGroup !== null && $requestedMaxStudents > $maxPerGroup) {
            throw ValidationException::withMessages([
                'max_students' => [__('messages.quota_group_max_students_exceeded', [
                    'max' => $maxPerGroup,
                    'plan' => $tier->label(),
                ])],
            ]);
        }
    }
}
