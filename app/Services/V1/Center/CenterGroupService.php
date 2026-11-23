<?php

namespace App\Services\V1\Center;

use App\Enums\AcademicStatus;
use App\Enums\AttendanceStatus;
use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Enums\JoinMethod;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CenterBranch;
use App\Models\CenterProfile;
use App\Models\CenterRoom;
use App\Models\CenterTeacher;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\GroupScheduleSlot;
use App\Models\StudentProfile;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CenterGroupService extends BaseService
{
    public function __construct(
        protected CenterBranchService $branchService,
        protected CenterRoomService $roomService,
        protected CenterTeacherService $teacherService,
        protected CenterScheduleService $scheduleService,
        protected CenterBillingService $billingService
    ) {}

    /**
     * List all study groups owned by this center with filter support & overview summary metrics.
     *
     * @return array<string, mixed>
     */
    public function listGroups(User $center, array $filters = []): array
    {
        $query = StudyGroup::where('center_id', $center->id)
            ->with([
                'scheduleSlots.centerRoom:id,uuid,name_ar',
                'centerBranch:id,uuid,name_ar',
                'centerRoom:id,uuid,name_ar,capacity',
                'centerTeacher:id,uuid,name_ar,subject_ar',
            ])
            ->withCount([
                'enrolledEnrollments as enrolled_count',
                'pendingEnrollments as pending_count',
            ])
            ->orderByDesc('created_at');

        if (! empty($filters['branch_id']) && $filters['branch_id'] !== 'all') {
            $branch = $this->branchService->findOwnedBranch($center, (string) $filters['branch_id']);
            $query->where('center_branch_id', $branch->id);
        }

        if (! empty($filters['room_id']) && $filters['room_id'] !== 'all') {
            $room = $this->roomService->findOwnedRoom($center, (string) $filters['room_id']);
            $query->where('center_room_id', $room->id);
        }

        if (! empty($filters['teacher_id']) && $filters['teacher_id'] !== 'all') {
            $teacher = $this->teacherService->findOwnedTeacher($center, (string) $filters['teacher_id']);
            $query->where('center_teacher_id', $teacher->id);
        }

        if (! empty($filters['subject']) && strtoupper((string) $filters['subject']) !== 'ALL') {
            $query->where('subject_ar', trim((string) $filters['subject']));
        }

        if (! empty($filters['status']) && in_array($filters['status'], ['active', 'archived'], true)) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $q = trim((string) $filters['search']);
            $query->where(function ($sub) use ($q) {
                $sub->where('name_ar', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('subject_ar', 'like', "%{$q}%")
                    ->orWhere('teacher_name_override', 'like', "%{$q}%")
                    ->orWhere('join_code', 'like', "%{$q}%");
            });
        }

        $groups = $query->get();

        $totalRoomsCapacity = (int) CenterRoom::forCenter($center->id)->sum('capacity');
        $totalRoomsCount = CenterRoom::forCenter($center->id)->count();
        $occupiedHalls = $groups->pluck('center_room_id')->filter()->unique()->count();
        if ($occupiedHalls === 0) {
            $occupiedHalls = min($totalRoomsCount, $groups->pluck('hall_name')->filter()->unique()->count());
        }

        $groupIds = $groups->pluck('id')->all();
        $averageAttendanceRate = 0.0;
        if (! empty($groupIds)) {
            $hasSessions = GroupAttendanceSession::whereIn('study_group_id', $groupIds)->exists();
            if ($hasSessions) {
                $avg = GroupEnrollment::whereIn('study_group_id', $groupIds)
                    ->where('status', EnrollmentStatus::ENROLLED->value)
                    ->avg('attendance_rate');
                $averageAttendanceRate = $avg !== null ? round((float) $avg, 1) : 0.0;
            }
        }

        return [
            'groups' => $groups->map(fn (StudyGroup $g) => $this->formatGroup($g))->values()->all(),
            'summary' => [
                'totalGroups' => $groups->count(),
                'totalEnrolledStudents' => (int) $groups->sum('enrolled_count'),
                'pendingRequestsCount' => (int) $groups->sum('pending_count'),
                'averageAttendanceRate' => $averageAttendanceRate,
                'hallsInUse' => $occupiedHalls,
                'totalRoomsCount' => $totalRoomsCount,
                'totalCenterCapacity' => $totalRoomsCapacity,
                'subjects' => $groups->pluck('subject_ar')->filter()->unique()->values()->all(),
                'teachers' => $groups->map(fn ($g) => $g->centerTeacher?->name_ar ?: $g->teacher_name_override)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Find a study group by UUID and enforce strict center ownership.
     */
    public function findOwnedGroup(User $center, string $uuid): StudyGroup
    {
        /** @var StudyGroup|null $group */
        $group = StudyGroup::where('uuid', $uuid)
            ->with([
                'scheduleSlots.centerRoom:id,uuid,name_ar',
                'centerBranch:id,uuid,name_ar',
                'centerRoom:id,uuid,name_ar,capacity',
                'centerTeacher:id,uuid,name_ar,subject_ar',
            ])
            ->withCount([
                'enrolledEnrollments as enrolled_count',
                'pendingEnrollments as pending_count',
            ])
            ->first();

        if (! $group) {
            throw (new ModelNotFoundException)->setModel(StudyGroup::class, [$uuid]);
        }

        $group->assertOwnedByCenter($center);

        return $group;
    }

    /**
     * Create a new study group for the center with multi-session slots & zero-conflict enforcement.
     *
     * @return array<string, mixed>
     */
    public function createGroup(User $center, array $data): array
    {
        $branch = $this->resolveOptionalOwnedBranch($center, $data['branch_id'] ?? $data['branchId'] ?? null);
        $room = $this->resolveOptionalOwnedRoom($center, $data['room_id'] ?? $data['roomId'] ?? null);
        $centerTeacher = $this->resolveOptionalOwnedTeacher($center, $data['center_teacher_id'] ?? $data['teacher_id'] ?? $data['teacherId'] ?? null);

        if (! $branch && $room?->branch_id) {
            $branch = CenterBranch::find($room->branch_id);
        }

        $profile = CenterProfile::where('user_id', $center->id)->first();
        $centerName = $branch?->name_ar ?: ($profile?->name_ar ?: 'السنتر التعليمي');
        $hallName = $room?->name_ar ?: trim(strip_tags((string) ($data['hall_name'] ?? $data['hallName'] ?? 'القاعة الرئيسية')));
        $teacherNameAr = $centerTeacher?->name_ar
            ?: trim(strip_tags((string) ($data['teacher_name_ar'] ?? $data['teacherNameAr'] ?? 'معلم معتمد')));

        $rawSlots = $data['schedule_slots'] ?? $data['scheduleSlots'] ?? $data['slots'] ?? [];
        $preparedSlots = $this->prepareSlots($center, is_array($rawSlots) ? $rawSlots : [], $room, $hallName);

        $group = $this->transaction(function () use ($center, $branch, $room, $centerTeacher, $centerName, $hallName, $teacherNameAr, $preparedSlots, $data) {
            if (! empty($preparedSlots)) {
                $this->scheduleService->assertNoConflicts($center, $preparedSlots, null, $centerTeacher?->id);
            }

            $nameAr = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? 'مجموعة دراسية جديدة')));
            $nameEn = trim(strip_tags((string) ($data['name_en'] ?? $data['nameEn'] ?? $nameAr)));
            $subjectAr = trim(strip_tags((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? ($centerTeacher?->subject_ar ?: 'مادة دراسية'))));
            $subjectEn = trim(strip_tags((string) ($data['subject_en'] ?? $data['subjectEn'] ?? $subjectAr)));
            $gradeAr = trim(strip_tags((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? 'الصف الثالث الثانوي')));
            $gradeEn = trim(strip_tags((string) ($data['grade_en'] ?? $data['gradeEn'] ?? '3rd Secondary')));
            $scheduleAr = trim(strip_tags((string) ($data['schedule_ar'] ?? $data['scheduleAr'] ?? $this->buildScheduleSummary($preparedSlots))));
            $maxStudents = max(1, min(1000, (int) ($data['max_students'] ?? $data['maxStudents'] ?? ($room?->capacity ?: 50))));
            $priceEgp = max(0.0, (float) ($data['price_egp'] ?? $data['priceEgp'] ?? 0.0));

            $prefix = ! empty($subjectAr) ? Str::substr($subjectAr, 0, 3) : 'CTR';
            $joinCode = StudyGroup::generateUniqueJoinCode($prefix);

            /** @var StudyGroup $created */
            $created = StudyGroup::create([
                'teacher_id' => $center->id,
                'center_id' => $center->id,
                'center_branch_id' => $branch?->id,
                'center_room_id' => $room?->id,
                'center_teacher_id' => $centerTeacher?->id,
                'teacher_name_override' => $teacherNameAr,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'subject_ar' => $subjectAr,
                'subject_en' => $subjectEn,
                'grade_ar' => $gradeAr,
                'grade_en' => $gradeEn,
                'center_name' => $centerName,
                'hall_name' => $hallName,
                'schedule_ar' => $scheduleAr,
                'schedule_en' => $scheduleAr,
                'max_students' => $maxStudents,
                'price_egp' => $priceEgp,
                'join_code' => $joinCode,
                'status' => 'active',
            ]);

            foreach ($preparedSlots as $slot) {
                GroupScheduleSlot::create([
                    'study_group_id' => $created->id,
                    'teacher_id' => $center->id,
                    'day_of_week' => $slot['day'],
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'hall_name' => $slot['hall_name'],
                    'center_room_id' => $slot['room_id'],
                ]);
            }

            $this->logSecurityEvent('center.group.created', [
                'group_uuid' => $created->uuid,
                'join_code' => $created->join_code,
                'hall_name' => $created->hall_name,
                'slots_count' => count($preparedSlots),
            ], 'info', $created);

            return $created->load([
                'scheduleSlots.centerRoom:id,uuid,name_ar',
                'centerBranch:id,uuid,name_ar',
                'centerRoom:id,uuid,name_ar,capacity',
                'centerTeacher:id,uuid,name_ar,subject_ar',
            ])->loadCount([
                'enrolledEnrollments as enrolled_count',
                'pendingEnrollments as pending_count',
            ]);
        });

        return $this->formatGroup($group);
    }

    /**
     * Update an existing center study group and synchronize its schedule slots without conflicts.
     *
     * @return array<string, mixed>
     */
    public function updateGroup(User $center, string $uuid, array $data): array
    {
        $group = $this->findOwnedGroup($center, $uuid);

        $hasBranch = array_key_exists('branch_id', $data) || array_key_exists('branchId', $data);
        $hasRoom = array_key_exists('room_id', $data) || array_key_exists('roomId', $data);
        $hasTeacher = array_key_exists('center_teacher_id', $data) || array_key_exists('teacher_id', $data) || array_key_exists('teacherId', $data);

        $branch = $hasBranch ? $this->resolveOptionalOwnedBranch($center, $data['branch_id'] ?? $data['branchId']) : $group->centerBranch;
        $room = $hasRoom ? $this->resolveOptionalOwnedRoom($center, $data['room_id'] ?? $data['roomId']) : $group->centerRoom;
        $centerTeacher = $hasTeacher
            ? $this->resolveOptionalOwnedTeacher($center, $data['center_teacher_id'] ?? $data['teacher_id'] ?? $data['teacherId'])
            : $group->centerTeacher;

        $rawSlots = $data['schedule_slots'] ?? $data['scheduleSlots'] ?? $data['slots'] ?? null;
        $preparedSlots = is_array($rawSlots)
            ? $this->prepareSlots($center, $rawSlots, $room, $room?->name_ar ?: ($data['hall_name'] ?? $group->hall_name))
            : null;

        $updated = $this->transaction(function () use ($center, $group, $branch, $room, $centerTeacher, $preparedSlots, $data, $hasBranch, $hasRoom, $hasTeacher) {
            if ($preparedSlots !== null) {
                $this->scheduleService->assertNoConflicts($center, $preparedSlots, $group->id, $centerTeacher?->id);
            }

            $payload = [];
            if ($hasBranch) {
                $payload['center_branch_id'] = $branch?->id;
                if ($branch) {
                    $payload['center_name'] = $branch->name_ar;
                }
            }
            if ($hasRoom) {
                $payload['center_room_id'] = $room?->id;
                if ($room) {
                    $payload['hall_name'] = $room->name_ar;
                }
            }
            if ($hasTeacher) {
                $payload['center_teacher_id'] = $centerTeacher?->id;
                if ($centerTeacher) {
                    $payload['teacher_name_override'] = $centerTeacher->name_ar;
                }
            }
            if (array_key_exists('teacher_name_ar', $data) || array_key_exists('teacherNameAr', $data)) {
                $payload['teacher_name_override'] = trim(strip_tags((string) ($data['teacher_name_ar'] ?? $data['teacherNameAr'])));
            }
            if (array_key_exists('name_ar', $data) || array_key_exists('nameAr', $data)) {
                $payload['name_ar'] = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'])));
            }
            if (array_key_exists('name_en', $data) || array_key_exists('nameEn', $data)) {
                $payload['name_en'] = trim(strip_tags((string) ($data['name_en'] ?? $data['nameEn'])));
            }
            if (array_key_exists('subject_ar', $data) || array_key_exists('subjectAr', $data)) {
                $payload['subject_ar'] = trim(strip_tags((string) ($data['subject_ar'] ?? $data['subjectAr'])));
            }
            if (array_key_exists('grade_ar', $data) || array_key_exists('gradeAr', $data)) {
                $payload['grade_ar'] = trim(strip_tags((string) ($data['grade_ar'] ?? $data['gradeAr'])));
            }
            if (array_key_exists('hall_name', $data) || array_key_exists('hallName', $data)) {
                $payload['hall_name'] = trim(strip_tags((string) ($data['hall_name'] ?? $data['hallName'])));
            }
            if (array_key_exists('max_students', $data) || array_key_exists('maxStudents', $data)) {
                $payload['max_students'] = max(1, min(1000, (int) ($data['max_students'] ?? $data['maxStudents'])));
            }
            if (array_key_exists('price_egp', $data) || array_key_exists('priceEgp', $data)) {
                $payload['price_egp'] = max(0.0, (float) ($data['price_egp'] ?? $data['priceEgp']));
            }
            if (array_key_exists('status', $data) && in_array($data['status'], ['active', 'archived'], true)) {
                $payload['status'] = $data['status'];
            }
            if ($preparedSlots !== null) {
                $payload['schedule_ar'] = trim(strip_tags((string) ($data['schedule_ar'] ?? $data['scheduleAr'] ?? $this->buildScheduleSummary($preparedSlots))));
            } elseif (array_key_exists('schedule_ar', $data) || array_key_exists('scheduleAr', $data)) {
                $payload['schedule_ar'] = trim(strip_tags((string) ($data['schedule_ar'] ?? $data['scheduleAr'])));
            }

            if (! empty($payload)) {
                $group->update($payload);
            }

            if ($preparedSlots !== null) {
                GroupScheduleSlot::where('study_group_id', $group->id)->delete();
                foreach ($preparedSlots as $slot) {
                    GroupScheduleSlot::create([
                        'study_group_id' => $group->id,
                        'teacher_id' => $center->id,
                        'day_of_week' => $slot['day'],
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'hall_name' => $slot['hall_name'],
                        'center_room_id' => $slot['room_id'],
                    ]);
                }
            }

            $this->logSecurityEvent('center.group.updated', [
                'group_uuid' => $group->uuid,
                'changes' => array_keys($payload),
            ], 'info', $group);

            return $group->fresh([
                'scheduleSlots.centerRoom:id,uuid,name_ar',
                'centerBranch:id,uuid,name_ar',
                'centerRoom:id,uuid,name_ar,capacity',
                'centerTeacher:id,uuid,name_ar,subject_ar',
            ])?->loadCount([
                'enrolledEnrollments as enrolled_count',
                'pendingEnrollments as pending_count',
            ]) ?? $group;
        });

        return $this->formatGroup($updated);
    }

    /**
     * Soft-delete a center study group.
     */
    public function deleteGroup(User $center, string $uuid): bool
    {
        $group = $this->findOwnedGroup($center, $uuid);

        return $this->transaction(function () use ($group) {
            $gUuid = $group->uuid;
            $group->delete();

            $this->logSecurityEvent('center.group.deleted', [
                'group_uuid' => $gUuid,
            ], 'warning', $group);

            return true;
        });
    }

    /**
     * Regenerate the group's unique join code (anti-leakage).
     *
     * @return array<string, mixed>
     */
    public function regenerateJoinCode(User $center, string $uuid): array
    {
        $group = $this->findOwnedGroup($center, $uuid);
        $prefix = ! empty($group->subject_ar) ? Str::substr($group->subject_ar, 0, 3) : 'CTR';
        $newCode = StudyGroup::generateUniqueJoinCode($prefix);

        $group->update(['join_code' => $newCode]);

        $this->logSecurityEvent('center.group.code_regenerated', [
            'group_uuid' => $group->uuid,
            'new_code' => $newCode,
        ], 'info', $group);

        return $this->formatGroup($group->fresh([
            'scheduleSlots.centerRoom:id,uuid,name_ar',
            'centerBranch:id,uuid,name_ar',
            'centerRoom:id,uuid,name_ar,capacity',
            'centerTeacher:id,uuid,name_ar,subject_ar',
        ])?->loadCount([
            'enrolledEnrollments as enrolled_count',
            'pendingEnrollments as pending_count',
        ]) ?? $group);
    }

    /**
     * Retrieve full group roster (enrolled & pending) with today's attendance status & barcode/passcode.
     *
     * @return array<string, mixed>
     */
    public function getGroupRoster(
        User $center,
        string $groupUuid,
        ?string $search = null,
        ?string $statusFilter = null,
        ?string $attendanceDate = null
    ): array {
        $group = $this->findOwnedGroup($center, $groupUuid);
        $targetDate = $attendanceDate ?: now()->toDateString();

        $enrolledQuery = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->with(['student:id,name,email,phone,student_code,avatar', 'student.studentProfile:student_id,grade_ar'])
            ->orderByDesc('enrolled_at');

        $pendingQuery = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::PENDING->value)
            ->with(['student:id,name,email,phone,student_code,avatar'])
            ->orderByDesc('requested_at');

        if ($search && trim($search) !== '') {
            $q = trim($search);
            $filterFn = function ($sub) use ($q) {
                $sub->where('student_name_override', 'like', "%{$q}%")
                    ->orWhere('student_phone_override', 'like', "%{$q}%")
                    ->orWhere('student_barcode', 'like', "%{$q}%")
                    ->orWhere('student_passcode', 'like', "%{$q}%")
                    ->orWhereHas('student', fn ($sq) => $sq->where('name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('student_code', 'like', "%{$q}%"));
            };
            $enrolledQuery->where($filterFn);
            $pendingQuery->where($filterFn);
        }

        $enrolled = $enrolledQuery->get();
        $pending = $pendingQuery->get();

        $dateSession = GroupAttendanceSession::where('study_group_id', $group->id)
            ->whereDate('session_date', $targetDate)
            ->first();

        $recordsByEnrollment = $dateSession
            ? GroupAttendanceRecord::where('attendance_session_id', $dateSession->id)
                ->get(['group_enrollment_id', 'status', 'scanned_at', 'recorded_method'])
                ->keyBy('group_enrollment_id')
            : collect();

        $presentCount = 0;
        $absentCount = 0;

        $studentsList = $enrolled->map(function (GroupEnrollment $e) use ($recordsByEnrollment, &$presentCount, &$absentCount, $group) {
            $rec = $recordsByEnrollment->get($e->id);
            $todayStatus = $rec
                ? ($rec->status instanceof AttendanceStatus ? $rec->status->value : (string) $rec->status)
                : 'absent';

            $isPresent = in_array($todayStatus, [AttendanceStatus::PRESENT->value, AttendanceStatus::LATE->value], true);
            if ($isPresent) {
                $presentCount++;
            } else {
                $absentCount++;
            }

            return $this->formatEnrollment($e, $group, $todayStatus, $rec?->scanned_at?->toIso8601String());
        });

        if ($statusFilter === 'present') {
            $studentsList = $studentsList->filter(fn ($s) => in_array($s['todayStatus'], ['present', 'late'], true));
        } elseif ($statusFilter === 'absent') {
            $studentsList = $studentsList->filter(fn ($s) => ! in_array($s['todayStatus'], ['present', 'late'], true));
        }

        $totalEnrolled = $enrolled->count();
        $attendanceRate = $totalEnrolled > 0 ? (int) round(($presentCount / $totalEnrolled) * 100) : 0;

        return [
            'group' => $this->formatGroup($group),
            'enrolled' => $studentsList->values()->all(),
            'pending' => $pending->map(function (GroupEnrollment $e) {
                $methodEnum = $e->join_method instanceof JoinMethod
                    ? $e->join_method
                    : JoinMethod::tryFrom((string) $e->join_method);
                $methodValue = $methodEnum ? $methodEnum->value : (string) $e->join_method;

                return [
                    'id' => $e->uuid,
                    'enrollmentId' => $e->uuid,
                    'studentId' => $e->student_id,
                    'studentCode' => $e->student?->student_code,
                    'nameAr' => $e->student_name_override ?: ($e->student?->name ?? 'طالب'),
                    'phone' => $e->student_phone_override ?: ($e->student?->phone ?? ''),
                    'email' => $e->student?->email ?? '',
                    'avatar' => $e->student?->avatar ?: null,
                    'parentNameAr' => $e->parent_name_ar,
                    'parentPhone' => $e->parent_phone,
                    'joinMethod' => $methodValue,
                    'method' => $methodEnum ? $methodEnum->labelAr() : 'كود الانضمام',
                    'isTeacherInvite' => $methodValue === JoinMethod::TEACHER_INVITE->value,
                    'requestedAt' => ($e->requested_at ?? $e->created_at)?->toIso8601String(),
                ];
            })->values()->all(),
            'stats' => [
                'enrolledCount' => $totalEnrolled,
                'pendingCount' => $pending->count(),
                'maxStudents' => (int) $group->max_students,
                'presentCount' => $presentCount,
                'absentCount' => $absentCount,
                'groupAttendanceRate' => $attendanceRate,
                'attendanceDate' => $targetDate,
            ],
        ];
    }

    /**
     * Search active platform students to invite into a center group.
     * When query is empty, returns the most recent registered platform students.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchPlatformStudents(User $center, string $query = '', int $limit = 20): array
    {
        $clean = trim($query);
        $safeLimit = min(40, max(1, $limit));

        $builder = User::query()
            ->where('role', UserRole::STUDENT->value)
            ->where('status', UserStatus::ACTIVE->value)
            ->where('email', 'not like', '%@student.motafawweq.local')
            ->with(['parents:id,name,phone', 'studentProfile:student_id,grade_ar,school_name,parent_name,parent_phone']);

        if ($clean !== '') {
            $builder->where(function ($q) use ($clean) {
                $q->where('name', 'like', "%{$clean}%")
                    ->orWhere('phone', 'like', "%{$clean}%")
                    ->orWhere('email', 'like', "%{$clean}%")
                    ->orWhere('student_code', 'like', "%{$clean}%");
            });
        }

        return $builder
            ->orderByDesc('id')
            ->limit($safeLimit)
            ->get()
            ->map(function (User $s) {
                $parent = $s->relationLoaded('parents') ? $s->parents->first() : null;
                $profile = $s->studentProfile;

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'nameAr' => $s->name,
                    'email' => $s->email,
                    'phone' => $s->phone ?? '',
                    'studentCode' => $s->student_code,
                    'gradeAr' => $profile?->grade_ar ?? 'الصف الثالث الثانوي',
                    'schoolAr' => $profile?->school_name ?? '',
                    'parentNameAr' => $profile?->parent_name ?: ($parent?->name ?? ''),
                    'parentPhone' => $profile?->parent_phone ?: ($parent?->phone ?? ''),
                    'avatar' => $s->avatar ?: null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Invite an existing platform student (creates a pending invitation for the student to accept)
     * OR register & directly enroll a walk-in non-platform student into a center group.
     *
     * @return array<string, mixed>
     */
    public function enrollStudent(User $center, string $groupUuid, array $data): array
    {
        $group = $this->findOwnedGroup($center, $groupUuid);

        $currentEnrolled = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->count();

        if ($currentEnrolled >= $group->max_students) {
            throw ValidationException::withMessages([
                'group' => [__('messages.group_capacity_reached', ['max' => $group->max_students])],
            ]);
        }

        $this->billingService->assertUsersQuotaAvailable($center, 1);

        $isPlatformInvite = false;

        $enrollment = $this->transaction(function () use ($group, $data, &$isPlatformInvite) {
            [$studentUser, $isExistingPlatformStudent] = $this->resolveOrProvisionStudentWithOrigin($data);
            $isPlatformInvite = $isExistingPlatformStudent;

            /** @var GroupEnrollment|null $existing */
            $existing = GroupEnrollment::withTrashed()
                ->where('study_group_id', $group->id)
                ->where('student_id', $studentUser->id)
                ->lockForUpdate()
                ->first();

            if ($existing && ! $existing->trashed()) {
                if ($existing->status === EnrollmentStatus::ENROLLED) {
                    throw ValidationException::withMessages([
                        'student' => [__('messages.student_already_enrolled')],
                    ]);
                }
                if ($isPlatformInvite && $existing->status === EnrollmentStatus::PENDING) {
                    throw ValidationException::withMessages([
                        'student' => [__('messages.student_already_pending')],
                    ]);
                }
            }

            $paymentStatus = in_array($data['payment_status'] ?? 'paid', ['paid', 'partial', 'due'], true)
                ? (string) ($data['payment_status'] ?? 'paid')
                : 'paid';
            $dueAmount = $paymentStatus === 'paid' ? 0.0 : max(0.0, (float) ($data['due_amount_egp'] ?? $group->price_egp));

            if ($isPlatformInvite) {
                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update([
                        'status' => EnrollmentStatus::PENDING,
                        'join_method' => JoinMethod::TEACHER_INVITE,
                        'student_barcode' => null,
                        'student_passcode' => null,
                        'enrolled_at' => null,
                        'student_name_override' => trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? $studentUser->name))),
                        'student_phone_override' => trim(strip_tags((string) ($data['phone'] ?? $studentUser->phone ?? ''))),
                        'parent_name_ar' => isset($data['parent_name_ar']) || isset($data['parentNameAr'])
                            ? trim(strip_tags((string) ($data['parent_name_ar'] ?? $data['parentNameAr'])))
                            : $existing->parent_name_ar,
                        'parent_phone' => isset($data['parent_phone']) || isset($data['parentPhone'])
                            ? trim(strip_tags((string) ($data['parent_phone'] ?? $data['parentPhone'])))
                            : $existing->parent_phone,
                        'payment_status' => $paymentStatus,
                        'due_amount_egp' => $dueAmount,
                        'payment_due_since' => $dueAmount > 0 ? now()->toDateString() : null,
                        'requested_at' => now(),
                    ]);
                    $enr = $existing;
                } else {
                    $enr = GroupEnrollment::create([
                        'study_group_id' => $group->id,
                        'student_id' => $studentUser->id,
                        'status' => EnrollmentStatus::PENDING,
                        'join_method' => JoinMethod::TEACHER_INVITE,
                        'student_name_override' => trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? $studentUser->name))),
                        'student_phone_override' => trim(strip_tags((string) ($data['phone'] ?? $studentUser->phone ?? ''))),
                        'parent_name_ar' => isset($data['parent_name_ar']) || isset($data['parentNameAr'])
                            ? trim(strip_tags((string) ($data['parent_name_ar'] ?? $data['parentNameAr'])))
                            : null,
                        'parent_phone' => isset($data['parent_phone']) || isset($data['parentPhone'])
                            ? trim(strip_tags((string) ($data['parent_phone'] ?? $data['parentPhone'])))
                            : null,
                        'payment_status' => $paymentStatus,
                        'due_amount_egp' => $dueAmount,
                        'payment_due_since' => $dueAmount > 0 ? now()->toDateString() : null,
                        'requested_at' => now(),
                    ]);
                }

                $this->logSecurityEvent('center.group.student_invited', [
                    'group_uuid' => $group->uuid,
                    'enrollment_uuid' => $enr->uuid,
                    'student_id' => $studentUser->id,
                ], 'info', $enr);

                return $enr->load(['student:id,name,email,phone,student_code,avatar', 'student.studentProfile:student_id,grade_ar']);
            }

            $barcode = ($existing?->student_barcode) ?: GroupEnrollment::generateUniqueBarcode($group->id, $studentUser->id);
            $passcode = ($existing?->student_passcode) ?: GroupEnrollment::generatePasscode();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update([
                    'status' => EnrollmentStatus::ENROLLED,
                    'join_method' => JoinMethod::DIRECT_ENROLLMENT,
                    'student_barcode' => $barcode,
                    'student_passcode' => $passcode,
                    'student_name_override' => trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? $studentUser->name))),
                    'student_phone_override' => trim(strip_tags((string) ($data['phone'] ?? $studentUser->phone ?? ''))),
                    'parent_name_ar' => isset($data['parent_name_ar']) || isset($data['parentNameAr'])
                        ? trim(strip_tags((string) ($data['parent_name_ar'] ?? $data['parentNameAr'])))
                        : $existing->parent_name_ar,
                    'parent_phone' => isset($data['parent_phone']) || isset($data['parentPhone'])
                        ? trim(strip_tags((string) ($data['parent_phone'] ?? $data['parentPhone'])))
                        : $existing->parent_phone,
                    'payment_status' => $paymentStatus,
                    'due_amount_egp' => $dueAmount,
                    'payment_due_since' => $dueAmount > 0 ? now()->toDateString() : null,
                    'enrolled_at' => now(),
                ]);
                $enr = $existing;
            } else {
                $enr = GroupEnrollment::create([
                    'study_group_id' => $group->id,
                    'student_id' => $studentUser->id,
                    'status' => EnrollmentStatus::ENROLLED,
                    'join_method' => JoinMethod::DIRECT_ENROLLMENT,
                    'student_barcode' => $barcode,
                    'student_passcode' => $passcode,
                    'student_name_override' => trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? $studentUser->name))),
                    'student_phone_override' => trim(strip_tags((string) ($data['phone'] ?? $studentUser->phone ?? ''))),
                    'parent_name_ar' => isset($data['parent_name_ar']) || isset($data['parentNameAr'])
                        ? trim(strip_tags((string) ($data['parent_name_ar'] ?? $data['parentNameAr'])))
                        : null,
                    'parent_phone' => isset($data['parent_phone']) || isset($data['parentPhone'])
                        ? trim(strip_tags((string) ($data['parent_phone'] ?? $data['parentPhone'])))
                        : null,
                    'attendance_rate' => 100,
                    'attended_sessions' => 0,
                    'total_sessions' => 0,
                    'academic_status' => AcademicStatus::GOOD,
                    'payment_status' => $paymentStatus,
                    'due_amount_egp' => $dueAmount,
                    'payment_due_since' => $dueAmount > 0 ? now()->toDateString() : null,
                    'enrolled_at' => now(),
                ]);
            }

            $this->logSecurityEvent('center.group.student_enrolled', [
                'group_uuid' => $group->uuid,
                'enrollment_uuid' => $enr->uuid,
                'student_id' => $studentUser->id,
                'barcode' => $barcode,
            ], 'info', $enr);

            return $enr->load(['student:id,name,email,phone,student_code,avatar', 'student.studentProfile:student_id,grade_ar']);
        });

        $formatted = $this->formatEnrollment($enrollment, $group, 'absent', null);
        $formatted['enrollmentStatus'] = $isPlatformInvite ? EnrollmentStatus::PENDING->value : EnrollmentStatus::ENROLLED->value;
        $formatted['joinMethod'] = $isPlatformInvite ? JoinMethod::TEACHER_INVITE->value : JoinMethod::DIRECT_ENROLLMENT->value;
        $formatted['isInvitation'] = $isPlatformInvite;

        return $formatted;
    }

    /**
     * Accept a pending student join request into a center group.
     *
     * @return array<string, mixed>
     */
    public function acceptPendingEnrollment(User $center, string $groupUuid, string $enrollmentUuid): array
    {
        $group = $this->findOwnedGroup($center, $groupUuid);

        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('uuid', $enrollmentUuid)
            ->first();

        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        if ($enrollment->status === EnrollmentStatus::ENROLLED) {
            return $this->formatEnrollment($enrollment->load('student'), $group, 'absent', null);
        }

        if ($enrollment->status !== EnrollmentStatus::PENDING) {
            throw ValidationException::withMessages([
                'enrollment' => ['يمكن قبول طلبات الانضمام المعلقة فقط.'],
            ]);
        }

        if ($enrollment->join_method === JoinMethod::TEACHER_INVITE) {
            throw ValidationException::withMessages([
                'enrollment' => ['تم إرسال هذه الدعوة من قِبل السنتر، ويجب على الطالب قبولها من حسابه للانضمام للمجموعة.'],
            ]);
        }

        $currentEnrolled = GroupEnrollment::where('study_group_id', $group->id)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->count();

        if ($currentEnrolled >= $group->max_students) {
            throw ValidationException::withMessages([
                'group' => [__('messages.group_capacity_reached', ['max' => $group->max_students])],
            ]);
        }

        $this->billingService->assertUsersQuotaAvailable($center, 1);

        $accepted = $this->transaction(function () use ($group, $enrollment) {
            $barcode = $enrollment->student_barcode ?: GroupEnrollment::generateUniqueBarcode($group->id, $enrollment->student_id);
            $passcode = $enrollment->student_passcode ?: GroupEnrollment::generatePasscode();

            $enrollment->update([
                'status' => EnrollmentStatus::ENROLLED,
                'student_barcode' => $barcode,
                'student_passcode' => $passcode,
                'enrolled_at' => now(),
                'academic_status' => AcademicStatus::GOOD,
            ]);

            $this->logSecurityEvent('center.group.student_accepted', [
                'group_uuid' => $group->uuid,
                'enrollment_uuid' => $enrollment->uuid,
            ], 'info', $enrollment);

            return $enrollment->fresh(['student:id,name,email,phone,student_code,avatar', 'student.studentProfile:student_id,grade_ar']);
        });

        return $this->formatEnrollment($accepted, $group, 'absent', null);
    }

    /**
     * Reject a pending join request.
     */
    public function rejectPendingEnrollment(User $center, string $groupUuid, string $enrollmentUuid): bool
    {
        $group = $this->findOwnedGroup($center, $groupUuid);

        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('uuid', $enrollmentUuid)
            ->first();

        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        if ($enrollment->status !== EnrollmentStatus::PENDING) {
            throw ValidationException::withMessages([
                'enrollment' => ['يمكن رفض طلبات الانضمام المعلقة فقط.'],
            ]);
        }

        $enrollment->update(['status' => EnrollmentStatus::REJECTED]);

        $this->logSecurityEvent('center.group.student_rejected', [
            'group_uuid' => $group->uuid,
            'enrollment_uuid' => $enrollment->uuid,
        ], 'info', $enrollment);

        return true;
    }

    /**
     * Remove a student from a center study group.
     */
    public function removeStudent(User $center, string $groupUuid, string $enrollmentUuid): bool
    {
        $group = $this->findOwnedGroup($center, $groupUuid);

        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('uuid', $enrollmentUuid)
            ->first();

        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        return $this->transaction(function () use ($group, $enrollment) {
            $enrollment->update(['status' => EnrollmentStatus::REMOVED]);
            $enrollment->delete();

            $this->logSecurityEvent('center.group.student_removed', [
                'group_uuid' => $group->uuid,
                'enrollment_uuid' => $enrollment->uuid,
            ], 'warning', $enrollment);

            return true;
        });
    }

    /**
     * Update a student enrollment's payment status & due balance.
     *
     * @return array<string, mixed>
     */
    public function updateEnrollmentPayment(User $center, string $groupUuid, string $enrollmentUuid, array $data): array
    {
        $group = $this->findOwnedGroup($center, $groupUuid);

        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('uuid', $enrollmentUuid)
            ->with(['student:id,name,email,phone,student_code,avatar', 'student.studentProfile:student_id,grade_ar'])
            ->first();

        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        $paymentStatus = (string) ($data['payment_status'] ?? 'paid');
        $dueAmount = $paymentStatus === 'paid' ? 0.0 : max(0.0, (float) ($data['due_amount_egp'] ?? 0.0));

        $enrollment->update([
            'payment_status' => $paymentStatus,
            'due_amount_egp' => $dueAmount,
            'payment_due_since' => $dueAmount > 0 ? ($data['payment_due_since'] ?? $enrollment->payment_due_since ?? now()->toDateString()) : null,
        ]);

        $this->logSecurityEvent('center.enrollment.payment_updated', [
            'group_uuid' => $group->uuid,
            'enrollment_uuid' => $enrollment->uuid,
            'payment_status' => $paymentStatus,
            'due_amount_egp' => $dueAmount,
        ], 'info', $enrollment);

        $todaySession = GroupAttendanceSession::where('study_group_id', $group->id)
            ->whereDate('session_date', now()->toDateString())
            ->first();
        $todayRec = $todaySession
            ? GroupAttendanceRecord::where('attendance_session_id', $todaySession->id)
                ->where('group_enrollment_id', $enrollment->id)
                ->first()
            : null;
        $todayStatus = $todayRec
            ? ($todayRec->status instanceof AttendanceStatus ? $todayRec->status->value : (string) $todayRec->status)
            : 'absent';

        return $this->formatEnrollment(
            $enrollment->fresh(['student', 'student.studentProfile']),
            $group,
            $todayStatus,
            $todayRec?->scanned_at?->toIso8601String()
        );
    }

    /**
     * Generate printable smart student ID card payload with signed QR code and barcode.
     *
     * @return array<string, mixed>
     */
    public function getStudentCard(User $center, string $groupUuid, string $enrollmentUuid): array
    {
        $group = $this->findOwnedGroup($center, $groupUuid);

        /** @var GroupEnrollment|null $enrollment */
        $enrollment = GroupEnrollment::where('study_group_id', $group->id)
            ->where('uuid', $enrollmentUuid)
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->with(['student:id,name,phone,student_code,avatar', 'student.studentProfile:student_id,grade_ar'])
            ->first();

        if (! $enrollment) {
            throw (new ModelNotFoundException)->setModel(GroupEnrollment::class, [$enrollmentUuid]);
        }

        $profile = CenterProfile::where('user_id', $center->id)->first();
        $barcode = $enrollment->student_barcode ?: GroupEnrollment::generateUniqueBarcode($group->id, $enrollment->student_id);
        $passcode = $enrollment->student_passcode ?: GroupEnrollment::generatePasscode();

        if (! $enrollment->student_barcode || ! $enrollment->student_passcode) {
            $enrollment->update(['student_barcode' => $barcode, 'student_passcode' => $passcode]);
        }

        $timestamp = now()->timestamp;
        $hmac = hash_hmac('sha256', "{$group->id}:{$enrollment->uuid}:{$timestamp}", (string) config('app.key'));

        return [
            'enrollmentId' => $enrollment->uuid,
            'studentNameAr' => $enrollment->student_name_override ?: ($enrollment->student?->name ?? 'طالب'),
            'studentPhone' => $enrollment->student_phone_override ?: ($enrollment->student?->phone ?? ''),
            'studentCode' => $enrollment->student?->student_code,
            'avatar' => $enrollment->student?->avatar ?: null,
            'studentAvatar' => $enrollment->student?->avatar ?: null,
            'gradeAr' => $enrollment->student?->studentProfile?->grade_ar ?: $group->grade_ar,
            'barcode' => $barcode,
            'passcode' => $passcode,
            'permanentQrPayload' => "MOTAFAWWEQ:STUDENT:{$barcode}",
            'signedQrPayload' => "MOTAFAWWEQ:ATTEND:{$group->id}:{$enrollment->uuid}:{$timestamp}:{$hmac}",
            'groupNameAr' => $group->name_ar,
            'subjectAr' => $group->subject_ar,
            'teacherNameAr' => $group->centerTeacher?->name_ar ?: ($group->teacher_name_override ?: 'معلم معتمد'),
            'hallName' => $group->hall_name,
            'scheduleAr' => $group->schedule_ar,
            'centerNameAr' => $profile?->name_ar ?: $group->center_name,
            'centerPhone' => $profile?->phone ?: '',
            'attendanceRate' => (int) ($enrollment->attendance_rate ?? 100),
            'paymentStatus' => $enrollment->payment_status ?: 'paid',
            'issuedAt' => now()->toDateString(),
        ];
    }

    /**
     * Format a StudyGroup for Center API responses.
     *
     * @return array<string, mixed>
     */
    public function formatGroup(StudyGroup $group): array
    {
        $slots = ($group->scheduleSlots ?? collect())->map(function (GroupScheduleSlot $s) use ($group) {
            $day = $s->day_of_week instanceof DayOfWeek ? $s->day_of_week->value : strtolower((string) $s->day_of_week);
            $meta = CenterScheduleService::DAY_LABELS[$day] ?? ['ar' => $day, 'en' => ucfirst($day)];

            return [
                'id' => $s->uuid,
                'day' => $day,
                'dayAr' => $meta['ar'],
                'dayEn' => $meta['en'],
                'startTime' => $s->start_time,
                'endTime' => $s->end_time,
                'roomId' => $s->centerRoom?->uuid,
                'hall' => $s->centerRoom?->name_ar ?: ($s->hall_name ?: $group->hall_name),
            ];
        })->values()->all();

        return [
            'id' => $group->uuid,
            'uuid' => $group->uuid,
            'nameAr' => $group->name_ar,
            'nameEn' => $group->name_en ?: $group->name_ar,
            'subjectAr' => $group->subject_ar,
            'subjectEn' => $group->subject_en ?: $group->subject_ar,
            'gradeAr' => $group->grade_ar,
            'gradeEn' => $group->grade_en ?: $group->grade_ar,
            'branchId' => $group->centerBranch?->uuid,
            'centerName' => $group->centerBranch?->name_ar ?: $group->center_name,
            'roomId' => $group->centerRoom?->uuid,
            'hallName' => $group->centerRoom?->name_ar ?: $group->hall_name,
            'teacherId' => $group->centerTeacher?->uuid,
            'teacherNameAr' => $group->centerTeacher?->name_ar ?: ($group->teacher_name_override ?: 'معلم معتمد'),
            'scheduleAr' => $group->schedule_ar,
            'maxStudents' => (int) $group->max_students,
            'priceEgp' => (float) $group->price_egp,
            'joinCode' => $group->join_code,
            'status' => $group->status,
            'enrolledCount' => (int) ($group->enrolled_count ?? 0),
            'pendingCount' => (int) ($group->pending_count ?? 0),
            'scheduleSlots' => $slots,
            'slots' => $slots,
            'createdAt' => $group->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatEnrollment(GroupEnrollment $e, StudyGroup $group, string $todayStatus, ?string $todayScannedAt): array
    {
        $paymentStatus = $e->payment_status ?: 'paid';
        $dueAmt = (float) ($e->due_amount_egp ?? 0);

        return [
            'id' => $e->uuid,
            'enrollmentId' => $e->uuid,
            'studentId' => $e->student_id,
            'studentCode' => $e->student?->student_code,
            'name' => $e->student?->name ?: ($e->student_name_override ?: 'طالب'),
            'nameAr' => $e->student_name_override ?: ($e->student?->name ?? 'طالب'),
            'phone' => $e->student_phone_override ?: ($e->student?->phone ?? ''),
            'email' => $e->student?->email ?? '',
            'avatar' => $e->student?->avatar ?: null,
            'gradeAr' => $e->student?->studentProfile?->grade_ar ?: $group->grade_ar,
            'parentNameAr' => $e->parent_name_ar ?? '',
            'parentPhone' => $e->parent_phone ?? '',
            'barcode' => $e->student_barcode,
            'passcode' => $e->student_passcode,
            'qrPayload' => $e->student_barcode ? "MOTAFAWWEQ:STUDENT:{$e->student_barcode}" : null,
            'todayStatus' => $todayStatus,
            'todayScannedAt' => $todayScannedAt,
            'attendanceRate' => (int) $e->attendance_rate,
            'attendedSessions' => (int) $e->attended_sessions,
            'totalSessions' => (int) $e->total_sessions,
            'paymentStatus' => $paymentStatus,
            'paymentStatusAr' => $paymentStatus === 'paid'
                ? 'مسدد بالكامل'
                : ($dueAmt > 0 ? "متبقي {$dueAmt} ج.م" : 'مستحق السداد'),
            'dueAmountEgp' => $dueAmt,
            'paymentDueSince' => $e->payment_due_since?->toDateString(),
            'enrolledAt' => $e->enrolled_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve an existing platform student or provision a walk-in non-platform student.
     *
     * @return array{0: User, 1: bool} [User $student, bool $isExistingPlatformStudent]
     */
    protected function resolveOrProvisionStudentWithOrigin(array $data): array
    {
        $studentIdentifier = trim((string) ($data['student_id'] ?? $data['studentId'] ?? ''));
        if ($studentIdentifier !== '') {
            $found = User::where('role', UserRole::STUDENT->value)
                ->where(function ($q) use ($studentIdentifier) {
                    if (is_numeric($studentIdentifier)) {
                        $q->where('id', (int) $studentIdentifier);
                    } else {
                        $q->whereRaw('UPPER(student_code) = ?', [strtoupper($studentIdentifier)]);
                    }
                })
                ->first();

            if ($found) {
                return [$found, true];
            }

            if (empty($data['name_ar']) && empty($data['nameAr'])) {
                throw ValidationException::withMessages([
                    'student_id' => [__('messages.student_not_found')],
                ]);
            }
        }

        $phone = trim(strip_tags((string) ($data['phone'] ?? '')));
        if ($phone !== '') {
            $existingByPhone = User::where('phone', $phone)->first();
            if ($existingByPhone) {
                if ($existingByPhone->role === UserRole::STUDENT) {
                    $isPlatformAccount = ! str_ends_with((string) $existingByPhone->email, '@student.motafawweq.local');

                    return [$existingByPhone, $isPlatformAccount];
                }
                throw ValidationException::withMessages([
                    'phone' => ['رقم الهاتف مسجل بالفعل لحساب غير طالب على المنصة.'],
                ]);
            }
        }

        $email = isset($data['email']) && trim((string) $data['email']) !== ''
            ? strtolower(trim((string) $data['email']))
            : null;

        if ($email) {
            $existingByEmail = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($existingByEmail) {
                if ($existingByEmail->role === UserRole::STUDENT) {
                    $isPlatformAccount = ! str_ends_with((string) $existingByEmail->email, '@student.motafawweq.local');

                    return [$existingByEmail, $isPlatformAccount];
                }
                throw ValidationException::withMessages([
                    'email' => ['البريد الإلكتروني مسجل بالفعل لحساب غير طالب على المنصة.'],
                ]);
            }
        }

        $name = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? 'طالب جديد')));
        $safeEmail = $email ?: ('std_'.time().'_'.Str::random(6).'@student.motafawweq.local');

        do {
            $studentCode = 'STD-'.random_int(100000, 999999);
        } while (User::where('student_code', $studentCode)->exists());

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $safeEmail,
            'phone' => $phone !== '' ? $phone : '010'.random_int(10000000, 99999999),
            'password' => Hash::make(Str::random(20)),
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'student_code' => $studentCode,
            'email_verified_at' => now(),
        ]);
        $user->save();

        StudentProfile::firstOrCreate(
            ['student_id' => $user->id],
            ['grade_ar' => trim(strip_tags((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? 'الصف الثالث الثانوي')))]
        );

        return [$user, false];
    }

    /**
     * @param  array<int, mixed>  $rawSlots
     * @return array<int, array<string, mixed>>
     */
    protected function prepareSlots(User $center, array $rawSlots, ?CenterRoom $defaultRoom, string $defaultHall): array
    {
        $prepared = [];

        foreach ($rawSlots as $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $day = strtolower(trim((string) ($slot['day'] ?? $slot['day_of_week'] ?? '')));
            if (! in_array($day, DayOfWeek::values(), true)) {
                continue;
            }

            $slotRoom = $defaultRoom;
            $slotRoomUuid = $slot['room_id'] ?? $slot['roomId'] ?? null;
            if ($slotRoomUuid && is_string($slotRoomUuid)) {
                $slotRoom = $this->roomService->findOwnedRoom($center, $slotRoomUuid);
            }

            $hallName = $slotRoom?->name_ar
                ?: trim(strip_tags((string) ($slot['hall'] ?? $slot['hall_name'] ?? $defaultHall)));

            $prepared[] = [
                'day' => $day,
                'start_time' => $this->scheduleService->normalizeTime((string) ($slot['start_time'] ?? $slot['startTime'] ?? '16:00')),
                'end_time' => $this->scheduleService->normalizeTime((string) ($slot['end_time'] ?? $slot['endTime'] ?? '18:00')),
                'room_id' => $slotRoom?->id,
                'hall_name' => $hallName,
            ];
        }

        return $prepared;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    protected function buildScheduleSummary(array $slots): string
    {
        if (empty($slots)) {
            return '';
        }

        $dayNames = array_map(
            fn ($s) => CenterScheduleService::DAY_LABELS[$s['day']]['ar'] ?? $s['day'],
            $slots
        );

        return implode(' و', array_unique($dayNames)).' من '.$slots[0]['start_time'].' إلى '.$slots[0]['end_time'];
    }

    protected function resolveOptionalOwnedBranch(User $center, mixed $uuid): ?CenterBranch
    {
        if (empty($uuid) || $uuid === 'all') {
            return null;
        }

        return $this->branchService->findOwnedBranch($center, (string) $uuid);
    }

    protected function resolveOptionalOwnedRoom(User $center, mixed $uuid): ?CenterRoom
    {
        if (empty($uuid)) {
            return null;
        }

        return $this->roomService->findOwnedRoom($center, (string) $uuid);
    }

    protected function resolveOptionalOwnedTeacher(User $center, mixed $uuid): ?CenterTeacher
    {
        if (empty($uuid)) {
            return null;
        }

        $teacher = $this->teacherService->findOwnedTeacher($center, (string) $uuid);

        if ($teacher->teacher_user_id !== null && in_array($teacher->status, ['invited', 'declined'], true)) {
            throw ValidationException::withMessages([
                'center_teacher_id' => ['لا يمكن إسناد مجموعة لهذا المعلم قبل موافقته على دعوة الانضمام للسنتر من حسابه.'],
            ]);
        }

        return $teacher;
    }
}
