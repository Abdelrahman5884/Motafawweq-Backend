<?php

namespace App\Services\V1\Center;

use App\Enums\DayOfWeek;
use App\Models\CenterRoom;
use App\Models\GroupScheduleSlot;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Validation\ValidationException;

class CenterScheduleService extends BaseService
{
    public const STANDARD_SLOTS = [
        ['label' => '10:00 ص - 12:00 م', 'startTime' => '10:00', 'endTime' => '12:00'],
        ['label' => '12:00 م - 02:00 م', 'startTime' => '12:00', 'endTime' => '14:00'],
        ['label' => '02:00 م - 04:00 م', 'startTime' => '14:00', 'endTime' => '16:00'],
        ['label' => '04:00 م - 06:00 م', 'startTime' => '16:00', 'endTime' => '18:00'],
        ['label' => '06:00 م - 08:00 م', 'startTime' => '18:00', 'endTime' => '20:00'],
        ['label' => '08:00 م - 10:00 م', 'startTime' => '20:00', 'endTime' => '22:00'],
    ];

    public const DAY_LABELS = [
        'saturday' => ['ar' => 'السبت', 'en' => 'Saturday'],
        'sunday' => ['ar' => 'الأحد', 'en' => 'Sunday'],
        'monday' => ['ar' => 'الاثنين', 'en' => 'Monday'],
        'tuesday' => ['ar' => 'الثلاثاء', 'en' => 'Tuesday'],
        'wednesday' => ['ar' => 'الأربعاء', 'en' => 'Wednesday'],
        'thursday' => ['ar' => 'الخميس', 'en' => 'Thursday'],
        'friday' => ['ar' => 'الجمعة', 'en' => 'Friday'],
    ];

    public function __construct(
        protected CenterBranchService $branchService
    ) {}

