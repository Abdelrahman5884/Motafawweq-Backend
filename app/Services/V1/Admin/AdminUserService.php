<?php

namespace App\Services\V1\Admin;

use App\Enums\BillingPeriod;
use App\Enums\DirectPaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationCodeType;
use App\Enums\VerificationStatus;
use App\Models\CenterBranch;
use App\Models\CenterProfile;
use App\Models\CenterSubscription;
use App\Models\StoredFile;
use App\Models\StudentDirectPayment;
use App\Models\StudentProfile;
use App\Models\StudentSubscription;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordOtpNotification;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\BaseService;
use App\Services\V1\Auth\OtpService;
use App\Services\V1\Auth\TokenService;
use App\Support\ExcelWorkbookBuilder;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminUserService extends BaseService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected TokenService $tokenService,
        protected OtpService $otpService,
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * Paginated list of platform users with single-pass summary counts & zero N+1 eager loading.
     *
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, int>, items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listUsers(array $filters = []): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);
        $role = $this->normalizeRoleFilter($filters['role'] ?? null);
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $query = User::query()
            ->with(['studentProfile', 'teacherProfile', 'centerProfile'])
            ->withCount(['courses', 'groupEnrollments', 'children', 'centerRooms'])
            ->latest('id');

        if ($role !== null && $role !== 'all') {
            $query->where('role', $role);
        }

        if ($status !== null && $status !== '' && $status !== 'all') {
            $this->applyStatusFilter($query, $status);
        }

        if ($search !== null && $search !== '') {
            $safe = addcslashes($search, '%_\\');
            $nidHash = User::blindIndexNationalId($search);

            $query->where(function (Builder $q) use ($safe, $nidHash): void {
                $q->where('name', 'like', "%{$safe}%")
                    ->orWhere('email', 'like', "%{$safe}%")
                    ->orWhere('phone', 'like', "%{$safe}%")
                    ->orWhere('student_code', 'like', "%{$safe}%")
                    ->orWhere('admin_details_ar', 'like', "%{$safe}%")
                    ->orWhere('admin_details_en', 'like', "%{$safe}%")
                    ->orWhere('national_id_hash', $nidHash);
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        $pageUsers = collect($paginator->items());
        $subscriptionsByUser = $this->loadSubscriptionsBatch($pageUsers);

        $items = $pageUsers
            ->map(function (User $u) use ($subscriptionsByUser): array {
                $userSubs = $subscriptionsByUser[$u->id] ?? [];

                return $this->formatUserRow($u, false, $userSubs);
            })
            ->values()
            ->all();

        return [
            'summary' => $this->computeSummaryStats(),
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * Fetch the Teacher / Center KYC & Accreditation Verification Queue.
     *
     * @return list<array<string, mixed>>
     */
    public function listVerificationQueue(?string $statusFilter = null): array
    {
        $query = User::query()
            ->whereIn('role', [UserRole::TEACHER->value, UserRole::CENTER->value])
            ->with(['teacherProfile', 'centerProfile'])
            ->latest('id');

        $normalized = strtolower(trim((string) $statusFilter));
        if ($normalized === '' || $normalized === 'pending') {
            $query->where(function (Builder $q): void {
                $q->where('status', UserStatus::PENDING->value)
                    ->orWhereHas('teacherProfile', fn (Builder $tp) => $tp->where('verification_status', VerificationStatus::PENDING->value))
                    ->orWhereHas('centerProfile', fn (Builder $cp) => $cp->where('is_verified_center', false));
            });
        } elseif ($normalized === 'verified') {
            $query->where('status', UserStatus::ACTIVE->value)
                ->where(function (Builder $q): void {
                    $q->whereHas('teacherProfile', fn (Builder $tp) => $tp->where('verification_status', VerificationStatus::VERIFIED->value))
                        ->orWhereHas('centerProfile', fn (Builder $cp) => $cp->where('is_verified_center', true));
                });
        } elseif ($normalized === 'rejected') {
            $query->whereHas('teacherProfile', fn (Builder $tp) => $tp->where('verification_status', VerificationStatus::REJECTED->value));
        }

        return $query->limit(100)->get()->map(function (User $u): array {
            $tp = $u->teacherProfile;
            $cp = $u->centerProfile;

            $vStatus = $this->resolveEffectiveStatus($u);
            $queueStatus = match ($vStatus) {
                'verified' => 'Approved',
                'suspended' => 'Rejected',
                default => 'Pending Review',
            };

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'role' => $u->role?->value,
                'subject' => $tp?->subject_ar ?: ($cp?->name_ar ?: ''),
                'certificate' => $tp?->degree_ar ?: ($u->admin_details_ar ?: ''),
                'nationalId' => $u->maskedNationalId() ?: 'غير مسجل',
                'backgroundCheckStatus' => $tp?->background_check_status ?: 'clean',
                'status' => $queueStatus,
                'effectiveStatus' => $vStatus,
                'verificationNotes' => $tp?->verification_notes,
                'submittedAt' => $u->created_at?->toDateString(),
            ];
        })->values()->all();
    }

    /**
     * Get comprehensive single user profile (including masked/audited PII, active sessions & recent logins).
     *
     * @return array<string, mixed>
     */
    public function getUserDetails(User $admin, int $userId): array
    {
        /** @var User $user */
        $user = User::query()
            ->with(['studentProfile', 'teacherProfile', 'centerProfile', 'children:id,name,email,student_code'])
            ->withCount(['courses', 'groupEnrollments', 'children', 'centerRooms'])
            ->findOrFail($userId);

        $activeSessionsCount = (int) $user->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        $recentLogins = $user->loginHistories()
            ->latest('created_at')
            ->limit(5)
            ->get(['status', 'ip_address', 'user_agent', 'created_at'])
            ->map(fn ($h) => [
                'status' => is_object($h->status) ? $h->status->value : (string) $h->status,
                'ipAddress' => $h->ip_address,
                'deviceName' => Str::limit((string) ($h->user_agent ?: 'Web'), 80),
                'createdAt' => $h->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $walletBalance = 0.0;
        if ($user->isTeacher()) {
            $walletBalance = (float) StudentDirectPayment::query()
                ->where('teacher_id', $user->id)
                ->where('status', DirectPaymentStatus::CONFIRMED->value)
                ->sum('amount_egp');
        }

        $subscriptions = $this->loadAllSubscriptionsForUser($user);
        $subscriptionsSummary = $this->summarizeSubscriptions($subscriptions);

        $row = $this->formatUserRow($user, true, $subscriptions);
        $row['activeSessionsCount'] = $activeSessionsCount;
        $row['recentLogins'] = $recentLogins;
        $row['walletBalance'] = round($walletBalance, 2);
        $row['isLocked'] = $user->isLocked();
        $row['emailVerified'] = $user->hasVerifiedEmail();
        $row['lastLoginIp'] = $user->last_login_ip;
        $row['subscriptions'] = $subscriptions;
        $row['activeSubscription'] = $this->pickActiveSubscription($subscriptions);
        $row['subscriptionsSummary'] = $subscriptionsSummary;
        $row['linkedChildren'] = $user->isParent()
            ? $user->children->map(fn (User $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'studentCode' => $c->student_code,
            ])->values()->all()
            : [];

        $this->logSecurityEvent('admin.user.viewed_details', [
            'admin_id' => $admin->id,
            'target_user_id' => $user->id,
            'target_role' => $user->role?->value,
        ], 'info', $user);

        return $row;
    }

    /**
     * Provision a new non-admin platform user account atomically with role profile initialization.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createUser(User $admin, array $data): array
    {
        $role = UserRole::from((string) $data['role']);

        // Defense in depth: never allow provisioning an admin via user management API
        if ($role === UserRole::ADMIN) {
            throw ValidationException::withMessages([
                'role' => [__('messages.admin.cannot_assign_admin_role')],
            ]);
        }

        $rawStatus = strtolower(trim((string) ($data['status'] ?? ($role === UserRole::TEACHER ? 'pending_verification' : 'active'))));
        [$userStatus, $teacherVerification] = $this->resolveStatusMapping($rawStatus, $role);

        $passwordGenerated = empty($data['password']);
        $plainPassword = $passwordGenerated
            ? Str::password(16, true, true, true, false)
            : (string) $data['password'];

        /** @var User $created */
        $created = $this->transaction(function () use ($admin, $data, $role, $userStatus, $teacherVerification, $plainPassword): User {
            $studentCode = $role === UserRole::STUDENT
                ? $this->userRepository->generateUniqueStudentCode()
                : null;

            $attrs = [
                'name' => trim((string) $data['name']),
                'email' => strtolower(trim((string) $data['email'])),
                'password' => $plainPassword,
                'phone' => ! empty($data['phone']) ? trim((string) $data['phone']) : null,
                'role' => $role->value,
                'status' => $userStatus->value,
                'student_code' => $studentCode,
                'email_verified_at' => now(),
                'password_changed_at' => now(),
                'admin_details_ar' => $data['details_ar'] ?? null,
                'admin_details_en' => $data['details_en'] ?? ($data['details_ar'] ?? null),
            ];

            if (! empty($data['national_id'])) {
                $cleanNid = trim((string) $data['national_id']);
                $attrs['national_id_encrypted'] = $cleanNid;
                $attrs['national_id_last4'] = substr($cleanNid, -4);
                $attrs['national_id_hash'] = User::blindIndexNationalId($cleanNid);
            }

            /** @var User $user */
            $user = $this->userRepository->forceCreate($attrs);

            if ($role === UserRole::STUDENT) {
                StudentProfile::create([
                    'student_id' => $user->id,
                    'grade_ar' => $data['grade_ar'] ?? null,
                    'track_ar' => $data['track_ar'] ?? null,
                    'governorate' => $data['governorate'] ?? null,
                    'parent_phone' => $data['parent_phone'] ?? null,
                ]);
            } elseif ($role === UserRole::TEACHER) {
                $profile = new TeacherProfile([
                    'user_id' => $user->id,
                    'name_ar' => $user->name,
                    'name_en' => $user->name,
                    'subject_ar' => $data['subject_ar'] ?? null,
                    'bio_ar' => $data['details_ar'] ?? null,
                    'whatsapp_phone' => $user->phone,
                ]);
                $profile->forceFill([
                    'degree_ar' => $data['degree_ar'] ?? null,
                    'is_verified_educator' => $teacherVerification === VerificationStatus::VERIFIED,
                    'verification_status' => $teacherVerification,
                    'verified_at' => $teacherVerification === VerificationStatus::VERIFIED ? now() : null,
                    'verified_by' => $teacherVerification === VerificationStatus::VERIFIED ? $admin->id : null,
                ])->save();
            } elseif ($role === UserRole::CENTER) {
                $profile = CenterProfile::create([
                    'user_id' => $user->id,
                    'name_ar' => $user->name,
                    'name_en' => $user->name,
                    'phone' => $user->phone,
                    'address_ar' => $data['governorate'] ?? ($data['details_ar'] ?? null),
                ]);
                $profile->forceFill([
                    'is_verified_center' => $userStatus === UserStatus::ACTIVE,
                ])->save();

                CenterBranch::firstOrCreate(
                    ['center_id' => $user->id, 'is_main' => true],
                    [
                        'name_ar' => 'الفرع الرئيسي',
                        'name_en' => 'Main Branch',
                        'phone' => $user->phone,
                        'manager_name_ar' => $user->name,
                        'is_main' => true,
                        'is_active' => true,
                    ]
                );
            }

            return $user;
        });

        // If password was auto-generated, send a password-reset OTP so the user can choose their own password
        if ($passwordGenerated) {
            $code = $this->otpService->generate($created, VerificationCodeType::PASSWORD_RESET, request()?->ip());
            $this->safeNotify($created, new ResetPasswordOtpNotification($code, (int) config('auth_security.otp.ttl_minutes')));
        }

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.user.created', [
            'admin_id' => $admin->id,
            'target_user_id' => $created->id,
            'role' => $role->value,
            'status' => $userStatus->value,
        ], 'info', $created);

        $created->load(['studentProfile', 'teacherProfile', 'centerProfile'])
            ->loadCount(['courses', 'groupEnrollments', 'children', 'centerRooms']);

        return $this->formatUserRow($created, true);
    }

    /**
     * Update an existing non-admin user account with strict self/peer-admin mutation guards.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function updateUser(User $admin, int $userId, array $data): array
    {
        /** @var User $target */
        $target = User::query()->with(['studentProfile', 'teacherProfile', 'centerProfile'])->findOrFail($userId);

        $this->assertTargetMutableByAdmin($admin, $target, 'update');

        if (isset($data['role']) && $data['role'] === UserRole::ADMIN->value) {
            throw ValidationException::withMessages([
                'role' => [__('messages.admin.cannot_assign_admin_role')],
            ]);
        }

        $changedFields = [];

        $this->transaction(function () use ($admin, $target, $data, &$changedFields): void {
            $updates = [];

            foreach (['name', 'email', 'phone'] as $field) {
                if (array_key_exists($field, $data)) {
                    $val = $field === 'email' ? strtolower(trim((string) $data[$field])) : $data[$field];
                    if ($target->{$field} !== $val) {
                        $updates[$field] = $val;
                        $changedFields[] = $field;
                    }
                }
            }

            if (array_key_exists('details_ar', $data)) {
                $updates['admin_details_ar'] = $data['details_ar'];
                $changedFields[] = 'admin_details_ar';
            }
            if (array_key_exists('details_en', $data)) {
                $updates['admin_details_en'] = $data['details_en'];
                $changedFields[] = 'admin_details_en';
            }

            if (array_key_exists('national_id', $data)) {
                $cleanNid = trim((string) ($data['national_id'] ?? ''));
                if ($cleanNid === '') {
                    $updates['national_id_encrypted'] = null;
                    $updates['national_id_last4'] = null;
                    $updates['national_id_hash'] = null;
                } else {
                    $updates['national_id_encrypted'] = $cleanNid;
                    $updates['national_id_last4'] = substr($cleanNid, -4);
                    $updates['national_id_hash'] = User::blindIndexNationalId($cleanNid);
                }
                $changedFields[] = 'national_id';
            }

            $newRole = isset($data['role']) ? UserRole::from((string) $data['role']) : $target->role;
            if ($newRole !== $target->role) {
                $updates['role'] = $newRole->value;
                $changedFields[] = 'role';
                if ($newRole === UserRole::STUDENT && empty($target->student_code)) {
                    $updates['student_code'] = $this->userRepository->generateUniqueStudentCode();
                }
            }

            $shouldRevokeTokens = false;
            if (isset($data['status'])) {
                [$mappedStatus, $teacherVerif] = $this->resolveStatusMapping((string) $data['status'], $newRole);
                $updates['status'] = $mappedStatus->value;
                $changedFields[] = 'status';

                if ($mappedStatus === UserStatus::SUSPENDED) {
                    $shouldRevokeTokens = true;
                } elseif ($mappedStatus === UserStatus::ACTIVE) {
                    $updates['failed_login_attempts'] = 0;
                    $updates['locked_until'] = null;
                }

                if ($newRole === UserRole::TEACHER && $target->teacherProfile) {
                    $target->teacherProfile->forceFill([
                        'is_verified_educator' => $teacherVerif === VerificationStatus::VERIFIED,
                        'verification_status' => $teacherVerif,
                        'verified_at' => $teacherVerif === VerificationStatus::VERIFIED ? now() : null,
                        'verified_by' => $teacherVerif === VerificationStatus::VERIFIED ? $admin->id : null,
                    ])->save();
                }
            }

            if (array_key_exists('degree_ar', $data) && $target->teacherProfile) {
                $target->teacherProfile->forceFill(['degree_ar' => $data['degree_ar']])->save();
                $changedFields[] = 'degree_ar';
            }

            if (! empty($updates)) {
                $target->forceFill($updates)->save();
            }

            if ($shouldRevokeTokens) {
                $this->tokenService->revokeAll($target);
            }
        });

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.user.updated', [
            'admin_id' => $admin->id,
            'target_user_id' => $target->id,
            'changed_fields' => array_values(array_unique($changedFields)),
        ], 'info', $target);

        $target->refresh()
            ->load(['studentProfile', 'teacherProfile', 'centerProfile'])
            ->loadCount(['courses', 'groupEnrollments', 'children', 'centerRooms']);

        return $this->formatUserRow($target, true);
    }

    /**
     * Toggle user status between active and suspended, immediately revoking all sessions upon suspension.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function toggleStatus(User $admin, int $userId): array
    {
        /** @var User $target */
        $target = User::query()->findOrFail($userId);

        $this->assertTargetMutableByAdmin($admin, $target, 'toggle_status');

        $nextStatus = $target->status === UserStatus::SUSPENDED
            ? UserStatus::ACTIVE
            : UserStatus::SUSPENDED;

        $this->transaction(function () use ($target, $nextStatus): void {
            $updates = ['status' => $nextStatus->value];
            if ($nextStatus === UserStatus::ACTIVE) {
                $updates['failed_login_attempts'] = 0;
                $updates['locked_until'] = null;
            }

            $target->forceFill($updates)->save();

            if ($nextStatus === UserStatus::SUSPENDED) {
                $this->tokenService->revokeAll($target);
            }
        });

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.user.status_toggled', [
            'admin_id' => $admin->id,
            'target_user_id' => $target->id,
            'new_status' => $nextStatus->value,
        ], $nextStatus === UserStatus::SUSPENDED ? 'warning' : 'info', $target);

        $target->refresh()
            ->load(['studentProfile', 'teacherProfile', 'centerProfile'])
            ->loadCount(['courses', 'groupEnrollments', 'children', 'centerRooms']);

        return $this->formatUserRow($target, false);
    }

    /**
     * Approve teacher or center verification / accreditation (KYC).
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function approveVerification(User $admin, int $userId, ?string $notes = null): array
    {
        /** @var User $target */
        $target = User::query()->with(['teacherProfile', 'centerProfile'])->findOrFail($userId);

        $this->assertTargetMutableByAdmin($admin, $target, 'verify');

        if (! in_array($target->role, [UserRole::TEACHER, UserRole::CENTER], true)) {
            throw ValidationException::withMessages([
                'user' => [__('messages.admin.verification_role_invalid')],
            ]);
        }

        $this->transaction(function () use ($admin, $target, $notes): void {
            $target->forceFill([
                'status' => UserStatus::ACTIVE->value,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            if ($target->isTeacher()) {
                $profile = $target->teacherProfile ?: TeacherProfile::create([
                    'user_id' => $target->id,
                    'name_ar' => $target->name,
                    'name_en' => $target->name,
                ]);
                $profile->forceFill([
                    'is_verified_educator' => true,
                    'verification_status' => VerificationStatus::VERIFIED,
                    'verification_notes' => $notes,
                    'verified_at' => now(),
                    'verified_by' => $admin->id,
                ])->save();
            } elseif ($target->isCenter()) {
                $profile = $target->centerProfile ?: CenterProfile::create([
                    'user_id' => $target->id,
                    'name_ar' => $target->name,
                    'name_en' => $target->name,
                ]);
                $profile->forceFill(['is_verified_center' => true])->save();
            }
        });

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.user.verified', [
            'admin_id' => $admin->id,
            'target_user_id' => $target->id,
            'target_role' => $target->role?->value,
        ], 'info', $target);

        $target->refresh()
            ->load(['studentProfile', 'teacherProfile', 'centerProfile'])
            ->loadCount(['courses', 'groupEnrollments', 'children', 'centerRooms']);

        return $this->formatUserRow($target, true);
    }

    /**
     * Reject teacher or center verification / accreditation and revoke active sessions.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function rejectVerification(User $admin, int $userId, ?string $reason = null): array
    {
        /** @var User $target */
        $target = User::query()->with(['teacherProfile', 'centerProfile'])->findOrFail($userId);

        $this->assertTargetMutableByAdmin($admin, $target, 'reject_verification');

        if (! in_array($target->role, [UserRole::TEACHER, UserRole::CENTER], true)) {
            throw ValidationException::withMessages([
                'user' => [__('messages.admin.verification_role_invalid')],
            ]);
        }

        $this->transaction(function () use ($admin, $target, $reason): void {
            $target->forceFill(['status' => UserStatus::SUSPENDED->value])->save();
            $this->tokenService->revokeAll($target);

            if ($target->isTeacher()) {
                $profile = $target->teacherProfile ?: TeacherProfile::create([
                    'user_id' => $target->id,
                    'name_ar' => $target->name,
                    'name_en' => $target->name,
                ]);
                $profile->forceFill([
                    'is_verified_educator' => false,
                    'verification_status' => VerificationStatus::REJECTED,
                    'verification_notes' => $reason,
                    'verified_at' => null,
                    'verified_by' => $admin->id,
                ])->save();
            } elseif ($target->isCenter() && $target->centerProfile) {
                $target->centerProfile->forceFill(['is_verified_center' => false])->save();
            }
        });

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.user.verification_rejected', [
            'admin_id' => $admin->id,
            'target_user_id' => $target->id,
            'reason' => $reason,
        ], 'warning', $target);

        $target->refresh()
            ->load(['studentProfile', 'teacherProfile', 'centerProfile'])
            ->loadCount(['courses', 'groupEnrollments', 'children', 'centerRooms']);

        return $this->formatUserRow($target, true);
    }

    /**
     * Trigger a password reset OTP email for a user and revoke active sessions.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function triggerPasswordReset(User $admin, int $userId, ?string $ip): array
    {
        /** @var User $target */
        $target = User::query()->findOrFail($userId);

        $this->assertTargetMutableByAdmin($admin, $target, 'reset_password');

        $code = $this->otpService->generate($target, VerificationCodeType::PASSWORD_RESET, $ip);
        $this->safeNotify($target, new ResetPasswordOtpNotification($code, (int) config('auth_security.otp.ttl_minutes')));
        $this->tokenService->revokeAll($target);

        $this->logSecurityEvent('admin.user.password_reset_triggered', [
            'admin_id' => $admin->id,
            'target_user_id' => $target->id,
        ], 'warning', $target);

        return [
            'userId' => $target->id,
            'email' => $target->email,
            'sessionsRevoked' => true,
            'otpDispatched' => true,
        ];
    }

    /**
     * Force-logout a user from all active devices immediately.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function revokeAllSessions(User $admin, int $userId): array
    {
        /** @var User $target */
        $target = User::query()->findOrFail($userId);

        $this->assertTargetMutableByAdmin($admin, $target, 'revoke_sessions');

        $count = (int) $target->tokens()->count();
        $this->tokenService->revokeAll($target);

        $this->logSecurityEvent('admin.user.sessions_revoked', [
            'admin_id' => $admin->id,
            'target_user_id' => $target->id,
            'revoked_tokens_count' => $count,
        ], 'warning', $target);

        return [
            'userId' => $target->id,
            'revokedCount' => $count,
        ];
    }

    /**
     * Permanently delete a non-admin user account inside a transaction with asset cleanup and audit trail.
     *
     * @throws AuthorizationException
     */
    public function deleteUser(User $admin, int $userId): void
    {
        /** @var User $target */
        $target = User::query()->findOrFail($userId);

        $this->assertTargetMutableByAdmin($admin, $target, 'delete');

        $this->transaction(function () use ($admin, $target): void {
            if ($target->avatar && preg_match('#/storage/(avatars/(?:students|teachers|centers)/[a-zA-Z0-9\-]+\.(jpg|png|webp))$#', $target->avatar, $matches)) {
                Storage::disk('public')->delete($matches[1]);
            }

            $storedFiles = StoredFile::query()
                ->where('user_id', $target->id)
                ->where('entity_type', 'user_avatar')
                ->get();

            foreach ($storedFiles as $file) {
                try {
                    if ($file->storage_disk && $file->storage_path) {
                        Storage::disk($file->storage_disk)->delete($file->storage_path);
                    }
                } catch (Throwable) {
                    // Do not fail user deletion on external storage errors
                }
            }

            $this->logSecurityEvent('admin.user.deleted', [
                'admin_id' => $admin->id,
                'deleted_user_id' => $target->id,
                'deleted_user_role' => $target->role?->value,
                'deleted_user_email' => $target->email,
            ], 'warning', $target);

            $this->tokenService->revokeAll($target);
            $target->delete();
        });

        $this->settingsService->bumpCacheEpoch();
    }

    /**
     * Export filtered users list as a formula-injection-safe UTF-8 CSV (PII masked).
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportUsersCsv(User $admin, array $filters = []): string
    {
        $role = $this->normalizeRoleFilter($filters['role'] ?? null);
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $query = User::query()->latest('id');
        if ($role !== null && $role !== 'all') {
            $query->where('role', $role);
        }
        if ($status !== null && $status !== '' && $status !== 'all') {
            $this->applyStatusFilter($query, $status);
        }
        if ($search !== null && $search !== '') {
            $safe = addcslashes($search, '%_\\');
            $query->where(function (Builder $q) use ($safe): void {
                $q->where('name', 'like', "%{$safe}%")
                    ->orWhere('email', 'like', "%{$safe}%")
                    ->orWhere('phone', 'like', "%{$safe}%");
            });
        }

        $rows = $query->limit(2000)->get();

        $headers = ['ID', 'الاسم', 'البريد الإلكتروني', 'الهاتف', 'الدور', 'الحالة', 'كود الطالب', 'الرقم القومي (مقنّع)', 'تاريخ الانضمام'];
        $escape = static function (mixed $val): string {
            $s = (string) ($val ?? '');
            if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
                $s = "'".$s;
            }

            return '"'.str_replace('"', '""', $s).'"';
        };

        $lines = [implode(',', array_map($escape, $headers))];
        foreach ($rows as $u) {
            $lines[] = implode(',', array_map($escape, [
                $u->id,
                $u->name,
                $u->email,
                $u->phone ?? '',
                $u->role?->value ?? '',
                $u->status?->value ?? '',
                $u->student_code ?? '',
                $u->maskedNationalId() ?? '',
                $u->created_at?->toDateString() ?? '',
            ]));
        }

        $this->logSecurityEvent('admin.users.exported_csv', [
            'admin_id' => $admin->id,
            'exported_count' => $rows->count(),
            'filters' => array_filter(['role' => $role, 'status' => $status]),
        ], 'warning');

        return "\xEF\xBB\xBF".implode("\n", $lines);
    }

    /**
     * Export filtered users & complete subscription history as a styled, RTL, formula-safe Excel (.xlsx) workbook.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportUsersExcel(User $admin, array $filters = []): string
    {
        $role = $this->normalizeRoleFilter($filters['role'] ?? null);
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;

        $query = User::query()
            ->with(['studentProfile', 'teacherProfile', 'centerProfile'])
            ->latest('id');

        if ($role !== null && $role !== 'all') {
            $query->where('role', $role);
        }
        if ($status !== null && $status !== '' && $status !== 'all') {
            $this->applyStatusFilter($query, $status);
        }
        if ($search !== null && $search !== '') {
            $safe = addcslashes($search, '%_\\');
            $nidHash = User::blindIndexNationalId($search);
            $query->where(function (Builder $q) use ($safe, $nidHash): void {
                $q->where('name', 'like', "%{$safe}%")
                    ->orWhere('email', 'like', "%{$safe}%")
                    ->orWhere('phone', 'like', "%{$safe}%")
                    ->orWhere('student_code', 'like', "%{$safe}%")
                    ->orWhere('national_id_hash', $nidHash);
            });
        }

        $users = $query->limit(3000)->get();
        $subscriptionsByUser = $this->loadSubscriptionsBatch($users);

        $roleAr = static fn (?UserRole $r): string => match ($r) {
            UserRole::STUDENT => 'طالب',
            UserRole::TEACHER => 'معلم',
            UserRole::PARENT => 'ولي أمر',
            UserRole::CENTER => 'سنتر تعليمي',
            UserRole::ADMIN => 'مشرف المنصة',
            default => '',
        };

        $effectiveStatusAr = static fn (string $st): string => match ($st) {
            'verified' => 'موثق ومعتمد',
            'pending_verification' => 'بانتظار الاعتماد',
            'suspended' => 'حساب مجمد',
            default => 'نشط',
        };

        $subStatusAr = static fn (string $st): string => match ($st) {
            'active' => 'نشط',
            'expired' => 'منتهي',
            'cancelled' => 'ملغي',
            'pending' => 'قيد الانتظار',
            default => $st,
        };

        $userHeaders = [
            'ID',
            'الاسم',
            'البريد الإلكتروني',
            'الهاتف',
            'الدور',
            'حالة الحساب',
            'كود الطالب',
            'الرقم القومي (مقنّع)',
            'الباقة الحالية',
            'حالة الاشتراك',
            'بداية الباقة',
            'انتهاء الباقة',
            'المتبقي (يوم)',
            'إجمالي الاشتراكات',
            'تاريخ الانضمام',
        ];

        $userRows = [];
        $subscriptionRows = [];

        foreach ($users as $u) {
            $userSubs = $subscriptionsByUser[$u->id] ?? [];
            $currentSub = $this->pickActiveSubscription($userSubs) ?? ($userSubs[0] ?? null);
            $effectiveStatus = $this->resolveEffectiveStatus($u);

            $userRows[] = [
                (int) $u->id,
                $u->name,
                $u->email,
                $u->phone ?? '',
                $roleAr($u->role),
                $effectiveStatusAr($effectiveStatus),
                $u->student_code ?? '',
                $u->maskedNationalId() ?? '',
                $currentSub ? $currentSub['planNameAr'] : 'الباقة المجانية',
                $currentSub ? $subStatusAr($currentSub['status']) : 'مجاني',
                $currentSub['startsDate'] ?? '—',
                $currentSub['endsDate'] ?? '—',
                $currentSub ? (int) $currentSub['daysRemaining'] : 0,
                count($userSubs),
                $u->created_at?->toDateString() ?? '',
            ];

            foreach ($userSubs as $sub) {
                $subscriptionRows[] = [
                    (int) $u->id,
                    $u->name,
                    $u->email,
                    $roleAr($u->role),
                    $sub['planNameAr'],
                    strtoupper((string) $sub['planTier']),
                    $sub['billingPeriodLabelAr'],
                    (float) $sub['amountEgp'],
                    $subStatusAr($sub['status']),
                    $sub['startsDate'] ?? '—',
                    $sub['endsDate'] ?? '—',
                    (int) $sub['daysRemaining'],
                ];
            }
        }

        $subHeaders = [
            'رقم المستخدم',
            'اسم المستخدم',
            'البريد الإلكتروني',
            'الدور',
            'اسم الباقة',
            'الفئة',
            'دورة الفوترة',
            'المبلغ (ج.م)',
            'حالة الاشتراك',
            'تاريخ الاشتراك',
            'تاريخ الانتهاء',
            'الأيام المتبقية',
        ];

        $workbook = (new ExcelWorkbookBuilder)
            ->addSheet('المستخدمون', $userHeaders, $userRows, true)
            ->addSheet('سجل الباقات والاشتراكات', $subHeaders, $subscriptionRows, true);

        $binary = $workbook->buildBinary();

        $this->logSecurityEvent('admin.users.exported_excel', [
            'admin_id' => $admin->id,
            'exported_users_count' => $users->count(),
            'exported_subscriptions_count' => count($subscriptionRows),
            'filters' => array_filter(['role' => $role, 'status' => $status]),
        ], 'warning');

        return $binary;
    }

    /**
     * SECURITY GUARD: Prevents an admin from modifying, suspending, deleting, or resetting themselves or peer admins.
     *
     * @throws AuthorizationException
     */
    protected function assertTargetMutableByAdmin(User $admin, User $target, string $action): void
    {
        if ($admin->id === $target->id || $target->isAdmin()) {
            $this->logSecurityEvent('admin.self_or_peer_admin_mutation_blocked', [
                'admin_id' => $admin->id,
                'target_user_id' => $target->id,
                'action' => $action,
            ], 'warning', $target);

            throw new AuthorizationException(__('messages.admin.cannot_modify_admin_account'));
        }
    }

    /**
     * @return array<string, int>
     */
    protected function computeSummaryStats(): array
    {
        $roleCounts = User::query()
            ->select('role', DB::raw('COUNT(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role')
            ->all();

        $pendingVerifications = (int) User::query()
            ->where(function (Builder $q): void {
                $q->where('status', UserStatus::PENDING->value)
                    ->orWhereHas('teacherProfile', fn (Builder $tp) => $tp->where('verification_status', VerificationStatus::PENDING->value))
                    ->orWhereHas('centerProfile', fn (Builder $cp) => $cp->where('is_verified_center', false));
            })
            ->count();
        $suspendedCount = (int) User::query()->where('status', UserStatus::SUSPENDED->value)->count();

        $students = (int) ($roleCounts[UserRole::STUDENT->value] ?? 0);
        $teachers = (int) ($roleCounts[UserRole::TEACHER->value] ?? 0);
        $parents = (int) ($roleCounts[UserRole::PARENT->value] ?? 0);
        $centers = (int) ($roleCounts[UserRole::CENTER->value] ?? 0);
        $admins = (int) ($roleCounts[UserRole::ADMIN->value] ?? 0);

        return [
            'total' => $students + $teachers + $parents + $centers + $admins,
            'students' => $students,
            'teachers' => $teachers,
            'parents' => $parents,
            'centers' => $centers,
            'admins' => $admins,
            'pendingVerifications' => $pendingVerifications,
            'suspended' => $suspendedCount,
        ];
    }

    protected function normalizeRoleFilter(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $clean = strtolower(trim((string) $raw));
        if ($clean === 'center_admin') {
            return UserRole::CENTER->value;
        }

        if ($clean === 'all' || in_array($clean, UserRole::values(), true)) {
            return $clean;
        }

        return null;
    }

    protected function applyStatusFilter(Builder $query, string $status): void
    {
        if ($status === 'pending' || $status === 'pending_verification') {
            $query->where(function (Builder $q): void {
                $q->where('status', UserStatus::PENDING->value)
                    ->orWhereHas('teacherProfile', fn (Builder $tp) => $tp->where('verification_status', VerificationStatus::PENDING->value))
                    ->orWhereHas('centerProfile', fn (Builder $cp) => $cp->where('is_verified_center', false));
            });
        } elseif ($status === 'verified') {
            $query->where('status', UserStatus::ACTIVE->value)
                ->where(function (Builder $q): void {
                    $q->whereHas('teacherProfile', fn (Builder $tp) => $tp->where('verification_status', VerificationStatus::VERIFIED->value))
                        ->orWhereHas('centerProfile', fn (Builder $cp) => $cp->where('is_verified_center', true));
                });
        } elseif (in_array($status, UserStatus::values(), true)) {
            $query->where('status', $status);
        }
    }

    /**
     * @return array{0: UserStatus, 1: VerificationStatus}
     */
    protected function resolveStatusMapping(string $rawStatus, UserRole $role): array
    {
        return match ($rawStatus) {
            'suspended' => [UserStatus::SUSPENDED, VerificationStatus::REJECTED],
            'pending', 'pending_verification' => [UserStatus::PENDING, VerificationStatus::PENDING],
            'verified' => [UserStatus::ACTIVE, VerificationStatus::VERIFIED],
            default => [
                UserStatus::ACTIVE,
                $role === UserRole::TEACHER ? VerificationStatus::VERIFIED : VerificationStatus::VERIFIED,
            ],
        };
    }

    protected function resolveEffectiveStatus(User $u): string
    {
        if ($u->status === UserStatus::SUSPENDED) {
            return 'suspended';
        }

        if ($u->status === UserStatus::PENDING) {
            return 'pending_verification';
        }

        if ($u->isTeacher() && $u->teacherProfile) {
            $vs = $u->teacherProfile->verification_status;
            if ($vs === VerificationStatus::PENDING) {
                return 'pending_verification';
            }
            if ($vs === VerificationStatus::VERIFIED || $u->teacherProfile->is_verified_educator) {
                return 'verified';
            }
        }

        if ($u->isCenter() && $u->centerProfile?->is_verified_center) {
            return 'verified';
        }

        return 'active';
    }

    /**
     * Batch-load all subscription rows for a collection of users in at most 3 indexed queries (zero N+1).
     *
     * @param  Collection<int, User>  $users
     * @return array<int, list<array<string, mixed>>>
     */
    protected function loadSubscriptionsBatch(Collection $users): array
    {
        $studentIds = $users->filter(fn (User $u) => $u->isStudent())->pluck('id')->all();
        $teacherIds = $users->filter(fn (User $u) => $u->isTeacher())->pluck('id')->all();
        $centerIds = $users->filter(fn (User $u) => $u->isCenter())->pluck('id')->all();

        $map = [];

        if (! empty($studentIds)) {
            $subs = StudentSubscription::query()
                ->whereIn('student_id', $studentIds)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get();

            foreach ($subs as $sub) {
                $map[(int) $sub->student_id][] = $this->formatSubscriptionRecord($sub, 'student');
            }
        }

        if (! empty($teacherIds)) {
            $subs = TeacherSubscription::query()
                ->whereIn('teacher_id', $teacherIds)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get();

            foreach ($subs as $sub) {
                $map[(int) $sub->teacher_id][] = $this->formatSubscriptionRecord($sub, 'teacher');
            }
        }

        if (! empty($centerIds)) {
            $subs = CenterSubscription::query()
                ->whereIn('center_id', $centerIds)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get();

            foreach ($subs as $sub) {
                $map[(int) $sub->center_id][] = $this->formatSubscriptionRecord($sub, 'center');
            }
        }

        return $map;
    }

    /**
     * Load complete subscription history for a single user (including linked children's plans for parent accounts).
     *
     * @return list<array<string, mixed>>
     */
    protected function loadAllSubscriptionsForUser(User $user): array
    {
        if ($user->isStudent()) {
            return StudentSubscription::query()
                ->where('student_id', $user->id)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (StudentSubscription $s) => $this->formatSubscriptionRecord($s, 'student'))
                ->values()
                ->all();
        }

        if ($user->isTeacher()) {
            return TeacherSubscription::query()
                ->where('teacher_id', $user->id)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (TeacherSubscription $s) => $this->formatSubscriptionRecord($s, 'teacher'))
                ->values()
                ->all();
        }

        if ($user->isCenter()) {
            return CenterSubscription::query()
                ->where('center_id', $user->id)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (CenterSubscription $s) => $this->formatSubscriptionRecord($s, 'center'))
                ->values()
                ->all();
        }

        if ($user->isParent()) {
            $childrenById = $user->relationLoaded('children')
                ? $user->children->keyBy('id')
                : $user->children()->get(['id', 'name', 'student_code'])->keyBy('id');

            if ($childrenById->isEmpty()) {
                return [];
            }

            return StudentSubscription::query()
                ->whereIn('student_id', $childrenById->keys()->all())
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get()
                ->map(function (StudentSubscription $s) use ($childrenById): array {
                    $child = $childrenById->get($s->student_id);

                    return $this->formatSubscriptionRecord(
                        $s,
                        'student',
                        $child?->name,
                        $child?->student_code
                    );
                })
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * Normalize StudentSubscription / TeacherSubscription / CenterSubscription into a single clean DTO.
     *
     * @return array<string, mixed>
     */
    protected function formatSubscriptionRecord(
        StudentSubscription|TeacherSubscription|CenterSubscription $sub,
        string $scopeRole,
        ?string $ownerName = null,
        ?string $ownerCode = null
    ): array {
        $tierEnum = $sub->plan_tier;
        $tierValue = is_object($tierEnum) ? (string) $tierEnum->value : strtolower((string) ($tierEnum ?? 'free'));

        $periodEnum = $sub->billing_period instanceof BillingPeriod
            ? $sub->billing_period
            : (BillingPeriod::tryFrom((string) $sub->billing_period) ?? BillingPeriod::MONTHLY);

        $periodEn = match ($periodEnum) {
            BillingPeriod::ANNUAL => 'Annual',
            BillingPeriod::TERM => 'Term / Half-Year',
            default => 'Monthly',
        };

        $startsAt = $sub->starts_at ? Carbon::parse($sub->starts_at) : ($sub->created_at ? Carbon::parse($sub->created_at) : null);
        $endsAt = $sub->renews_at ? Carbon::parse($sub->renews_at) : null;
        $cancelledAt = isset($sub->cancelled_at) && $sub->cancelled_at ? Carbon::parse($sub->cancelled_at) : null;

        $rawStatus = strtolower(trim((string) ($sub->status ?: 'active')));
        $now = Carbon::now();

        if ($cancelledAt !== null || $rawStatus === 'cancelled' || $rawStatus === 'canceled') {
            $effectiveStatus = 'cancelled';
        } elseif ($rawStatus === 'expired' || ($endsAt !== null && $endsAt->isPast() && $tierValue !== 'free')) {
            $effectiveStatus = 'expired';
        } elseif ($rawStatus === 'pending') {
            $effectiveStatus = 'pending';
        } else {
            $effectiveStatus = 'active';
        }

        $totalDays = ($startsAt && $endsAt && $endsAt->gt($startsAt))
            ? max(1, (int) ceil($startsAt->diffInHours($endsAt) / 24))
            : $periodEnum->durationDays();

        $daysRemaining = 0;
        if ($effectiveStatus === 'active' && $endsAt !== null && $endsAt->isFuture()) {
            $daysRemaining = max(1, (int) ceil($now->diffInHours($endsAt, false) / 24));
        }

        $daysElapsed = 0;
        if ($startsAt !== null) {
            $refEnd = ($effectiveStatus === 'active') ? $now : ($cancelledAt ?? $endsAt ?? $now);
            $daysElapsed = min($totalDays, max(0, (int) floor(max(0, $startsAt->diffInSeconds($refEnd, false)) / 86400)));
        }

        $progressPercent = $effectiveStatus === 'active'
            ? ($endsAt ? min(100, max(0, (int) round(($daysElapsed / max(1, $totalDays)) * 100))) : 0)
            : 100;

        $defaultNameAr = method_exists($tierEnum, 'labelAr') ? $tierEnum->labelAr() : strtoupper($tierValue);
        $defaultNameEn = method_exists($tierEnum, 'labelEn') ? $tierEnum->labelEn() : strtoupper($tierValue);

        return [
            'id' => (string) ($sub->uuid ?? "{$scopeRole}-sub-{$sub->id}"),
            'scopeRole' => $scopeRole,
            'ownerName' => $ownerName,
            'ownerCode' => $ownerCode,
            'planTier' => $tierValue,
            'planNameAr' => $sub->plan_name_ar ?: $defaultNameAr,
            'planNameEn' => $sub->plan_name_en ?: $defaultNameEn,
            'billingPeriod' => $periodEnum->value,
            'billingPeriodLabelAr' => $periodEnum->label(),
            'billingPeriodLabelEn' => $periodEn,
            'amountEgp' => round((float) ($sub->amount_egp ?? 0), 2),
            'status' => $effectiveStatus,
            'isCurrent' => $effectiveStatus === 'active',
            'startsAt' => $startsAt?->toIso8601String(),
            'startsDate' => $startsAt?->toDateString(),
            'endsAt' => $endsAt?->toIso8601String(),
            'endsDate' => $endsAt?->toDateString(),
            'cancelledAt' => $cancelledAt?->toIso8601String(),
            'cancelledDate' => $cancelledAt?->toDateString(),
            'totalDays' => $totalDays,
            'daysElapsed' => $daysElapsed,
            'daysRemaining' => $daysRemaining,
            'progressPercent' => $progressPercent,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $subscriptions
     * @return array<string, mixed>|null
     */
    protected function pickActiveSubscription(array $subscriptions): ?array
    {
        foreach ($subscriptions as $sub) {
            if (($sub['status'] ?? '') === 'active') {
                return $sub;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $subscriptions
     * @return array{totalCount: int, activeCount: int, expiredCount: int, cancelledCount: int, totalSpentEgp: float}
     */
    protected function summarizeSubscriptions(array $subscriptions): array
    {
        $active = 0;
        $expired = 0;
        $cancelled = 0;
        $spent = 0.0;

        foreach ($subscriptions as $sub) {
            $st = (string) ($sub['status'] ?? '');
            if ($st === 'active') {
                $active++;
            } elseif ($st === 'expired') {
                $expired++;
            } elseif ($st === 'cancelled') {
                $cancelled++;
            }
            $spent += (float) ($sub['amountEgp'] ?? 0);
        }

        return [
            'totalCount' => count($subscriptions),
            'activeCount' => $active,
            'expiredCount' => $expired,
            'cancelledCount' => $cancelled,
            'totalSpentEgp' => round($spent, 2),
        ];
    }

    /**
     * Format user row cleanly matching AdminUsersManager.jsx while keeping PII masked in list views.
     *
     * @param  list<array<string, mixed>>  $userSubscriptions
     * @return array<string, mixed>
     */
    protected function formatUserRow(User $u, bool $includeDetailedPii, array $userSubscriptions = []): array
    {
        $sp = $u->studentProfile;
        $tp = $u->teacherProfile;
        $cp = $u->centerProfile;

        $detailsAr = $u->admin_details_ar;
        if (! $detailsAr) {
            if ($u->isStudent() && $sp) {
                $detailsAr = implode(' — ', array_filter([$sp->grade_ar, $sp->track_ar, $sp->governorate]));
            } elseif ($u->isTeacher() && $tp) {
                $detailsAr = implode(' - ', array_filter([$tp->subject_ar, $tp->title_ar ?: $tp->degree_ar]));
            } elseif ($u->isCenter() && $cp) {
                $detailsAr = implode(' - ', array_filter([$cp->name_ar, $cp->address_ar]));
            }
        }

        $currentSub = $this->pickActiveSubscription($userSubscriptions) ?? ($userSubscriptions[0] ?? null);

        return [
            'id' => $u->id,
            'name' => $u->name,
            'nameAr' => $u->name,
            'nameEn' => $tp?->name_en ?: ($cp?->name_en ?: $u->name),
            'role' => $u->role?->value,
            'roleLabel' => $u->role?->label(),
            'email' => $u->email,
            'phone' => $u->phone,
            'avatar' => $u->avatar,
            'studentCode' => $u->student_code,
            'status' => $this->resolveEffectiveStatus($u),
            'accountStatus' => $u->status?->value,
            'details' => $detailsAr ?: '',
            'detailsAr' => $detailsAr ?: '',
            'detailsEn' => $u->admin_details_en ?: ($detailsAr ?: ''),
            'grade' => $sp?->grade_ar,
            'track' => $sp?->track_ar,
            'governorate' => $sp?->governorate,
            'parentPhone' => $sp?->parent_phone,
            'degreeAr' => $tp?->degree_ar,
            'backgroundCheckStatus' => $tp?->background_check_status,
            'nationalIdMasked' => $u->maskedNationalId(),
            'nationalId' => $includeDetailedPii ? $u->maskedNationalId() : null,
            'enrolledCourses' => (int) ($u->isTeacher() ? ($u->courses_count ?? 0) : ($u->group_enrollments_count ?? 0)),
            'linkedStudents' => $u->isParent() ? (int) ($u->children_count ?? 0) : null,
            'hallsCount' => $u->isCenter() ? (int) ($u->center_rooms_count ?? 0) : null,
            'currentSubscription' => $currentSub,
            'subscriptionsCount' => count($userSubscriptions),
            'joinDate' => $u->created_at?->toDateString(),
            'lastLoginAt' => $u->last_login_at?->toIso8601String(),
        ];
    }
}
