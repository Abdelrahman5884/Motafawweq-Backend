<?php

namespace App\Services\V1\Center;

use App\Enums\AttendanceStatus;
use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Models\CenterBranch;
use App\Models\CenterProfile;
use App\Models\CenterQueueCompletion;
use App\Models\CenterRoom;
use App\Models\CenterStaff;
use App\Models\CenterTeacher;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\GroupScheduleSlot;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class CenterDashboardService extends BaseService
{
    public function __construct(
        protected CenterBranchService $branchService,
        protected CenterBillingService $billingService
    ) {}

    /**
     * Build the full executive Center Dashboard payload (KPIs, 7-day attendance spline, hall occupancy, live daily queue).
     *
     * @return array<string, mixed>
     */
    public function getDashboard(
        User $center,
        ?string $branchUuid = null,
        string $period = 'month',
        ?string $queueDay = null
    ): array {
        $profile = CenterProfile::firstOrCreate(
            ['user_id' => $center->id],
            [
                'name_ar' => 'السنتر التعليمي',
                'name_en' => 'Educational Center',
                'phone' => $center->phone,
            ]
        );

        $branchId = null;
        if ($branchUuid && $branchUuid !== 'all') {
            $branchId = $this->branchService->findOwnedBranch($center, $branchUuid)->id;
        }

        $centerBranches = CenterBranch::forCenter($center->id)
            ->orderByDesc('is_main')
            ->orderBy('name_ar')
            ->get(['uuid', 'name_ar', 'name_en', 'is_main']);

        $periodStart = match ($period) {
            'today' => now()->startOfDay(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        // 1. Rooms & Capacity
        $rooms = CenterRoom::forCenter($center->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->with('branch:id,uuid,name_ar')
            ->get();

        // 2. Teachers count
        $teachersCount = CenterTeacher::forCenter($center->id)
            ->where('status', 'active')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->count();

        // 3. Groups & Enrolled Students
        $groups = StudyGroup::where('center_id', $center->id)
            ->where('status', 'active')
            ->when($branchId !== null, fn ($q) => $q->where('center_branch_id', $branchId))
            ->with(['scheduleSlots', 'centerTeacher:id,uuid,name_ar', 'centerRoom:id,uuid,name_ar,capacity'])
            ->withCount('enrolledEnrollments as enrolled_count')
            ->get();

        $groupIds = $groups->pluck('id')->all();

        $totalRegisteredStudents = empty($groupIds)
            ? 0
            : (int) GroupEnrollment::whereIn('study_group_id', $groupIds)
                ->where('status', EnrollmentStatus::ENROLLED->value)
                ->distinct('student_id')
                ->count('student_id');

        $activeStudentsCount = empty($groupIds)
            ? 0
            : (int) GroupAttendanceRecord::query()
                ->join('group_attendance_sessions as gas', 'gas.id', '=', 'group_attendance_records.attendance_session_id')
                ->whereIn('group_attendance_records.study_group_id', $groupIds)
                ->whereIn('group_attendance_records.status', [AttendanceStatus::PRESENT->value, AttendanceStatus::LATE->value])
                ->whereDate('gas.session_date', '>=', $periodStart->toDateString())
                ->distinct('group_attendance_records.student_id')
                ->count('group_attendance_records.student_id');

        $activeStudentsPct = $totalRegisteredStudents > 0
            ? round(($activeStudentsCount / $totalRegisteredStudents) * 100, 1)
            : 0.0;

        // 4. Hall Occupancy Breakdown (real seat occupancy from active groups & slots mapped to rooms)
        $roomOccupancy = $rooms->map(function (CenterRoom $room) use ($groups) {
            $roomNameNormalized = mb_strtolower(trim((string) $room->name_ar));
            $roomGroups = $groups->filter(fn (StudyGroup $g) => (int) $g->center_room_id === (int) $room->id
                || mb_strtolower(trim((string) $g->hall_name)) === $roomNameNormalized
                || $g->scheduleSlots->contains(fn ($s) => (int) $s->center_room_id === (int) $room->id)
            );
            $maxGroupEnrolled = (int) ($roomGroups->max('enrolled_count') ?? 0);
            $occupied = min((int) $room->capacity, $maxGroupEnrolled);
            $pct = $room->capacity > 0 ? min(100, (int) round(($occupied / $room->capacity) * 100)) : 0;

            return [
                'id' => $room->uuid,
                'nameAr' => $room->name_ar,
                'nameEn' => $room->name_en ?: $room->name_ar,
                'branchNameAr' => $room->branch?->name_ar ?? 'الفرع الرئيسي',
                'capacity' => (int) $room->capacity,
                'occupied' => $occupied,
                'pct' => $pct,
                'groupsCount' => $roomGroups->count(),
            ];
        })->sortByDesc('pct')->values();

        $totalCenterCapacity = (int) $roomOccupancy->sum('capacity');
        $totalCenterOccupied = (int) $roomOccupancy->sum('occupied');
        $overallOccupancyPct = $totalCenterCapacity > 0
            ? min(100, (int) round(($totalCenterOccupied / $totalCenterCapacity) * 100))
            : 0;

        // 5. Last 7 Days Attendance Spline Chart
        $weeklyAttendance = $this->buildWeeklyAttendanceSeries($groups, $groupIds);

        // 6. Live Chronological Cohorts Queue
        $todayDayKey = strtolower(now()->format('l'));
        $selectedQueueDay = strtolower(trim((string) ($queueDay ?: $todayDayKey)));
        if (! in_array($selectedQueueDay, DayOfWeek::values(), true)) {
            $selectedQueueDay = $todayDayKey;
        }
        $liveQueue = $this->buildLiveQueue($center, $groups, $selectedQueueDay, now()->toDateString());

        $planTier = $this->billingService->resolveActivePlanTier($center);

        return [
            'center' => [
                'nameAr' => $profile->name_ar,
                'nameEn' => $profile->name_en ?: $profile->name_ar,
                'managerName' => $center->name,
                'planTier' => $planTier->value,
                'planNameAr' => $planTier->labelAr(),
                'planNameEn' => $planTier->labelEn(),
                'isVerified' => (bool) $profile->is_verified_center,
            ],
            'branches' => $centerBranches->map(fn (CenterBranch $b) => [
                'id' => $b->uuid,
                'nameAr' => $b->name_ar,
                'nameEn' => $b->name_en ?: $b->name_ar,
                'isMain' => (bool) $b->is_main,
            ])->values()->all(),
            'selectedBranchId' => ($branchUuid && $branchUuid !== 'all') ? $branchUuid : 'all',
            'period' => $period,
            'kpis' => [
                'activeStudents' => $activeStudentsCount,
                'totalRegisteredStudents' => $totalRegisteredStudents,
                'activeStudentsPct' => $activeStudentsPct,
                'teachersCount' => $teachersCount,
                'roomsCount' => $rooms->count(),
                'totalCapacity' => $totalCenterCapacity,
                'overallOccupancyPct' => $overallOccupancyPct,
                'activeGroupsCount' => $groups->count(),
                'targetAttendancePct' => (int) $profile->target_attendance_pct,
                'targetUtilizationPct' => (int) $profile->target_room_utilization_pct,
            ],
            'weeklyAttendance' => $weeklyAttendance,
            'hallOccupancy' => [
                'totalCapacity' => $totalCenterCapacity,
                'totalOccupied' => $totalCenterOccupied,
                'availableSeats' => max(0, $totalCenterCapacity - $totalCenterOccupied),
                'overallOccupancyPct' => $overallOccupancyPct,
                'roomsCount' => $rooms->count(),
                'rooms' => $roomOccupancy->all(),
            ],
            'liveQueue' => $liveQueue,
        ];
    }

    /**
     * Mark a session in today's Live Cohorts Queue as completed (pop from active queue).
     *
     * @return array<string, mixed>
     */
    public function completeQueueItem(
        User $center,
        string $groupUuid,
        ?string $slotUuid = null,
        ?string $queueDate = null
    ): array {
        /** @var StudyGroup|null $group */
        $group = StudyGroup::where('uuid', $groupUuid)->first();
        if (! $group) {
            throw (new ModelNotFoundException)->setModel(StudyGroup::class, [$groupUuid]);
        }
        $group->assertOwnedByCenter($center);

        $slot = null;
        if ($slotUuid !== null && $slotUuid !== '') {
            $slot = GroupScheduleSlot::where('study_group_id', $group->id)
                ->where('uuid', $slotUuid)
                ->first();
            if (! $slot) {
                throw (new ModelNotFoundException)->setModel(GroupScheduleSlot::class, [$slotUuid]);
            }
        }

        $targetDate = $queueDate ? Carbon::parse($queueDate)->toDateString() : now()->toDateString();
        $dayKey = $slot?->day_of_week instanceof DayOfWeek
            ? $slot->day_of_week->value
            : strtolower(Carbon::parse($targetDate)->format('l'));

        $completion = CenterQueueCompletion::updateOrCreate(
            [
                'center_id' => $center->id,
                'study_group_id' => $group->id,
                'schedule_slot_id' => $slot?->id,
                'queue_date' => $targetDate,
            ],
            [
                'day_of_week' => $dayKey,
                'completed_by' => $center->id,
                'completed_at' => now(),
            ]
        );

        $this->logSecurityEvent('center.queue.item_completed', [
            'group_uuid' => $group->uuid,
            'slot_uuid' => $slot?->uuid,
            'queue_date' => $targetDate,
        ], 'info', $completion);

        return [
            'id' => $completion->uuid,
            'groupId' => $group->uuid,
            'slotId' => $slot?->uuid,
            'queueDate' => $targetDate,
            'dayOfWeek' => $dayKey,
            'completedAt' => $completion->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Restore a completed session back into the Live Cohorts Queue.
     */
    public function restoreQueueItem(
        User $center,
        string $groupUuid,
        ?string $slotUuid = null,
        ?string $queueDate = null
    ): bool {
        /** @var StudyGroup|null $group */
        $group = StudyGroup::where('uuid', $groupUuid)->first();
        if (! $group) {
            throw (new ModelNotFoundException)->setModel(StudyGroup::class, [$groupUuid]);
        }
        $group->assertOwnedByCenter($center);

        $targetDate = $queueDate ? Carbon::parse($queueDate)->toDateString() : now()->toDateString();

        $query = CenterQueueCompletion::forCenter($center->id)
            ->where('study_group_id', $group->id)
            ->whereDate('queue_date', $targetDate);

        if ($slotUuid !== null && $slotUuid !== '') {
            $slotId = GroupScheduleSlot::where('study_group_id', $group->id)
                ->where('uuid', $slotUuid)
                ->value('id');
            if (! $slotId) {
                throw (new ModelNotFoundException)->setModel(GroupScheduleSlot::class, [$slotUuid]);
            }
            $query->where('schedule_slot_id', $slotId);
        }

        $query->delete();

        $this->logSecurityEvent('center.queue.item_restored', [
            'group_uuid' => $group->uuid,
            'slot_uuid' => $slotUuid,
            'queue_date' => $targetDate,
        ], 'info', $group);

        return true;
    }

    /**
     * Reset all queue completions for a specific day/date.
     */
    public function resetQueue(User $center, ?string $day = null, ?string $queueDate = null): int
    {
        $targetDate = $queueDate ? Carbon::parse($queueDate)->toDateString() : now()->toDateString();

        $query = CenterQueueCompletion::forCenter($center->id)
            ->whereDate('queue_date', $targetDate);

        if ($day && in_array(strtolower($day), DayOfWeek::values(), true)) {
            $query->where('day_of_week', strtolower($day));
        }

        $deleted = $query->delete();

        $this->logSecurityEvent('center.queue.reset', [
            'queue_date' => $targetDate,
            'day' => $day,
            'reset_count' => $deleted,
        ], 'info');

        return $deleted;
    }

    /**
     * Executive Administrative Report (Zero Financials — operational KPIs, branch benchmarks, attendance health).
     *
     * @return array<string, mixed>
     */
    public function getExecutiveReport(User $center, string $period = 'weekly'): array
    {
        $profile = CenterProfile::where('user_id', $center->id)->first();
        $branches = $this->branchService->listBranches($center);
        $roomsCount = CenterRoom::forCenter($center->id)->count();
        $totalCapacity = (int) CenterRoom::forCenter($center->id)->sum('capacity');
        $teachersCount = CenterTeacher::forCenter($center->id)->where('status', 'active')->count();
        $staffCount = CenterStaff::forCenter($center->id)->where('status', 'active')->count();
        $groupsCount = StudyGroup::where('center_id', $center->id)->where('status', 'active')->count();

        $since = match ($period) {
            'monthly' => now()->subDays(30),
            'term' => now()->subDays(150),
            default => now()->subDays(7),
        };

        $groupIds = StudyGroup::where('center_id', $center->id)->pluck('id')->all();
        $totalStudents = empty($groupIds)
            ? 0
            : (int) GroupEnrollment::whereIn('study_group_id', $groupIds)
                ->where('status', EnrollmentStatus::ENROLLED->value)
                ->distinct('student_id')
                ->count('student_id');

        $sessionsInPeriod = empty($groupIds)
            ? collect()
            : GroupAttendanceSession::whereIn('study_group_id', $groupIds)
                ->whereDate('session_date', '>=', $since->toDateString())
                ->get();

        $avgAttendanceRate = $sessionsInPeriod->isNotEmpty()
            ? round((float) $sessionsInPeriod->avg('attendance_rate'), 1)
            : 0.0;

        return [
            'reportType' => $period,
            'generatedAt' => now()->toIso8601String(),
            'centerNameAr' => $profile?->name_ar ?: $center->name,
            'centerNameEn' => $profile?->name_en ?: $center->name,
            'summary' => [
                'branchesCount' => count($branches),
                'roomsCount' => $roomsCount,
                'totalCapacity' => $totalCapacity,
                'teachersCount' => $teachersCount,
                'staffCount' => $staffCount,
                'activeGroupsCount' => $groupsCount,
                'totalStudents' => $totalStudents,
                'sessionsHeld' => $sessionsInPeriod->count(),
                'avgAttendanceRate' => $avgAttendanceRate,
            ],
            'branches' => $branches,
        ];
    }

    /**
     * Build 7-day attendance spline series from real center attendance records and scheduled slot hours.
     *
     * @param  Collection<int, StudyGroup>  $groups
     * @param  array<int, int>  $groupIds
     * @return array<string, mixed>
     */
    protected function buildWeeklyAttendanceSeries($groups, array $groupIds): array
    {
        $dayMapAr = [
            'saturday' => ['ar' => 'السبت', 'en' => 'Sat'],
            'sunday' => ['ar' => 'الأحد', 'en' => 'Sun'],
            'monday' => ['ar' => 'الإثنين', 'en' => 'Mon'],
            'tuesday' => ['ar' => 'الثلاثاء', 'en' => 'Tue'],
            'wednesday' => ['ar' => 'الأربعاء', 'en' => 'Wed'],
            'thursday' => ['ar' => 'الخميس', 'en' => 'Thu'],
            'friday' => ['ar' => 'الجمعة', 'en' => 'Fri'],
        ];

        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $dates[] = [
                'date' => $d->toDateString(),
                'dayKey' => strtolower($d->format('l')),
                'isToday' => $i === 0,
            ];
        }

        $startDate = $dates[0]['date'];
        $sessionsByDate = empty($groupIds)
            ? collect()
            : GroupAttendanceSession::whereIn('study_group_id', $groupIds)
                ->whereDate('session_date', '>=', $startDate)
                ->with('group:id,subject_ar,name_ar')
                ->get()
                ->groupBy(fn ($s) => $s->session_date?->toDateString());

        $points = [];
        $maxStudents = 0;
        $peakIdx = null;
        $totalStudentsSum = 0;
        $totalHoursSum = 0.0;
        $rateSum = 0.0;
        $rateCount = 0;

        foreach ($dates as $idx => $dInfo) {
            $dayKey = $dInfo['dayKey'];
            $daySessions = $sessionsByDate->get($dInfo['date'], collect());
            $studentsPresent = (int) $daySessions->sum('present_count');

            // Compute teaching hours scheduled on this weekday across center groups
            $hours = 0.0;
            $topSessionGroup = $daySessions->sortByDesc('present_count')->first()?->group;
            $topTopic = $topSessionGroup
                ? trim($topSessionGroup->subject_ar.' — '.$topSessionGroup->name_ar)
                : '';

            foreach ($groups as $g) {
                foreach ($g->scheduleSlots as $slot) {
                    $sDay = $slot->day_of_week instanceof DayOfWeek ? $slot->day_of_week->value : strtolower((string) $slot->day_of_week);
                    if ($sDay === $dayKey) {
                        $sParts = explode(':', (string) $slot->start_time);
                        $eParts = explode(':', (string) $slot->end_time);
                        if (count($sParts) >= 2 && count($eParts) >= 2) {
                            $durMin = max(0, (((int) $eParts[0] * 60) + (int) $eParts[1]) - (((int) $sParts[0] * 60) + (int) $sParts[1]));
                            $hours += $durMin / 60;
                        }
                        if ($topTopic === '') {
                            $topTopic = $g->subject_ar.' — '.$g->name_ar;
                        }
                    }
                }
            }

            if ($daySessions->isNotEmpty()) {
                $rateSum += (float) $daySessions->avg('attendance_rate');
                $rateCount++;
            }

            $hoursRounded = round($hours, 1);
            $totalStudentsSum += $studentsPresent;
            $totalHoursSum += $hoursRounded;

            if ($studentsPresent > $maxStudents) {
                $maxStudents = $studentsPresent;
                $peakIdx = $idx;
            }

            $labels = $dayMapAr[$dayKey] ?? ['ar' => $dayKey, 'en' => ucfirst($dayKey)];
            $points[] = [
                'date' => $dInfo['date'],
                'dayKey' => $dayKey,
                'dayAr' => $labels['ar'],
                'dayEn' => $labels['en'],
                'students' => $studentsPresent,
                'hours' => $hoursRounded,
                'isToday' => $dInfo['isToday'],
                'isPeak' => false,
                'topTopic' => $topTopic,
            ];
        }

        if ($peakIdx !== null && isset($points[$peakIdx])) {
            $points[$peakIdx]['isPeak'] = true;
        }

        return [
            'days' => $points,
            'peakDay' => ($peakIdx !== null && isset($points[$peakIdx])) ? $points[$peakIdx] : null,
            'dailyAverage' => count($points) > 0 ? (int) round($totalStudentsSum / count($points)) : 0,
            'commitmentRate' => $rateCount > 0 ? round($rateSum / $rateCount, 1) : 0.0,
            'totalWeeklyHours' => round($totalHoursSum, 1),
        ];
    }

    /**
     * Build the chronological Live Cohorts Queue for a weekday, separated into active and completed items.
     *
     * @param  Collection<int, StudyGroup>  $groups
     * @return array<string, mixed>
     */
    protected function buildLiveQueue(User $center, $groups, string $dayKey, string $queueDate): array
    {
        $completions = CenterQueueCompletion::forCenter($center->id)
            ->whereDate('queue_date', $queueDate)
            ->where('day_of_week', $dayKey)
            ->get();

        $completedKeys = [];
        foreach ($completions as $c) {
            $completedKeys[$c->study_group_id.'_'.($c->schedule_slot_id ?? 0)] = $c->completed_at?->toIso8601String();
        }

        $items = [];
        foreach ($groups as $group) {
            foreach ($group->scheduleSlots as $slot) {
                $sDay = $slot->day_of_week instanceof DayOfWeek ? $slot->day_of_week->value : strtolower((string) $slot->day_of_week);
                if ($sDay !== $dayKey) {
                    continue;
                }

                $lookupKey = $group->id.'_'.$slot->id;
                $isCompleted = array_key_exists($lookupKey, $completedKeys);

                $durationMinutes = 120;
                $sParts = explode(':', (string) $slot->start_time);
                $eParts = explode(':', (string) $slot->end_time);
                if (count($sParts) >= 2 && count($eParts) >= 2) {
                    $diff = (((int) $eParts[0] * 60) + (int) $eParts[1]) - (((int) $sParts[0] * 60) + (int) $sParts[1]);
                    if ($diff > 0) {
                        $durationMinutes = $diff;
                    }
                }

                $items[] = [
                    'uniqueId' => "{$group->uuid}_{$slot->uuid}",
                    'groupId' => $group->uuid,
                    'slotId' => $slot->uuid,
                    'groupNameAr' => $group->name_ar,
                    'groupNameEn' => $group->name_en ?: $group->name_ar,
                    'subjectAr' => $group->subject_ar,
                    'gradeAr' => $group->grade_ar,
                    'teacherNameAr' => $group->centerTeacher?->name_ar ?: ($group->teacher_name_override ?: 'معلم معتمد'),
                    'hall' => $slot->hall_name ?: ($group->hall_name ?: 'القاعة الرئيسية'),
                    'startTime' => $slot->start_time,
                    'endTime' => $slot->end_time,
                    'durationMinutes' => $durationMinutes,
                    'studentCount' => (int) ($group->enrolled_count ?? 0),
                    'capacity' => (int) ($group->centerRoom?->capacity ?: $group->max_students),
                    'priceEgp' => (float) $group->price_egp,
                    'isCompleted' => $isCompleted,
                    'completedAt' => $completedKeys[$lookupKey] ?? null,
                ];
            }
        }

        usort($items, fn ($a, $b) => strcmp($a['startTime'], $b['startTime']));

        $activeQueue = array_values(array_filter($items, fn ($i) => ! $i['isCompleted']));
        $completedQueue = array_values(array_filter($items, fn ($i) => $i['isCompleted']));

        return [
            'day' => $dayKey,
            'queueDate' => $queueDate,
            'totalCount' => count($items),
            'activeCount' => count($activeQueue),
            'completedCount' => count($completedQueue),
            'completedIds' => array_values(array_map(fn ($i) => $i['uniqueId'], $completedQueue)),
            'currentActiveItem' => $activeQueue[0] ?? null,
            'upcomingQueueItems' => array_slice($activeQueue, 1),
            'activeQueue' => $activeQueue,
            'completedQueue' => $completedQueue,
            'allItems' => $items,
        ];
    }
}