    /**
     * Conflict-Free Master Schedule for a specific weekday (or today) across all rooms of the center/branch.
     *
     * @return array<string, mixed>
     */
    public function getMasterSchedule(User $center, ?string $day = null, ?string $branchUuid = null): array
    {
        $selectedDay = strtolower(trim((string) ($day ?: now()->format('l'))));
        if (! in_array($selectedDay, DayOfWeek::values(), true)) {
            $selectedDay = 'saturday';
        }

        $roomsQuery = CenterRoom::forCenter($center->id)
            ->with('branch:id,uuid,name_ar,name_en')
            ->orderBy('name_ar');

        if ($branchUuid && $branchUuid !== 'all') {
            $branch = $this->branchService->findOwnedBranch($center, $branchUuid);
            $roomsQuery->where('branch_id', $branch->id);
        }

        $rooms = $roomsQuery->get();

        $activeGroups = StudyGroup::where('center_id', $center->id)
            ->where('status', 'active')
            ->with([
                'scheduleSlots' => fn ($q) => $q->where('day_of_week', $selectedDay),
                'centerTeacher:id,uuid,name_ar',
                'centerBranch:id,uuid,name_ar',
            ])
            ->withCount('enrolledEnrollments as enrolled_count')
            ->get();

        $hallsSchedule = [];
        $occupiedRoomsCount = 0;
        $totalSessionsCount = 0;

        foreach ($rooms as $room) {
            $sessions = [];

            foreach ($activeGroups as $group) {
                foreach ($group->scheduleSlots as $slot) {
                    if ($this->slotMatchesRoom($slot, $group, $room)) {
                        $sessions[] = [
                            'slotId' => $slot->uuid,
                            'groupId' => $group->uuid,
                            'groupNameAr' => $group->name_ar,
                            'groupNameEn' => $group->name_en ?: $group->name_ar,
                            'subjectAr' => $group->subject_ar,
                            'gradeAr' => $group->grade_ar,
                            'teacherNameAr' => $group->centerTeacher?->name_ar ?: ($group->teacher_name_override ?: 'معلم معتمد'),
                            'startTime' => $slot->start_time,
                            'endTime' => $slot->end_time,
                            'enrolledCount' => (int) ($group->enrolled_count ?? 0),
                            'maxStudents' => (int) $group->max_students,
                            'capacity' => (int) $room->capacity,
                        ];
                    }
                }
            }

            usort($sessions, fn ($a, $b) => strcmp($a['startTime'], $b['startTime']));

            $freeSlots = [];
            foreach (self::STANDARD_SLOTS as $std) {
                $stdStart = $this->parseTimeToMinutes($std['startTime']);
                $stdEnd = $this->parseTimeToMinutes($std['endTime']);
                $overlaps = false;

                foreach ($sessions as $sess) {
                    $sStart = $this->parseTimeToMinutes($sess['startTime']);
                    $sEnd = $this->parseTimeToMinutes($sess['endTime']);
                    if ($stdStart < $sEnd && $stdEnd > $sStart) {
                        $overlaps = true;
                        break;
                    }
                }

                if (! $overlaps) {
                    $freeSlots[] = $std;
                }
            }

            $isOccupied = count($sessions) > 0;
            if ($isOccupied) {
                $occupiedRoomsCount++;
            }
            $totalSessionsCount += count($sessions);

            $hallsSchedule[] = [
                'roomId' => $room->uuid,
                'roomNameAr' => $room->name_ar,
                'roomNameEn' => $room->name_en ?: $room->name_ar,
                'branchId' => $room->branch?->uuid,
                'branchNameAr' => $room->branch?->name_ar ?? 'الفرع الرئيسي',
                'floor' => $room->floor ?? 'الطابق الأول',
                'capacity' => (int) $room->capacity,
                'isOccupied' => $isOccupied,
                'sessionsCount' => count($sessions),
                'sessions' => $sessions,
                'freeSlots' => $freeSlots,
            ];
        }

        $dayMeta = self::DAY_LABELS[$selectedDay] ?? ['ar' => $selectedDay, 'en' => ucfirst($selectedDay)];

        return [
            'day' => $selectedDay,
            'dayAr' => $dayMeta['ar'],
            'dayEn' => $dayMeta['en'],
            'summary' => [
                'totalRooms' => count($hallsSchedule),
                'occupiedRooms' => $occupiedRoomsCount,
                'availableRooms' => max(0, count($hallsSchedule) - $occupiedRoomsCount),
                'totalSessions' => $totalSessionsCount,
            ],
            'halls' => $hallsSchedule,
            'standardSlots' => self::STANDARD_SLOTS,
        ];
    }

