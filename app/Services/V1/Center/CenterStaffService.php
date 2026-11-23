<?php

namespace App\Services\V1\Center;

use App\Enums\CenterStaffRole;
use App\Models\CenterBranch;
use App\Models\CenterStaff;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CenterStaffService extends BaseService
{
    public function __construct(
        protected CenterBranchService $branchService
    ) {}

    /**
     * List all administrative staff members owned by the center.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listStaff(User $center, array $filters = []): array
    {
        $query = CenterStaff::forCenter($center->id)
            ->with('branch:id,uuid,name_ar')
            ->orderByDesc('created_at');

        if (! empty($filters['branch_id']) && $filters['branch_id'] !== 'all') {
            $branch = $this->branchService->findOwnedBranch($center, (string) $filters['branch_id']);
            $query->where('branch_id', $branch->id);
        }

        if (! empty($filters['role'])) {
            $query->where('role', trim((string) $filters['role']));
        }

        if (! empty($filters['search'])) {
            $q = trim((string) $filters['search']);
            $query->where(function ($sub) use ($q) {
                $sub->where('name_ar', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('role_ar', 'like', "%{$q}%");
            });
        }

        return $query->get()
            ->map(fn (CenterStaff $s) => $this->formatStaff($s))
            ->values()
            ->all();
    }

    /**
     * Find a staff record by UUID and enforce strict center ownership.
     */
    public function findOwnedStaff(User $center, string $uuid): CenterStaff
    {
        /** @var CenterStaff|null $staff */
        $staff = CenterStaff::where('uuid', $uuid)->with('branch')->first();
        if (! $staff) {
            throw (new ModelNotFoundException)->setModel(CenterStaff::class, [$uuid]);
        }

        $staff->assertOwnedByCenter($center);

        return $staff;
    }

    /**
     * Create a new staff member.
     *
     * @return array<string, mixed>
     */
    public function createStaff(User $center, array $data): array
    {
        $branchId = $this->resolveOwnedBranchId($center, $data['branch_id'] ?? $data['branchId'] ?? null);
        $roleEnum = CenterStaffRole::tryFrom(strtolower(trim((string) ($data['role'] ?? 'receptionist'))))
            ?? CenterStaffRole::RECEPTIONIST;

        $permissions = $this->sanitizePermissions($data['permissions'] ?? $roleEnum->defaultPermissions());

        $staff = $this->transaction(function () use ($center, $branchId, $roleEnum, $permissions, $data) {
            $nameAr = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? '')));
            $nameEn = trim(strip_tags((string) ($data['name_en'] ?? $data['nameEn'] ?? $nameAr)));
            $roleAr = trim(strip_tags((string) ($data['role_ar'] ?? $data['roleAr'] ?? $roleEnum->labelAr())));

            /** @var CenterStaff $created */
            $created = CenterStaff::create([
                'center_id' => $center->id,
                'branch_id' => $branchId,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'phone' => isset($data['phone']) ? trim(strip_tags((string) $data['phone'])) : null,
                'email' => isset($data['email']) ? strtolower(trim((string) $data['email'])) : null,
                'role' => $roleEnum,
                'role_ar' => $roleAr,
                'salary_egp' => max(0.0, (float) ($data['salary_egp'] ?? $data['salaryEgp'] ?? 0.0)),
                'permissions' => $permissions,
                'status' => 'active',
            ]);

            $this->logSecurityEvent('center.staff.created', [
                'staff_uuid' => $created->uuid,
                'role' => $roleEnum->value,
                'branch_id' => $branchId,
            ], 'info', $created);

            return $created->load('branch');
        });

        return $this->formatStaff($staff);
    }

    /**
     * Update an existing staff member.
     *
     * @return array<string, mixed>
     */
    public function updateStaff(User $center, string $uuid, array $data): array
    {
        $staff = $this->findOwnedStaff($center, $uuid);

        $hasBranchInput = array_key_exists('branch_id', $data) || array_key_exists('branchId', $data);
        $branchId = $hasBranchInput
            ? $this->resolveOwnedBranchId($center, $data['branch_id'] ?? $data['branchId'])
            : null;

        $updated = $this->transaction(function () use ($staff, $data, $hasBranchInput, $branchId) {
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
            if (array_key_exists('phone', $data)) {
                $payload['phone'] = trim(strip_tags((string) $data['phone']));
            }
            if (array_key_exists('email', $data)) {
                $payload['email'] = $data['email'] ? strtolower(trim((string) $data['email'])) : null;
            }
            if (array_key_exists('role', $data)) {
                $roleEnum = CenterStaffRole::tryFrom(strtolower(trim((string) $data['role'])));
                if ($roleEnum) {
                    $payload['role'] = $roleEnum;
                }
            }
            if (array_key_exists('role_ar', $data) || array_key_exists('roleAr', $data)) {
                $payload['role_ar'] = trim(strip_tags((string) ($data['role_ar'] ?? $data['roleAr'])));
            }
            if (array_key_exists('salary_egp', $data) || array_key_exists('salaryEgp', $data)) {
                $payload['salary_egp'] = max(0.0, (float) ($data['salary_egp'] ?? $data['salaryEgp']));
            }
            if (array_key_exists('permissions', $data)) {
                $payload['permissions'] = $this->sanitizePermissions($data['permissions']);
            }
            if (array_key_exists('status', $data) && in_array($data['status'], ['active', 'inactive'], true)) {
                $payload['status'] = $data['status'];
            }

            if (! empty($payload)) {
                $staff->update($payload);
            }

            $this->logSecurityEvent('center.staff.updated', [
                'staff_uuid' => $staff->uuid,
                'changes' => array_keys($payload),
            ], 'info', $staff);

            return $staff->fresh(['branch']);
        });

        return $this->formatStaff($updated);
    }

    /**
     * Soft-delete a staff record.
     */
    public function deleteStaff(User $center, string $uuid): bool
    {
        $staff = $this->findOwnedStaff($center, $uuid);

        return $this->transaction(function () use ($staff) {
            $sUuid = $staff->uuid;
            $staff->delete();

            $this->logSecurityEvent('center.staff.deleted', [
                'staff_uuid' => $sUuid,
            ], 'warning', $staff);

            return true;
        });
    }

    /**
     * Retrieve strictly tenant-isolated immutable SecurityAuditLog trail for this center.
     */
    public function listAuditLogs(User $center, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $safePerPage = min(100, max(5, $perPage));

        $query = SecurityAuditLog::query()
            ->where('user_id', $center->id)
            ->where('event', 'like', 'center.%')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['level']) && in_array($filters['level'], ['info', 'warning', 'critical', 'error'], true)) {
            $query->where('level', $filters['level']);
        }

        if (! empty($filters['event'])) {
            $event = trim((string) $filters['event']);
            $query->where('event', 'like', "%{$event}%");
        }

        if (! empty($filters['search'])) {
            $term = trim(strip_tags((string) $filters['search']));
            if ($term !== '') {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
                $like = "%{$escaped}%";
                $query->where(function ($sub) use ($like) {
                    $sub->where('event', 'like', $like)
                        ->orWhere('ip_address', 'like', $like)
                        ->orWhere('context', 'like', $like);
                });
            }
        }

        return $query->paginate($safePerPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatStaff(CenterStaff $staff): array
    {
        $roleVal = $staff->role instanceof CenterStaffRole ? $staff->role->value : (string) $staff->role;

        return [
            'id' => $staff->uuid,
            'uuid' => $staff->uuid,
            'nameAr' => $staff->name_ar,
            'nameEn' => $staff->name_en ?: $staff->name_ar,
            'phone' => $staff->phone ?? '',
            'email' => $staff->email ?? '',
            'role' => $roleVal,
            'roleAr' => $staff->role_ar ?: ($staff->role instanceof CenterStaffRole ? $staff->role->labelAr() : $roleVal),
            'branchId' => $staff->branch?->uuid,
            'branchNameAr' => $staff->branch?->name_ar ?? 'الفرع الرئيسي',
            'salaryEgp' => (float) $staff->salary_egp,
            'permissions' => $staff->permissions ?? [],
            'status' => $staff->status,
            'createdAt' => $staff->created_at?->toIso8601String(),
        ];
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

    /**
     * @return list<string>
     */
    protected function sanitizePermissions(mixed $permissions): array
    {
        if (! is_array($permissions)) {
            return [];
        }

        return collect($permissions)
            ->filter(fn ($p) => is_string($p) && trim(strip_tags($p)) !== '')
            ->map(fn ($p) => mb_substr(trim(strip_tags($p)), 0, 80))
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }
}
