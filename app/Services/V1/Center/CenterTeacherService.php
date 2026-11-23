<?php

namespace App\Services\V1\Center;

use App\Enums\UserRole;
use App\Models\CenterBranch;
use App\Models\CenterProfile;
use App\Models\CenterTeacher;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class CenterTeacherService extends BaseService
{
    public function __construct(
        protected CenterBranchService $branchService
    ) {}

    /**
     * List all teachers affiliated with the center.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTeachers(User $center, array $filters = []): array
    {
        $query = CenterTeacher::forCenter($center->id)
            ->with([
                'branch:id,uuid,name_ar,name_en',
                'teacherUser:id,name,email,phone,avatar',
            ])
            ->withCount(['studyGroups as enrolled_groups_count' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('created_at');

        if (! empty($filters['branch_id']) && $filters['branch_id'] !== 'all') {
            $branch = $this->branchService->findOwnedBranch($center, (string) $filters['branch_id']);
            $query->where('branch_id', $branch->id);
        }

        if (! empty($filters['status'])) {
            $query->where('status', trim((string) $filters['status']));
        }

        if (! empty($filters['search'])) {
            $q = trim((string) $filters['search']);
            $query->where(function ($sub) use ($q) {
                $sub->where('name_ar', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('subject_ar', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        return $query->get()
            ->map(fn (CenterTeacher $t) => $this->formatTeacher($t))
            ->values()
            ->all();
    }

    /**
     * Find a center teacher by UUID and strictly enforce center ownership.
     */
    public function findOwnedTeacher(User $center, string $uuid): CenterTeacher
    {
        /** @var CenterTeacher|null $teacher */
        $teacher = CenterTeacher::where('uuid', $uuid)
            ->with(['branch', 'teacherUser:id,name,email,phone,avatar'])
            ->withCount(['studyGroups as enrolled_groups_count' => fn ($q) => $q->where('status', 'active')])
            ->first();

        if (! $teacher) {
            throw (new ModelNotFoundException)->setModel(CenterTeacher::class, [$uuid]);
        }

        $teacher->assertOwnedByCenter($center);

        return $teacher;
    }

    /**
     * Add & accredit a teacher for the center, auto-linking existing platform accounts when matched.
     *
     * @return array<string, mixed>
     */
    public function createTeacher(User $center, array $data): array
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = isset($data['phone']) ? trim(strip_tags((string) $data['phone'])) : null;

        // Prevent active duplicate email within the same center
        $duplicate = CenterTeacher::forCenter($center->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'email' => [__('messages.center.teacher_email_duplicate')],
            ]);
        }

        $branchId = $this->resolveOwnedBranchId($center, $data['branch_id'] ?? $data['branchId'] ?? null);
        $defaultRate = (float) (CenterProfile::where('user_id', $center->id)->value('default_teacher_settlement_rate') ?? 0.75);
        $settlementRate = isset($data['settlement_rate'])
            ? max(0.05, min(0.95, (float) $data['settlement_rate']))
            : $defaultRate;

        // Auto-link to existing platform Teacher User if email or phone matches
        $linkedUser = User::where('role', UserRole::TEACHER->value)
            ->where(function ($q) use ($email, $phone) {
                $q->whereRaw('LOWER(email) = ?', [$email]);
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->first();

        $tokenPair = CenterTeacher::generateActivationToken();

        $teacher = $this->transaction(function () use ($center, $branchId, $linkedUser, $email, $phone, $settlementRate, $tokenPair, $data) {
            $nameAr = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? '')));
            $nameEn = trim(strip_tags((string) ($data['name_en'] ?? $data['nameEn'] ?? $nameAr)));
            $subjectAr = trim(strip_tags((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? 'مادة دراسية')));
            $subjectEn = trim(strip_tags((string) ($data['subject_en'] ?? $data['subjectEn'] ?? $subjectAr)));

            /** @var CenterTeacher $created */
            $created = CenterTeacher::create([
                'center_id' => $center->id,
                'branch_id' => $branchId,
                'teacher_user_id' => $linkedUser?->id,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'subject_ar' => $subjectAr,
                'subject_en' => $subjectEn,
                'phone' => $phone,
                'email' => $email,
                'settlement_rate' => $settlementRate,
                'status' => 'active',
                'invite_token_hash' => $tokenPair['hash'],
                'invite_sent_at' => now(),
                'activated_at' => $linkedUser ? now() : null,
            ]);

            $this->logSecurityEvent('center.teacher.created', [
                'center_teacher_uuid' => $created->uuid,
                'email' => $email,
                'linked_user_id' => $linkedUser?->id,
            ], 'info', $created);

            return $created->load(['branch', 'teacherUser:id,name,email,phone,avatar']);
        });

        $formatted = $this->formatTeacher($teacher);
        $formatted['activationLink'] = $this->buildActivationUrl($tokenPair['plain']);

        return $formatted;
    }

    /**
     * Update an affiliated center teacher's profile, branch, settlement rate, or status.
     *
     * @return array<string, mixed>
     */
    public function updateTeacher(User $center, string $uuid, array $data): array
    {
        $teacher = $this->findOwnedTeacher($center, $uuid);

        $hasBranchInput = array_key_exists('branch_id', $data) || array_key_exists('branchId', $data);
        $branchId = $hasBranchInput
            ? $this->resolveOwnedBranchId($center, $data['branch_id'] ?? $data['branchId'])
            : null;

        if (isset($data['email'])) {
            $newEmail = strtolower(trim((string) $data['email']));
            if ($newEmail !== strtolower((string) $teacher->email)) {
                $exists = CenterTeacher::forCenter($center->id)
                    ->where('id', '!=', $teacher->id)
                    ->whereRaw('LOWER(email) = ?', [$newEmail])
                    ->exists();
                if ($exists) {
                    throw ValidationException::withMessages([
                        'email' => [__('messages.center.teacher_email_duplicate')],
                    ]);
                }
            }
        }

        $updated = $this->transaction(function () use ($teacher, $data, $hasBranchInput, $branchId) {
            $payload = [];

            if ($hasBranchInput) {
                $payload['branch_id'] = $branchId;
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
            if (array_key_exists('subject_en', $data) || array_key_exists('subjectEn', $data)) {
                $payload['subject_en'] = trim(strip_tags((string) ($data['subject_en'] ?? $data['subjectEn'])));
            }
            if (array_key_exists('phone', $data)) {
                $payload['phone'] = trim(strip_tags((string) $data['phone']));
            }
            if (array_key_exists('email', $data)) {
                $payload['email'] = strtolower(trim((string) $data['email']));
            }
            if (array_key_exists('settlement_rate', $data) || array_key_exists('settlementRate', $data)) {
                $payload['settlement_rate'] = max(0.05, min(0.95, (float) ($data['settlement_rate'] ?? $data['settlementRate'])));
            }
            if (array_key_exists('status', $data) && in_array($data['status'], ['active', 'invited', 'suspended'], true)) {
                $payload['status'] = $data['status'];
            }

            if (! empty($payload)) {
                $teacher->update($payload);
            }

            if (isset($payload['name_ar'])) {
                StudyGroup::where('center_id', $teacher->center_id)
                    ->where('center_teacher_id', $teacher->id)
                    ->update(['teacher_name_override' => $payload['name_ar']]);
            }

            $this->logSecurityEvent('center.teacher.updated', [
                'center_teacher_uuid' => $teacher->uuid,
                'changes' => array_keys($payload),
            ], 'info', $teacher);

            return $teacher->fresh(['branch', 'teacherUser:id,name,email,phone,avatar'])
                ?->loadCount(['studyGroups as enrolled_groups_count' => fn ($q) => $q->where('status', 'active')]) ?? $teacher;
        });

        return $this->formatTeacher($updated);
    }

    /**
     * Soft-delete a teacher affiliation from the center.
     */
    public function deleteTeacher(User $center, string $uuid): bool
    {
        $teacher = $this->findOwnedTeacher($center, $uuid);

        return $this->transaction(function () use ($teacher) {
            $tUuid = $teacher->uuid;
            $tName = $teacher->name_ar;
            $teacher->delete();

            $this->logSecurityEvent('center.teacher.deleted', [
                'center_teacher_uuid' => $tUuid,
                'name_ar' => $tName,
            ], 'warning', $teacher);

            return true;
        });
    }

    /**
     * Rotate activation token and resend invitation link for an affiliated teacher.
     *
     * @return array<string, mixed>
     */
    public function resendInvite(User $center, string $uuid): array
    {
        $teacher = $this->findOwnedTeacher($center, $uuid);
        $tokenPair = CenterTeacher::generateActivationToken();

        $teacher->update([
            'invite_token_hash' => $tokenPair['hash'],
            'invite_sent_at' => now(),
        ]);

        $activationLink = $this->buildActivationUrl($tokenPair['plain']);

        $this->logSecurityEvent('center.teacher.invite_resent', [
            'center_teacher_uuid' => $teacher->uuid,
            'email' => $teacher->email,
        ], 'info', $teacher);

        return [
            'teacherId' => $teacher->uuid,
            'email' => $teacher->email,
            'activationLink' => $activationLink,
            'inviteSentAt' => $teacher->invite_sent_at?->toIso8601String(),
        ];
    }

    /**
     * Format a CenterTeacher for API responses (never exposing invite_token_hash).
     *
     * @return array<string, mixed>
     */
    public function formatTeacher(CenterTeacher $teacher): array
    {
        $statusAr = match ($teacher->status) {
            'active' => 'معتمد ونشط بالمنصة',
            'invited' => 'بانتظار التفعيل',
            'suspended' => 'موقوف مؤقتاً',
            default => 'نشط',
        };

        return [
            'id' => $teacher->uuid,
            'uuid' => $teacher->uuid,
            'teacherUserId' => $teacher->teacher_user_id,
            'nameAr' => $teacher->name_ar,
            'nameEn' => $teacher->name_en ?: $teacher->name_ar,
            'subjectAr' => $teacher->subject_ar,
            'subjectEn' => $teacher->subject_en ?: $teacher->subject_ar,
            'phone' => $teacher->phone ?? '',
            'email' => $teacher->email,
            'branchId' => $teacher->branch?->uuid,
            'branchNameAr' => $teacher->branch?->name_ar ?? 'الفرع الرئيسي',
            'settlementRate' => (float) $teacher->settlement_rate,
            'status' => $teacher->status,
            'statusAr' => $statusAr,
            'inviteSent' => $teacher->invite_sent_at !== null,
            'inviteSentAt' => $teacher->invite_sent_at?->toIso8601String(),
            'isPlatformLinked' => $teacher->teacher_user_id !== null,
            'avatar' => $teacher->teacherUser?->avatar,
            'enrolledGroupsCount' => (int) ($teacher->enrolled_groups_count ?? 0),
            'createdAt' => $teacher->created_at?->toDateString(),
        ];
    }

    protected function buildActivationUrl(string $plainToken): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url', 'https://motafawweq.edu.eg')), '/');

        return "{$base}/teacher/activate?token={$plainToken}";
    }

    protected function resolveOwnedBranchId(User $center, mixed $branchUuid): ?int
    {
        if (empty($branchUuid) || $branchUuid === 'all') {
            return CenterBranch::forCenter($center->id)->orderByDesc('is_main')->value('id');
        }

        /** @var CenterBranch|null $branch */
        $branch = CenterBranch::where('uuid', (string) $branchUuid)->first();
        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => [__('messages.center.branch_not_found')],
            ]);
        }

        $branch->assertOwnedByCenter($center);

        return $branch->id;
    }
}
