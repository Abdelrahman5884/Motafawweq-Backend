<?php

namespace App\Services\V1\Teacher;

use App\Enums\DayOfWeek;
use App\Enums\ScheduleNotePriority;
use App\Models\Course;
use App\Models\GroupScheduleSlot;
use App\Models\StudyGroup;
use App\Models\TeacherScheduleNote;
use App\Models\User;
use App\Repositories\Contracts\StudyGroupRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TeacherStudyGroupService extends BaseService
{
    public function __construct(
        protected StudyGroupRepositoryInterface $groupRepository
    ) {}

    /**
     * List all study groups owned by the authenticated teacher.
     */
    public function listGroups(User $teacher, array $filters = []): Collection
    {
        return $this->groupRepository->getForTeacher($teacher->id, $filters);
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
            $priceEgp = max(0.0, (float) ($data['price_egp'] ?? $data['priceEgp'] ?? 0.0));

            // Determine join code
            $joinCode = null;
            if (!empty($data['join_code']) || !empty($data['joinCode'])) {
                $rawCode = strtoupper(trim((string) ($data['join_code'] ?? $data['joinCode'])));
                if (!StudyGroup::where('join_code', $rawCode)->exists()) {
                    $joinCode = $rawCode;
                }
            }
            if (!$joinCode) {
                $prefix = !empty($subjectAr) ? Str::substr($subjectAr, 0, 3) : 'GRP';
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
                'teacher_id'   => $teacher->id,
                'course_id'    => $courseId,
                'name_ar'      => $nameAr,
                'name_en'      => $nameEn,
                'subject_ar'   => $subjectAr,
                'subject_en'   => $subjectEn,
                'grade_ar'     => $gradeAr,
                'grade_en'     => $gradeEn,
                'center_name'  => $centerName,
                'hall_name'    => $hallName,
                'schedule_ar'  => $scheduleAr,
                'schedule_en'  => $scheduleEn,
                'max_students' => $maxStudents,
                'price_egp'    => $priceEgp,
                'join_code'    => $joinCode,
                'status'       => 'active',
            ]);

            // Save schedule slots
            $rawSlots = $data['schedule_slots'] ?? $data['scheduleSlots'] ?? $data['slots'] ?? [];
            if (is_array($rawSlots) && count($rawSlots) > 0) {
                $this->syncGroupSlots($group, $teacher, $rawSlots);
            }

            $this->logSecurityEvent('teacher.group.created', [
                'group_uuid' => $group->uuid,
                'join_code'  => $group->join_code,
                'center'     => $group->center_name,
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
                $fillableData['max_students'] = max(1, (int) ($data['max_students'] ?? $data['maxStudents']));
            }
            if (isset($data['price_egp']) || isset($data['priceEgp'])) {
                $fillableData['price_egp'] = max(0.0, (float) ($data['price_egp'] ?? $data['priceEgp']));
            }
            if (isset($data['status'])) {
                $fillableData['status'] = in_array($data['status'], ['active', 'archived'], true) ? $data['status'] : 'active';
            }

            if (!empty($fillableData)) {
                $group->update($fillableData);
            }

            // Sync schedule slots if passed
            $rawSlots = $data['schedule_slots'] ?? $data['scheduleSlots'] ?? $data['slots'] ?? null;
            if (is_array($rawSlots)) {
                $this->syncGroupSlots($group, $teacher, $rawSlots);
            }

            $this->logSecurityEvent('teacher.group.updated', [
                'group_uuid' => $group->uuid,
                'changes'    => array_keys($fillableData),
            ], 'info', $group);

            return $group->fresh(['scheduleSlots']);
        });
    }

    /**
     * Soft delete a study group.
     */
    public function deleteGroup(User $teacher, string $uuid): bool
    {
        // Enforce ownership BEFORE opening transaction
        $group = $this->groupRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $group) {
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

        return $this->transaction(function () use ($teacher, $group) {
            $oldCode = $group->join_code;
            $prefix = !empty($group->subject_ar) ? Str::substr($group->subject_ar, 0, 3) : 'GRP';
            $newCode = StudyGroup::generateUniqueJoinCode($prefix);

            $group->update(['join_code' => $newCode]);

            $this->logSecurityEvent('teacher.group.code_regenerated', [
                'group_uuid' => $group->uuid,
                'old_code'   => substr($oldCode, 0, 4) . '***',
                'new_code'   => $newCode,
            ], 'info', $group);

            return $group->fresh(['scheduleSlots']);
        });
    }

    /**
     * Sync schedule slots for a group.
     */
    protected function syncGroupSlots(StudyGroup $group, User $teacher, array $slots): void
    {
        GroupScheduleSlot::where('study_group_id', $group->id)->delete();

        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $day = strtolower(trim((string) ($slot['day'] ?? $slot['day_of_week'] ?? '')));
            if (!in_array($day, DayOfWeek::values(), true)) {
                continue;
            }

            $startTime = trim((string) ($slot['start_time'] ?? $slot['startTime'] ?? '16:00'));
            $endTime = trim((string) ($slot['end_time'] ?? $slot['endTime'] ?? '18:00'));
            $hall = trim(strip_tags((string) ($slot['hall'] ?? $slot['hall_name'] ?? $group->hall_name ?? 'القاعة الرئيسية')));

            GroupScheduleSlot::create([
                'study_group_id' => $group->id,
                'teacher_id'     => $teacher->id,
                'day_of_week'    => $day,
                'start_time'     => $startTime,
                'end_time'       => $endTime,
                'hall_name'      => $hall,
            ]);
        }
    }

    /**
     * Get the aggregated weekly timetable (all 7 days, sessions, and daily notes) for TeacherWeeklySchedule.
     */
    public function getWeeklySchedule(User $teacher, ?string $centerFilter = null): array
    {
        $groupsQuery = StudyGroup::forTeacher($teacher->id)
            ->where('status', 'active')
            ->with(['scheduleSlots']);

        if ($centerFilter && $centerFilter !== 'all') {
            $groupsQuery->where('center_name', $centerFilter);
        }

        $groups = $groupsQuery->get();

        $notes = TeacherScheduleNote::forTeacher($teacher->id)
            ->orderBy('is_done')
            ->orderBy('note_time')
            ->get();

        $daysConfig = [
            'saturday'  => ['dayIndex' => 6, 'nameAr' => 'السبت', 'nameEn' => 'Saturday'],
            'sunday'    => ['dayIndex' => 0, 'nameAr' => 'الأحد', 'nameEn' => 'Sunday'],
            'monday'    => ['dayIndex' => 1, 'nameAr' => 'الاثنين', 'nameEn' => 'Monday'],
            'tuesday'   => ['dayIndex' => 2, 'nameAr' => 'الثلاثاء', 'nameEn' => 'Tuesday'],
            'wednesday' => ['dayIndex' => 3, 'nameAr' => 'الأربعاء', 'nameEn' => 'Wednesday'],
            'thursday'  => ['dayIndex' => 4, 'nameAr' => 'الخميس', 'nameEn' => 'Thursday'],
            'friday'    => ['dayIndex' => 5, 'nameAr' => 'الجمعة', 'nameEn' => 'Friday'],
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
        $todayKey = $todayDayMap[date('w')];

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
                            'slotId'     => $slot->uuid,
                            'groupId'    => $group->uuid,
                            'groupName'  => $group->name_ar,
                            'subject'    => $group->subject_ar,
                            'grade'      => $group->grade_ar,
                            'centerName' => $group->center_name,
                            'hallName'   => $slot->hall_name ?: $group->hall_name,
                            'startTime'  => $slot->start_time,
                            'endTime'    => $slot->end_time,
                            'joinCode'   => $group->join_code,
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
                'id'        => $note->uuid,
                'uuid'      => $note->uuid,
                'text'      => $note->note_text,
                'time'      => $note->note_time,
                'priority'  => $note->priority,
                'isDone'    => (bool) $note->is_done,
                'createdAt' => $note->created_at?->toDateString(),
            ])->values()->all();

            $totalSessions += count($daySessions);

            $days[$dayKey] = [
                'dayKey'   => $dayKey,
                'dayName'  => $cfg['nameAr'],
                'dayEn'    => $cfg['nameEn'],
                'isToday'  => $dayKey === $todayKey,
                'sessions' => $daySessions,
                'notes'    => $dayNotes,
            ];
        }

        return [
            'days'               => $days,
            'centers'            => $allCenters,
            'todayDayKey'        => $todayKey,
            'todaySessionsCount' => count($days[$todayKey]['sessions'] ?? []),
            'totalSessions'      => $totalSessions,
            'activeGroupsCount'  => $groups->count(),
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

        if (!in_array($priority, ['high', 'medium', 'normal', 'low'], true)) {
            $priority = 'medium';
        }
        if ($priority === 'low') {
            $priority = 'normal';
        }

        return TeacherScheduleNote::create([
            'teacher_id'  => $teacher->id,
            'day_of_week' => $day,
            'note_text'   => $text,
            'note_time'   => $time,
            'priority'    => $priority,
            'is_done'     => false,
        ]);
    }

    /**
     * Toggle the done status of a schedule note.
     */
    public function toggleScheduleNote(User $teacher, string $uuid): TeacherScheduleNote
    {
        /** @var TeacherScheduleNote|null $note */
        $note = TeacherScheduleNote::where('uuid', $uuid)->first();
        if (!$note) {
            throw (new ModelNotFoundException())->setModel(TeacherScheduleNote::class, [$uuid]);
        }

        $note->assertOwnedBy($teacher);

        $note->update(['is_done' => !$note->is_done]);

        return $note;
    }

    /**
     * Delete a schedule note.
     */
    public function deleteScheduleNote(User $teacher, string $uuid): bool
    {
        /** @var TeacherScheduleNote|null $note */
        $note = TeacherScheduleNote::where('uuid', $uuid)->first();
        if (!$note) {
            throw (new ModelNotFoundException())->setModel(TeacherScheduleNote::class, [$uuid]);
        }

        $note->assertOwnedBy($teacher);
        $note->delete();

        return true;
    }
}

