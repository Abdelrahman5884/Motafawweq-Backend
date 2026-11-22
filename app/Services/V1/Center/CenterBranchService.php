<?php

namespace App\Services\V1\Center;

use App\Enums\EnrollmentStatus;
use App\Models\CenterBranch;
use App\Models\CenterRoom;
use App\Models\CenterStaff;
use App\Models\CenterTeacher;
use App\Models\GroupEnrollment;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CenterBranchService extends BaseService
{
    /**
     * List all branches owned by the center with aggregated live metrics (zero N+1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBranches(User $center, array $filters = []): array
    {
        $query = CenterBranch::forCenter($center->id)
            ->orderByDesc('is_main')
            ->orderByDesc('created_at');

        if (! empty($filters['search'])) {
            $q = trim((string) $filters['search']);
            $query->where(function ($sub) use ($q) {
                $sub->where('name_ar', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('address_ar', 'like', "%{$q}%")
                    ->orWhere('manager_name_ar', 'like', "%{$q}%");
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        $branches = $query->get();
        if ($branches->isEmpty()) {
            return [];
        }

        $branchIds = $branches->pluck('id')->all();

        $roomsAgg = CenterRoom::forCenter($center->id)
            ->whereIn('branch_id', $branchIds)
            ->select([
                'branch_id',
                DB::raw('COUNT(*) as rooms_count'),
                DB::raw('COALESCE(SUM(capacity), 0) as total_capacity'),
            ])
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $teachersAgg = CenterTeacher::forCenter($center->id)
            ->whereIn('branch_id', $branchIds)
            ->select(['branch_id', DB::raw('COUNT(*) as teachers_count')])
            ->groupBy('branch_id')
            ->pluck('teachers_count', 'branch_id');

        $staffAgg = CenterStaff::forCenter($center->id)
            ->whereIn('branch_id', $branchIds)
            ->select(['branch_id', DB::raw('COUNT(*) as staff_count')])
            ->groupBy('branch_id')
            ->pluck('staff_count', 'branch_id');

        $groupsAgg = StudyGroup::where('center_id', $center->id)
            ->whereIn('center_branch_id', $branchIds)
            ->where('status', 'active')
            ->select(['center_branch_id', DB::raw('COUNT(*) as groups_count')])
            ->groupBy('center_branch_id')
            ->pluck('groups_count', 'center_branch_id');

        $studentsAgg = GroupEnrollment::query()
            ->join('study_groups', 'study_groups.id', '=', 'group_enrollments.study_group_id')
            ->where('study_groups.center_id', $center->id)
            ->whereIn('study_groups.center_branch_id', $branchIds)
            ->whereNull('study_groups.deleted_at')
            ->whereNull('group_enrollments.deleted_at')
            ->where('group_enrollments.status', EnrollmentStatus::ENROLLED->value)
            ->select([
                'study_groups.center_branch_id as branch_id',
                DB::raw('COUNT(DISTINCT group_enrollments.student_id) as students_count'),
                DB::raw('COUNT(group_enrollments.id) as seats_occupied'),
            ])
            ->groupBy('study_groups.center_branch_id')
            ->get()
            ->keyBy('branch_id');

        return $branches->map(function (CenterBranch $branch) use ($roomsAgg, $teachersAgg, $staffAgg, $groupsAgg, $studentsAgg) {
            $roomRow = $roomsAgg->get($branch->id);
            $roomsCount = (int) ($roomRow?->rooms_count ?? 0);
            $totalCapacity = (int) ($roomRow?->total_capacity ?? 0);
            $teachersCount = (int) ($teachersAgg[$branch->id] ?? 0);
            $staffCount = (int) ($staffAgg[$branch->id] ?? 0);
            $groupsCount = (int) ($groupsAgg[$branch->id] ?? 0);
            $studentRow = $studentsAgg->get($branch->id);
            $activeStudents = (int) ($studentRow?->students_count ?? 0);
            $seatsOccupied = (int) ($studentRow?->seats_occupied ?? 0);

            $utilizationRate = $totalCapacity > 0
                ? min(100, (int) round(($seatsOccupied / $totalCapacity) * 100))
                : 0;

            return $this->formatBranch($branch, [
                'roomsCount' => $roomsCount,
                'totalCapacity' => $totalCapacity,
                'teachersCount' => $teachersCount,
                'staffCount' => $staffCount,
                'groupsCount' => $groupsCount,
                'activeStudents' => $activeStudents,
                'utilizationRate' => $utilizationRate,
            ]);
        })->values()->all();
    }

    /**
     * Find a branch by UUID and assert strict ownership.
     */
    public function findOwnedBranch(User $center, string $uuid): CenterBranch
    {
        /** @var CenterBranch|null $branch */
        $branch = CenterBranch::where('uuid', $uuid)->first();
        if (! $branch) {
            throw (new ModelNotFoundException)->setModel(CenterBranch::class, [$uuid]);
        }

        $branch->assertOwnedByCenter($center);

        return $branch;
    }

    /**
     * Create a new branch for the center.
     *
     * @return array<string, mixed>
     */
    public function createBranch(User $center, array $data): array
    {
        $branch = $this->transaction(function () use ($center, $data) {
            $hasExisting = CenterBranch::forCenter($center->id)->exists();
            $isMain = isset($data['is_main']) ? (bool) $data['is_main'] : ! $hasExisting;

            if ($isMain && $hasExisting) {
                CenterBranch::forCenter($center->id)->update(['is_main' => false]);
            }

            $nameAr = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? 'فرع جديد')));
            $nameEn = trim(strip_tags((string) ($data['name_en'] ?? $data['nameEn'] ?? $nameAr)));

            /** @var CenterBranch $created */
            $created = CenterBranch::create([
                'center_id' => $center->id,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'address_ar' => isset($data['address_ar']) || isset($data['addressAr'])
                    ? trim(strip_tags((string) ($data['address_ar'] ?? $data['addressAr'])))
                    : null,
                'address_en' => isset($data['address_en']) || isset($data['addressEn'])
                    ? trim(strip_tags((string) ($data['address_en'] ?? $data['addressEn'])))
                    : null,
                'phone' => isset($data['phone']) ? trim(strip_tags((string) $data['phone'])) : null,
                'manager_name_ar' => isset($data['manager_name_ar']) || isset($data['managerNameAr'])
                    ? trim(strip_tags((string) ($data['manager_name_ar'] ?? $data['managerNameAr'])))
                    : null,
                'manager_name_en' => isset($data['manager_name_en']) || isset($data['managerNameEn'])
                    ? trim(strip_tags((string) ($data['manager_name_en'] ?? $data['managerNameEn'])))
                    : null,
                'is_main' => $isMain,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            ]);

            $this->logSecurityEvent('center.branch.created', [
                'branch_uuid' => $created->uuid,
                'name_ar' => $created->name_ar,
                'is_main' => $created->is_main,
            ], 'info', $created);

            return $created;
        });

        return $this->formatBranch($branch);
    }

    /**
     * Update an existing branch owned by the center.
     *
     * @return array<string, mixed>
     */
    public function updateBranch(User $center, string $uuid, array $data): array
    {
        $branch = $this->findOwnedBranch($center, $uuid);

        $updated = $this->transaction(function () use ($center, $branch, $data) {
            $payload = [];

            if (array_key_exists('name_ar', $data) || array_key_exists('nameAr', $data)) {
                $payload['name_ar'] = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'])));
            }
            if (array_key_exists('name_en', $data) || array_key_exists('nameEn', $data)) {
                $payload['name_en'] = trim(strip_tags((string) ($data['name_en'] ?? $data['nameEn'])));
            }
            if (array_key_exists('address_ar', $data) || array_key_exists('addressAr', $data)) {
                $payload['address_ar'] = trim(strip_tags((string) ($data['address_ar'] ?? $data['addressAr'])));
            }
            if (array_key_exists('address_en', $data) || array_key_exists('addressEn', $data)) {
                $payload['address_en'] = trim(strip_tags((string) ($data['address_en'] ?? $data['addressEn'])));
            }
            if (array_key_exists('phone', $data)) {
                $payload['phone'] = trim(strip_tags((string) $data['phone']));
            }
            if (array_key_exists('manager_name_ar', $data) || array_key_exists('managerNameAr', $data)) {
                $payload['manager_name_ar'] = trim(strip_tags((string) ($data['manager_name_ar'] ?? $data['managerNameAr'])));
            }
            if (array_key_exists('manager_name_en', $data) || array_key_exists('managerNameEn', $data)) {
                $payload['manager_name_en'] = trim(strip_tags((string) ($data['manager_name_en'] ?? $data['managerNameEn'])));
            }
            if (array_key_exists('is_active', $data)) {
                $payload['is_active'] = (bool) $data['is_active'];
            }
            if (! empty($data['is_main'])) {
                CenterBranch::forCenter($center->id)
                    ->where('id', '!=', $branch->id)
                    ->update(['is_main' => false]);
                $payload['is_main'] = true;
            }

            if (! empty($payload)) {
                $branch->update($payload);
            }

            $this->logSecurityEvent('center.branch.updated', [
                'branch_uuid' => $branch->uuid,
                'changes' => array_keys($payload),
            ], 'info', $branch);

            return $branch->fresh();
        });

        return $this->formatBranch($updated);
    }

    /**
     * Soft-delete a branch without destroying historical rooms or sessions.
     */
    public function deleteBranch(User $center, string $uuid): bool
    {
        $branch = $this->findOwnedBranch($center, $uuid);

        return $this->transaction(function () use ($branch) {
            $branchUuid = $branch->uuid;
            $branchName = $branch->name_ar;
            $branch->delete();

            $this->logSecurityEvent('center.branch.deleted', [
                'branch_uuid' => $branchUuid,
                'name_ar' => $branchName,
            ], 'warning', $branch);

            return true;
        });
    }

    /**
     * Export branches operational benchmark table as formula-safe UTF-8 CSV.
     */
    public function exportBranchesCsv(User $center): string
    {
        $branches = $this->listBranches($center);
        $headers = ['اسم الفرع', 'العنوان', 'المدير', 'الهاتف', 'عدد القاعات', 'عدد الطلاب', 'عدد المدرسين', 'نسبة الإشغال'];

        $lines = [implode(',', array_map([$this, 'escapeCsvCell'], $headers))];

        foreach ($branches as $b) {
            $row = [
                $b['nameAr'],
                $b['addressAr'] ?? '',
                $b['managerNameAr'] ?? '',
                $b['phone'] ?? '',
                $b['roomsCount'],
                $b['activeStudents'],
                $b['teachersCount'],
                $b['utilizationRate'].'%',
            ];
            $lines[] = implode(',', array_map([$this, 'escapeCsvCell'], $row));
        }

        return "\xEF\xBB\xBF".implode("\n", $lines);
    }

    /**
     * Format a CenterBranch record for consistent API responses.
     *
     * @param  array<string, int>  $metrics
     * @return array<string, mixed>
     */
    public function formatBranch(CenterBranch $branch, array $metrics = []): array
    {
        return [
            'id' => $branch->uuid,
            'uuid' => $branch->uuid,
            'nameAr' => $branch->name_ar,
            'nameEn' => $branch->name_en ?: $branch->name_ar,
            'addressAr' => $branch->address_ar ?? '',
            'addressEn' => $branch->address_en ?: ($branch->address_ar ?? ''),
            'phone' => $branch->phone ?? '',
            'managerNameAr' => $branch->manager_name_ar ?? '',
            'managerNameEn' => $branch->manager_name_en ?: ($branch->manager_name_ar ?? ''),
            'isMain' => (bool) $branch->is_main,
            'isActive' => (bool) $branch->is_active,
            'roomsCount' => (int) ($metrics['roomsCount'] ?? 0),
            'totalCapacity' => (int) ($metrics['totalCapacity'] ?? 0),
            'teachersCount' => (int) ($metrics['teachersCount'] ?? 0),
            'staffCount' => (int) ($metrics['staffCount'] ?? 0),
            'groupsCount' => (int) ($metrics['groupsCount'] ?? 0),
            'activeStudents' => (int) ($metrics['activeStudents'] ?? 0),
            'utilizationRate' => (int) ($metrics['utilizationRate'] ?? 0),
            'createdAt' => $branch->created_at?->toIso8601String(),
        ];
    }

    /**
     * Neutralize spreadsheet formula injection (=, +, -, @, tab, CR) and quote CSV cells safely.
     */
    protected function escapeCsvCell(mixed $value): string
    {
        $str = (string) ($value ?? '');
        if ($str !== '' && preg_match('/^[=+\-@\t\r]/', $str)) {
            $str = "'".$str;
        }

        return '"'.str_replace('"', '""', $str).'"';
    }
}