    /**
     * Evaluate availability of all standard slots for a given day + room (+ optional teacher).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSlotAvailability(
        User $center,
        string $day,
        ?int $roomId = null,
        ?string $hallName = null,
        ?int $excludeGroupId = null,
        ?int $centerTeacherId = null
    ): array {
        $dayKey = strtolower(trim($day));
        if (! in_array($dayKey, DayOfWeek::values(), true)) {
            return [];
        }

        $results = [];
        foreach (self::STANDARD_SLOTS as $std) {
            $check = $this->checkConflicts($center, [[
                'day' => $dayKey,
                'start_time' => $std['startTime'],
                'end_time' => $std['endTime'],
                'room_id' => $roomId,
                'hall_name' => $hallName,
            ]], $excludeGroupId, $centerTeacherId);

            $firstConflict = $check['conflicts'][0] ?? null;

            $results[] = [
                'label' => $std['label'],
                'startTime' => $std['startTime'],
                'endTime' => $std['endTime'],
                'isAvailable' => ! $check['hasConflict'],
                'conflictType' => $firstConflict['type'] ?? null,
                'conflictMessageAr' => $firstConflict['messageAr'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * Check candidate schedule slots for:
     *  1) Invalid time ranges (end <= start)
     *  2) Intra-batch overlap (two slots in the same group overlapping in the same hall or same time)
     *  3) Cross-group room overlap (same room booked for another active cohort in this center)
     *  4) Cross-group teacher overlap (same center teacher scheduled in another active cohort at same time)
     *
     * @param  array<int, array<string, mixed>>  $candidateSlots
     * @return array{hasConflict: bool, conflicts: array<int, array<string, mixed>>, messageAr: string}
     */
    public function checkConflicts(
        User $center,
        array $candidateSlots,
        ?int $excludeGroupId = null,
        ?int $centerTeacherId = null
    ): array {
        $conflicts = [];
        $normalized = [];

        foreach ($candidateSlots as $idx => $slot) {
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
            $dayAr = self::DAY_LABELS[$day]['ar'] ?? $day;

            if ($startMin < 0 || $endMin < 0 || $endMin <= $startMin) {
                $conflicts[] = [
                    'slotIndex' => $idx,
                    'type' => 'invalid_time_range',
                    'messageAr' => "وقت انتهاء الحصة يجب أن يكون بعد وقت البداية يوم {$dayAr} ({$startTime} - {$endTime}).",
                ];

                continue;
            }

            $roomId = isset($slot['room_id']) && is_numeric($slot['room_id']) ? (int) $slot['room_id'] : null;
            $hallName = trim(strip_tags((string) ($slot['hall_name'] ?? $slot['hall'] ?? '')));

            $normalized[] = [
                'index' => $idx,
                'day' => $day,
                'dayAr' => $dayAr,
                'startTime' => $startTime,
                'endTime' => $endTime,
                'startMin' => $startMin,
                'endMin' => $endMin,
                'roomId' => $roomId,
                'hallName' => $hallName,
                'hallNormalized' => $this->normalizeHallKey($hallName),
            ];
        }

        // 1. Intra-batch overlap check
        $count = count($normalized);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $normalized[$i];
                $b = $normalized[$j];
                if ($a['day'] === $b['day'] && $a['startMin'] < $b['endMin'] && $a['endMin'] > $b['startMin']) {
                    $conflicts[] = [
                        'slotIndex' => $b['index'],
                        'type' => 'internal_overlap',
                        'messageAr' => 'تعارض داخلي: يتداخل توقيت الحصة رقم '.($b['index'] + 1).' مع الحصة رقم '.($a['index'] + 1)." يوم {$a['dayAr']} ({$a['startTime']} - {$a['endTime']}).",
                    ];
                }
            }
        }

        if (empty($normalized)) {
            return [
                'hasConflict' => count($conflicts) > 0,
                'conflicts' => $conflicts,
                'messageAr' => $conflicts[0]['messageAr'] ?? '',
            ];
        }

        // 2. External cross-group checks against all active groups in this center
        $days = array_values(array_unique(array_column($normalized, 'day')));
        $existingSlots = GroupScheduleSlot::query()
            ->whereIn('day_of_week', $days)
            ->when($excludeGroupId !== null, fn ($q) => $q->where('study_group_id', '!=', $excludeGroupId))
            ->whereHas('studyGroup', fn ($q) => $q->where('center_id', $center->id)->where('status', 'active'))
            ->with(['studyGroup:id,uuid,name_ar,hall_name,center_room_id,center_teacher_id,teacher_name_override'])
            ->get();

        foreach ($normalized as $cand) {
            foreach ($existingSlots as $existing) {
                $existingDay = $existing->day_of_week instanceof DayOfWeek
                    ? $existing->day_of_week->value
                    : strtolower((string) $existing->day_of_week);

                if ($existingDay !== $cand['day']) {
                    continue;
                }

                $exStart = $this->parseTimeToMinutes((string) $existing->start_time);
                $exEnd = $this->parseTimeToMinutes((string) $existing->end_time);
                if ($exStart < 0 || $exEnd < 0) {
                    continue;
                }

                $timeOverlaps = ($cand['startMin'] < $exEnd) && ($cand['endMin'] > $exStart);
                if (! $timeOverlaps) {
                    continue;
                }

                $existingGroup = $existing->studyGroup;
                $existingGroupName = $existingGroup?->name_ar ?? 'مجموعة أخرى';
                $existingRoomId = $existing->center_room_id ?? $existingGroup?->center_room_id;
                $existingHallName = trim((string) ($existing->hall_name ?: $existingGroup?->hall_name));
                $existingHallNorm = $this->normalizeHallKey($existingHallName);

                // 2a. Same Hall/Room Collision
                $sameRoom = ($cand['roomId'] !== null && $existingRoomId !== null && (int) $cand['roomId'] === (int) $existingRoomId)
                    || ($cand['hallNormalized'] !== '' && $existingHallNorm !== '' && $cand['hallNormalized'] === $existingHallNorm);

                if ($sameRoom) {
                    $displayHall = $cand['hallName'] ?: $existingHallName;
                    $conflicts[] = [
                        'slotIndex' => $cand['index'],
                        'type' => 'hall_overlap',
                        'conflictingGroupId' => $existingGroup?->uuid,
                        'conflictingGroupNameAr' => $existingGroupName,
                        'conflictingTime' => "{$existing->start_time} - {$existing->end_time}",
                        'messageAr' => "تعارض قاعات: القاعة «{$displayHall}» محجوزة بالفعل يوم {$cand['dayAr']} ({$existing->start_time} - {$existing->end_time}) لمجموعة «{$existingGroupName}»!",
                    ];
                    break;
                }

                // 2b. Same Teacher Collision
                if (
                    $centerTeacherId !== null
                    && $existingGroup?->center_teacher_id !== null
                    && (int) $existingGroup->center_teacher_id === $centerTeacherId
                ) {
                    $conflicts[] = [
                        'slotIndex' => $cand['index'],
                        'type' => 'teacher_overlap',
                        'conflictingGroupId' => $existingGroup->uuid,
                        'conflictingGroupNameAr' => $existingGroupName,
                        'conflictingTime' => "{$existing->start_time} - {$existing->end_time}",
                        'messageAr' => "تعارض مدرس: المدرس محجوز بالفعل يوم {$cand['dayAr']} ({$existing->start_time} - {$existing->end_time}) في مجموعة «{$existingGroupName}»!",
                    ];
                    break;
                }
            }
        }

        return [
            'hasConflict' => count($conflicts) > 0,
            'conflicts' => $conflicts,
            'messageAr' => $conflicts[0]['messageAr'] ?? '',
        ];
    }

    /**
     * Assert zero conflicts or throw ValidationException with descriptive Arabic error message.
     *
     * @param  array<int, array<string, mixed>>  $candidateSlots
     *
     * @throws ValidationException
     */
    public function assertNoConflicts(
        User $center,
        array $candidateSlots,
        ?int $excludeGroupId = null,
        ?int $centerTeacherId = null
    ): void {
        $result = $this->checkConflicts($center, $candidateSlots, $excludeGroupId, $centerTeacherId);

        if ($result['hasConflict']) {
            throw ValidationException::withMessages([
                'slots' => [$result['messageAr']],
            ]);
        }
    }

    public function normalizeTime(string $time): string
    {
        $parts = explode(':', trim($time));
        if (count($parts) !== 2) {
            return trim($time);
        }

        return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
    }

    public function parseTimeToMinutes(string $time): int
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

    protected function normalizeHallKey(string $hall): string
    {
        return preg_replace('/[\(\)\s\-_]+/u', '', mb_strtolower(trim($hall))) ?? '';
    }

    protected function slotMatchesRoom(GroupScheduleSlot $slot, StudyGroup $group, CenterRoom $room): bool
    {
        if ($slot->center_room_id !== null) {
            return (int) $slot->center_room_id === (int) $room->id;
        }
        if ($group->center_room_id !== null) {
            return (int) $group->center_room_id === (int) $room->id;
        }

        $roomKey = $this->normalizeHallKey((string) $room->name_ar);
        $slotKey = $this->normalizeHallKey((string) ($slot->hall_name ?: $group->hall_name));

        return $roomKey !== '' && $slotKey !== '' && $roomKey === $slotKey;
    }
}
