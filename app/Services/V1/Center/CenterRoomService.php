<?php

namespace App\Services\V1\Center;

use App\Models\CenterBranch;
use App\Models\CenterRoom;
use App\Models\GroupScheduleSlot;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CenterRoomService extends BaseService
{
    public function __construct(
        protected CenterBranchService $branchService
    ) {}

    /**
     * List all rooms owned by the center with assigned cohorts & utilization efficiency (zero N+1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRooms(User $center, array $filters = []): array
    {
        $query = CenterRoom::forCenter($center->id)
            ->with('branch:id,uuid,name_ar,name_en')
            ->orderByDesc('created_at');

        if (! empty($filters['branch_id']) && $filters['branch_id'] !== 'all') {
            $branch = $this->branchService->findOwnedBranch($center, (string) $filters['branch_id']);
            $query->where('branch_id', $branch->id);
        }

        if (isset($filters['is_available']) && $filters['is_available'] !== '') {
            $query->where('is_available', filter_var($filters['is_available'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['search'])) {
            $q = trim((string) $filters['search']);
            $query->where(function ($sub) use ($q) {
                $sub->where('name_ar', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('floor', 'like', "%{$q}%");
            });
        }

        $rooms = $query->get();
        if ($rooms->isEmpty()) {
            return [];
        }

        $activeGroups = StudyGroup::where('center_id', $center->id)
            ->where('status', 'active')
            ->with(['scheduleSlots', 'centerTeacher:id,uuid,name_ar'])
            ->withCount('enrolledEnrollments as enrolled_count')
            ->get();

        return $rooms->map(fn (CenterRoom $room) => $this->hydrateRoom($center, $room, $activeGroups))->values()->all();
    }

    /**
     * Hydrate a single CenterRoom with its assigned active cohorts and live efficiency percentage.
     *
     * @param  Collection<int, StudyGroup>|null  $activeGroups
     * @return array<string, mixed>
     */
    public function hydrateRoom(User $center, CenterRoom $room, $activeGroups = null): array
    {
        $groups = $activeGroups ?? StudyGroup::where('center_id', $center->id)
            ->where('status', 'active')
            ->with(['scheduleSlots', 'centerTeacher:id,uuid,name_ar'])
            ->withCount('enrolledEnrollments as enrolled_count')
            ->get();

        $roomNorm = $this->normalizeHallKey((string) $room->name_ar);

        $assignedGroups = $groups->filter(function (StudyGroup $g) use ($room, $roomNorm) {
            if ($g->center_room_id !== null && (int) $g->center_room_id === (int) $room->id) {
                return true;
            }
            if ($g->scheduleSlots->contains(fn ($s) => $s->center_room_id !== null && (int) $s->center_room_id === (int) $room->id)) {
                return true;
            }
            if ($g->center_room_id === null && $roomNorm !== '') {
                if ($this->normalizeHallKey((string) $g->hall_name) === $roomNorm) {
                    return true;
                }
                if ($g->scheduleSlots->contains(fn ($s) => $s->center_room_id === null && $this->normalizeHallKey((string) $s->hall_name) === $roomNorm)) {
                    return true;
                }
            }

            return false;
        });

        $assignedCohorts = $assignedGroups->map(function (StudyGroup $g) use ($room) {
            $enrolled = (int) ($g->enrolled_count ?? 0);
            $fillRatio = $room->capacity > 0 ? min(100, (int) round(($enrolled / $room->capacity) * 100)) : 0;

            return [
                'id' => $g->uuid,
                'nameAr' => $g->name_ar,
                'subjectAr' => $g->subject_ar,
                'gradeAr' => $g->grade_ar,
                'scheduleAr' => $g->schedule_ar,
                'teacherNameAr' => $g->centerTeacher?->name_ar ?: ($g->teacher_name_override ?: 'معلم معتمد'),
                'enrolledCount' => $enrolled,
                'capacity' => (int) $room->capacity,
                'fillRatio' => $fillRatio,
            ];
        })->values()->all();

        $avgFillRatio = count($assignedCohorts) > 0
            ? (int) round(collect($assignedCohorts)->avg('fillRatio'))
            : 0;

        return $this->formatRoom($room, $assignedCohorts, $avgFillRatio);
    }

    /**
     * Find a room by UUID and strictly enforce center ownership.
     */
    public function findOwnedRoom(User $center, string $uuid): CenterRoom
    {
        /** @var CenterRoom|null $room */
        $room = CenterRoom::where('uuid', $uuid)->with('branch')->first();
        if (! $room) {
            throw (new ModelNotFoundException)->setModel(CenterRoom::class, [$uuid]);
        }

        $room->assertOwnedByCenter($center);

        return $room;
    }

    /**
     * Create a new classroom / hall owned by the center.
     *
     * @return array<string, mixed>
     */
    public function createRoom(User $center, array $data): array
    {
        $branchId = $this->resolveOwnedBranchId($center, $data['branch_id'] ?? $data['branchId'] ?? null);

        $room = $this->transaction(function () use ($center, $branchId, $data) {
            $nameAr = trim(strip_tags((string) ($data['name_ar'] ?? $data['nameAr'] ?? 'قاعة جديدة')));
            $nameEn = trim(strip_tags((string) ($data['name_en'] ?? $data['nameEn'] ?? $nameAr)));
            $equipment = $this->sanitizeEquipment($data['equipment'] ?? $data['equipped'] ?? []);

            /** @var CenterRoom $created */
            $created = CenterRoom::create([
                'center_id' => $center->id,
                'branch_id' => $branchId,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'floor' => isset($data['floor']) ? trim(strip_tags((string) $data['floor'])) : 'الطابق الأول',
                'capacity' => max(1, min(1000, (int) ($data['capacity'] ?? 40))),
                'equipment' => $equipment,
                'is_available' => isset($data['is_available']) ? (bool) $data['is_available'] : true,
            ]);

            $this->logSecurityEvent('center.room.created', [
                'room_uuid' => $created->uuid,
                'name_ar' => $created->name_ar,
                'capacity' => $created->capacity,
                'branch_id' => $branchId,
            ], 'info', $created);

            return $created->load('branch');
        });

        return $this->hydrateRoom($center, $room);
    }

    /**
     * Update an existing room and synchronize hall names on linked center slots/groups.
     *
     * @return array<string, mixed>
     */
    public function updateRoom(User $center, string $uuid, array $data): array
    {
        $room = $this->findOwnedRoom($center, $uuid);
        $oldNameAr = $room->name_ar;

        $branchId = null;
        $hasBranchInput = array_key_exists('branch_id', $data) || array_key_exists('branchId', $data);
        if ($hasBranchInput) {
            $branchId = $this->resolveOwnedBranchId($center, $data['branch_id'] ?? $data['branchId']);
        }

        $updated = $this->transaction(function () use ($center, $room, $oldNameAr, $data, $hasBranchInput, $branchId) {
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
            if (array_key_exists('floor', $data)) {
                $payload['floor'] = trim(strip_tags((string) $data['floor']));
            }
            if (array_key_exists('capacity', $data)) {
                $payload['capacity'] = max(1, min(1000, (int) $data['capacity']));
            }
            if (array_key_exists('equipment', $data) || array_key_exists('equipped', $data)) {
                $payload['equipment'] = $this->sanitizeEquipment($data['equipment'] ?? $data['equipped'] ?? []);
            }
            if (array_key_exists('is_available', $data) || array_key_exists('isAvailable', $data)) {
                $payload['is_available'] = (bool) ($data['is_available'] ?? $data['isAvailable']);
            }

            if (! empty($payload)) {
                $room->update($payload);
            }

            // Keep hall_name synced on linked study groups and schedule slots if room was renamed
            if (isset($payload['name_ar'])) {
                StudyGroup::where('center_id', $center->id)
                    ->where(function ($q) use ($room, $oldNameAr) {
                        $q->where('center_room_id', $room->id)
                            ->orWhere(fn ($sq) => $sq->whereNull('center_room_id')->where('hall_name', $oldNameAr));
                    })
                    ->update([
                        'center_room_id' => $room->id,
                        'hall_name' => $payload['name_ar'],
                    ]);

                GroupScheduleSlot::whereHas('studyGroup', fn ($q) => $q->where('center_id', $center->id))
                    ->where(function ($q) use ($room, $oldNameAr) {
                        $q->where('center_room_id', $room->id)
                            ->orWhere(fn ($sq) => $sq->whereNull('center_room_id')->where('hall_name', $oldNameAr));
                    })
                    ->update([
                        'center_room_id' => $room->id,
                        'hall_name' => $payload['name_ar'],
                    ]);
            }

            $this->logSecurityEvent('center.room.updated', [
                'room_uuid' => $room->uuid,
                'changes' => array_keys($payload),
            ], 'info', $room);

            return $room->fresh(['branch']);
        });

        return $this->hydrateRoom($center, $updated);
    }

    /**
     * Soft-delete a room and detach references on active groups/slots.
     */
    public function deleteRoom(User $center, string $uuid): bool
    {
        $room = $this->findOwnedRoom($center, $uuid);

        return $this->transaction(function () use ($center, $room) {
            $roomUuid = $room->uuid;
            $roomName = $room->name_ar;

            StudyGroup::where('center_id', $center->id)
                ->where('center_room_id', $room->id)
                ->update(['center_room_id' => null]);

            GroupScheduleSlot::where('center_room_id', $room->id)
                ->whereHas('studyGroup', fn ($q) => $q->where('center_id', $center->id))
                ->update(['center_room_id' => null]);

            $room->delete();

            $this->logSecurityEvent('center.room.deleted', [
                'room_uuid' => $roomUuid,
                'name_ar' => $roomName,
            ], 'warning', $room);

            return true;
        });
    }

    /**
     * Format a CenterRoom for API responses.
     *
     * @param  array<int, array<string, mixed>>  $assignedCohorts
     * @return array<string, mixed>
     */
    public function formatRoom(CenterRoom $room, array $assignedCohorts = [], int $efficiencyPct = 0): array
    {
        return [
            'id' => $room->uuid,
            'uuid' => $room->uuid,
            'nameAr' => $room->name_ar,
            'nameEn' => $room->name_en ?: $room->name_ar,
            'branchId' => $room->branch?->uuid,
            'branchNameAr' => $room->branch?->name_ar ?? 'الفرع الرئيسي',
            'branchNameEn' => $room->branch?->name_en ?: ($room->branch?->name_ar ?? 'Main Branch'),
            'floor' => $room->floor ?? 'الطابق الأول',
            'capacity' => (int) $room->capacity,
            'equipped' => $room->equipment ?? [],
            'equipment' => $room->equipment ?? [],
            'isAvailable' => (bool) $room->is_available,
            'assignedCohortsCount' => count($assignedCohorts),
            'assignedCohorts' => $assignedCohorts,
            'efficiencyPct' => $efficiencyPct,
            'createdAt' => $room->created_at?->toIso8601String(),
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
    protected function sanitizeEquipment(mixed $equipment): array
    {
        if (! is_array($equipment)) {
            return [];
        }

        return collect($equipment)
            ->filter(fn ($item) => is_string($item) && trim(strip_tags($item)) !== '')
            ->map(fn ($item) => mb_substr(trim(strip_tags($item)), 0, 120))
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    protected function normalizeHallKey(string $hall): string
    {
        return preg_replace('/[\(\)\s\-_]+/u', '', mb_strtolower(trim($hall))) ?? '';
    }
}
