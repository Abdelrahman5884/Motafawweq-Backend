<?php

namespace App\Services\V1\Center;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CenterBranch;
use App\Models\CenterConnectedUser;
use App\Models\CenterTeacher;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class CenterConnectService extends BaseService
{
    public function __construct(
        protected CenterBranchService $branchService,
        protected CenterBillingService $billingService
    ) {}

    /**
     * Motafawweq Connect: search active platform teachers and students by name, phone, email, or student_code.
     * Minimum 2 characters to mitigate full user directory enumeration.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchGlobalUsers(User $center, string $query, ?string $role = null, int $limit = 15): array
    {
        $clean = trim($query);
        if (mb_strlen($clean) < 2) {
            return [];
        }

        $safeLimit = min(30, max(1, $limit));
        $allowedRoles = match ($role) {
            'teacher' => [UserRole::TEACHER->value],
            'student' => [UserRole::STUDENT->value],
            default => [UserRole::TEACHER->value, UserRole::STUDENT->value],
        };

        $users = User::query()
            ->whereIn('role', $allowedRoles)
            ->where('status', UserStatus::ACTIVE->value)
            ->where(function ($q) use ($clean) {
                $q->where('name', 'like', "%{$clean}%")
                    ->orWhere('phone', 'like', "%{$clean}%")
                    ->orWhere('email', 'like', "%{$clean}%")
                    ->orWhere('student_code', 'like', "%{$clean}%");
            })
            ->with([
                'teacherProfile:user_id,subject_ar,rating',
                'studentProfile:student_id,grade_ar,school_name',
            ])
            ->orderByDesc('id')
            ->limit($safeLimit)
            ->get();

        $connectedIds = CenterConnectedUser::forCenter($center->id)
            ->whereIn('user_id', $users->pluck('id')->all())
            ->pluck('user_id')
            ->flip();

        $teacherStatuses = CenterTeacher::forCenter($center->id)
            ->whereIn('teacher_user_id', $users->pluck('id')->all())
            ->get(['teacher_user_id', 'status'])
            ->keyBy('teacher_user_id');

        return $users->map(function (User $u) use ($connectedIds, $teacherStatuses) {
            $isTeacher = $u->role === UserRole::TEACHER;
            $ct = $isTeacher ? $teacherStatuses->get($u->id) : null;
            $hasActiveOrPendingInvite = $ct !== null && in_array($ct->status, ['active', 'invited'], true);
            $connectionStatus = $ct?->status === 'invited'
                ? 'pending'
                : ($ct?->status === 'declined'
                    ? 'declined'
                    : ($connectedIds->has($u->id) || $ct?->status === 'active' ? 'active' : null));

            return [
                'id' => $u->id,
                'nameAr' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone ?? '',
                'studentCode' => $u->student_code,
                'role' => $u->role?->value,
                'roleAr' => $isTeacher ? 'معلم معتمد' : 'طالب مسجل',
                'subjectAr' => $isTeacher ? ($u->teacherProfile?->subject_ar ?? '') : null,
                'rating' => $isTeacher ? (float) ($u->teacherProfile?->rating ?? 5.0) : null,
                'gradeAr' => ! $isTeacher ? ($u->studentProfile?->grade_ar ?? '') : null,
                'schoolAr' => ! $isTeacher ? ($u->studentProfile?->school_name ?? '') : null,
                'avatar' => $u->avatar,
                'isAlreadyConnected' => $connectedIds->has($u->id) || $hasActiveOrPendingInvite,
                'connectionStatus' => $connectionStatus,
            ];
        })->values()->all();
    }

    /**
     * List all platform users already connected to this center.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listConnectedUsers(User $center, ?string $role = null, ?string $branchUuid = null): array
    {
        $query = CenterConnectedUser::forCenter($center->id)
            ->with([
                'user:id,name,email,phone,role,avatar,student_code',
                'user.teacherProfile:user_id,subject_ar',
                'user.studentProfile:student_id,grade_ar',
                'branch:id,uuid,name_ar',
            ])
            ->orderByDesc('connected_at');

        if ($role && in_array($role, ['teacher', 'student'], true)) {
            $query->where('connected_role', $role);
        }

        if ($branchUuid && $branchUuid !== 'all') {
            $branch = $this->branchService->findOwnedBranch($center, $branchUuid);
            $query->where('branch_id', $branch->id);
        }

        $connections = $query->get();

        $teacherUserIds = $connections
            ->where('connected_role', UserRole::TEACHER->value)
            ->pluck('user_id')
            ->all();

        $teacherStatusMap = empty($teacherUserIds)
            ? collect()
            : CenterTeacher::forCenter($center->id)
                ->whereIn('teacher_user_id', $teacherUserIds)
                ->get(['uuid', 'teacher_user_id', 'status'])
                ->keyBy('teacher_user_id');

        return $connections
            ->map(fn (CenterConnectedUser $conn) => $this->formatConnection($conn, $teacherStatusMap->get($conn->user_id)))
            ->values()
            ->all();
    }

    /**
     * Connect an existing active platform teacher or student to the center without duplicating accounts.
     *
     * @return array<string, mixed>
     */
    public function connectUser(User $center, int $targetUserId, ?string $branchUuid = null): array
    {
        /** @var User|null $target */
        $target = User::where('id', $targetUserId)
            ->whereIn('role', [UserRole::TEACHER->value, UserRole::STUDENT->value])
            ->where('status', UserStatus::ACTIVE->value)
            ->with(['teacherProfile:user_id,subject_ar'])
            ->first();

        if (! $target) {
            throw ValidationException::withMessages([
                'user_id' => [__('messages.center.connect_user_not_found')],
            ]);
        }

        $alreadyConnected = CenterConnectedUser::forCenter($center->id)
            ->where('user_id', $target->id)
            ->exists();

        if ($alreadyConnected) {
            throw ValidationException::withMessages([
                'user_id' => [__('messages.center.connect_user_already_linked')],
            ]);
        }

        // Enforce active users & teachers quota so Motafawweq Connect cannot bypass plan limits
        $tier = $this->billingService->resolveActivePlanTier($center);
        $usage = $this->billingService->getUsageStats($center);
        if ($target->role === UserRole::TEACHER) {
            $maxTeachers = $tier->maxTeachers();
            if ($maxTeachers !== null && $usage['teachersCount'] >= $maxTeachers) {
                throw ValidationException::withMessages([
                    'quota' => [__('messages.center.quota_teachers_exceeded', [
                        'max' => $maxTeachers,
                        'plan' => $tier->label(),
                    ])],
                ]);
            }
        }
        $this->billingService->assertUsersQuotaAvailable($center, 1);

        $branchId = null;
        if ($branchUuid && $branchUuid !== 'all') {
            $branchId = $this->branchService->findOwnedBranch($center, $branchUuid)->id;
        } else {
            $branchId = CenterBranch::forCenter($center->id)->orderByDesc('is_main')->value('id');
        }

        // Enforce active plan quotas when connecting a teacher
        if ($target->isTeacher()) {
            $tier = $this->billingService->resolveActivePlanTier($center);
            $maxTeachers = $tier->maxTeachers();
            $currentTeachers = CenterTeacher::forCenter($center->id)->count();
            if ($maxTeachers !== null && $currentTeachers >= $maxTeachers) {
                throw ValidationException::withMessages([
                    'quota' => [__('messages.center.quota_teachers_exceeded', [
                        'max' => $maxTeachers,
                        'plan' => $tier->label(),
                    ])],
                ]);
            }
        }

        $this->billingService->assertUsersQuotaAvailable($center, 1);

        $connection = $this->transaction(function () use ($center, $target, $branchId) {
            /** @var CenterConnectedUser $conn */
            $conn = CenterConnectedUser::create([
                'center_id' => $center->id,
                'user_id' => $target->id,
                'branch_id' => $branchId,
                'connected_role' => $target->role->value,
                'connected_by' => $center->id,
                'connected_at' => now(),
            ]);

            $linkedCenterTeacher = null;

            // If linking a platform teacher, send a PENDING invitation (status = invited) requiring teacher approval!
            if ($target->isTeacher()) {
                $existingTeacher = CenterTeacher::forCenter($center->id)
                    ->where(function ($q) use ($target) {
                        $q->where('teacher_user_id', $target->id)
                            ->orWhereRaw('LOWER(email) = ?', [strtolower($target->email)]);
                    })
                    ->first();

                if (! $existingTeacher) {
                    $linkedCenterTeacher = CenterTeacher::create([
                        'center_id' => $center->id,
                        'branch_id' => $branchId,
                        'teacher_user_id' => $target->id,
                        'name_ar' => $target->name,
                        'name_en' => $target->name,
                        'subject_ar' => $target->teacherProfile?->subject_ar ?: 'مادة دراسية',
                        'phone' => $target->phone,
                        'email' => strtolower($target->email),
                        'status' => 'invited',
                        'invite_sent_at' => now(),
                        'activated_at' => null,
                    ]);
                } else {
                    $existingTeacher->update([
                        'branch_id' => $branchId ?? $existingTeacher->branch_id,
                        'teacher_user_id' => $target->id,
                        'status' => $existingTeacher->status === 'active' ? 'active' : 'invited',
                        'invite_sent_at' => now(),
                    ]);
                    $linkedCenterTeacher = $existingTeacher->fresh();
                }
            }

            $this->logSecurityEvent('center.connect.user_linked', [
                'connection_uuid' => $conn->uuid,
                'connected_user_id' => $target->id,
                'connected_role' => $target->role->value,
                'branch_id' => $branchId,
                'awaiting_teacher_approval' => $target->isTeacher() && $linkedCenterTeacher?->status === 'invited',
            ], 'info', $conn);

            return [
                'conn' => $conn->load([
                    'user:id,name,email,phone,role,avatar,student_code',
                    'user.teacherProfile:user_id,subject_ar',
                    'user.studentProfile:student_id,grade_ar',
                    'branch:id,uuid,name_ar',
                ]),
                'teacher' => $linkedCenterTeacher,
            ];
        });

        return $this->formatConnection($connection['conn'], $connection['teacher']);
    }

    /**
     * Disconnect a previously linked user (or cancel a pending platform teacher invite) from the center.
     */
    public function disconnectUser(User $center, string $connectionUuid): bool
    {
        /** @var CenterConnectedUser|null $conn */
        $conn = CenterConnectedUser::where('uuid', $connectionUuid)->first();
        if (! $conn) {
            throw (new ModelNotFoundException)->setModel(CenterConnectedUser::class, [$connectionUuid]);
        }

        $conn->assertOwnedByCenter($center);

        return $this->transaction(function () use ($conn) {
            $uuid = $conn->uuid;
            $userId = $conn->user_id;
            $centerId = $conn->center_id;
            $role = $conn->connected_role;
            $conn->delete();

            // Cancel any pending/declined CenterTeacher invitation when disconnecting
            if ($role === UserRole::TEACHER->value) {
                CenterTeacher::where('center_id', $centerId)
                    ->where('teacher_user_id', $userId)
                    ->whereIn('status', ['invited', 'declined'])
                    ->delete();
            }

            $this->logSecurityEvent('center.connect.user_unlinked', [
                'connection_uuid' => $uuid,
                'user_id' => $userId,
            ], 'warning');

            return true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatConnection(CenterConnectedUser $conn, ?CenterTeacher $centerTeacher = null): array
    {
        $u = $conn->user;
        $isTeacher = $conn->connected_role === UserRole::TEACHER->value;

        $connectionStatus = 'active';
        $connectionStatusAr = 'مربوط ونشط';
        if ($isTeacher && $centerTeacher) {
            if ($centerTeacher->status === 'invited') {
                $connectionStatus = 'pending';
                $connectionStatusAr = 'بانتظار موافقة المعلم';
            } elseif ($centerTeacher->status === 'declined') {
                $connectionStatus = 'declined';
                $connectionStatusAr = 'رفض المعلم الدعوة';
            }
        }

        return [
            'id' => $conn->uuid,
            'uuid' => $conn->uuid,
            'userId' => $conn->user_id,
            'centerTeacherId' => $centerTeacher?->uuid,
            'nameAr' => $u?->name ?? '',
            'email' => $u?->email ?? '',
            'phone' => $u?->phone ?? '',
            'studentCode' => $u?->student_code,
            'role' => $conn->connected_role,
            'roleAr' => $isTeacher ? 'معلم معتمد' : 'طالب مسجل',
            'subjectAr' => $isTeacher ? ($u?->teacherProfile?->subject_ar ?? '') : null,
            'gradeAr' => ! $isTeacher ? ($u?->studentProfile?->grade_ar ?? '') : null,
            'avatar' => $u?->avatar,
            'branchId' => $conn->branch?->uuid,
            'branchNameAr' => $conn->branch?->name_ar ?? 'الفرع الرئيسي',
            'status' => $connectionStatus,
            'statusAr' => $connectionStatusAr,
            'awaitingTeacherApproval' => $connectionStatus === 'pending',
            'connectedAt' => ($conn->connected_at ?? $conn->created_at)?->toDateString(),
        ];
    }
}
