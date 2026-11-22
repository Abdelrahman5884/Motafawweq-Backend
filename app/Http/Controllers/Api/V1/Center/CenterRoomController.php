<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Enums\DayOfWeek;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Center\StoreCenterRoomRequest;
use App\Http\Requests\V1\Center\UpdateCenterRoomRequest;
use App\Models\StudyGroup;
use App\Services\V1\Center\CenterRoomService;
use App\Services\V1\Center\CenterScheduleService;
use App\Services\V1\Center\CenterTeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CenterRoomController extends BaseApiController
{
    public function __construct(
        protected CenterRoomService $roomService,
        protected CenterScheduleService $scheduleService,
        protected CenterTeacherService $teacherService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rooms = $this->roomService->listRooms(
            $this->authenticatedUser(),
            $request->only(['branch_id', 'is_available', 'search'])
        );

        return $this->successResponse(
            $rooms,
            __('messages.center.rooms_fetched'),
            meta: ['total' => count($rooms)]
        );
    }

    public function store(StoreCenterRoomRequest $request): JsonResponse
    {
        $room = $this->roomService->createRoom(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse($room, __('messages.center.room_created'));
    }

    public function show(string $uuid): JsonResponse
    {
        $room = $this->roomService->findOwnedRoom($this->authenticatedUser(), $uuid);

        return $this->successResponse(
            $this->roomService->formatRoom($room),
            __('messages.center.room_fetched')
        );
    }

    public function update(UpdateCenterRoomRequest $request, string $uuid): JsonResponse
    {
        $room = $this->roomService->updateRoom(
            $this->authenticatedUser(),
            $uuid,
            $request->validated()
        );

        return $this->successResponse($room, __('messages.center.room_updated'));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->roomService->deleteRoom($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.center.room_deleted'));
    }

    /**
     * GET /api/v1/center/schedule
     */
    public function masterSchedule(Request $request): JsonResponse
    {
        $schedule = $this->scheduleService->getMasterSchedule(
            $this->authenticatedUser(),
            $request->query('day'),
            $request->query('branch_id')
        );

        return $this->successResponse($schedule, __('messages.center.schedule_fetched'));
    }

    /**
     * GET /api/v1/center/schedule/availability
     */
    public function slotAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'day' => ['required', 'string', Rule::in(DayOfWeek::values())],
            'room_id' => ['nullable', 'uuid'],
            'hall_name' => ['nullable', 'string', 'max:120'],
            'exclude_group_id' => ['nullable', 'uuid'],
            'teacher_id' => ['nullable', 'uuid'],
        ]);

        $center = $this->authenticatedUser();
        $roomId = ! empty($validated['room_id'])
            ? $this->roomService->findOwnedRoom($center, $validated['room_id'])->id
            : null;
        $excludeGroupId = ! empty($validated['exclude_group_id'])
            ? StudyGroup::where('center_id', $center->id)->where('uuid', $validated['exclude_group_id'])->value('id')
            : null;
        $teacherId = ! empty($validated['teacher_id'])
            ? $this->teacherService->findOwnedTeacher($center, $validated['teacher_id'])->id
            : null;

        $slots = $this->scheduleService->getSlotAvailability(
            $center,
            $validated['day'],
            $roomId,
            $validated['hall_name'] ?? null,
            $excludeGroupId,
            $teacherId
        );

        return $this->successResponse($slots, __('messages.center.schedule_availability_fetched'));
    }

    /**
     * POST /api/v1/center/schedule/check-conflicts
     */
    public function checkConflicts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slots' => ['required', 'array', 'min:1', 'max:7'],
            'slots.*.day' => ['required', 'string', Rule::in(DayOfWeek::values())],
            'slots.*.start_time' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'slots.*.end_time' => ['required', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'slots.*.room_id' => ['nullable', 'uuid'],
            'slots.*.hall_name' => ['nullable', 'string', 'max:120'],
            'exclude_group_id' => ['nullable', 'uuid'],
            'teacher_id' => ['nullable', 'uuid'],
        ]);

        $center = $this->authenticatedUser();
        $excludeGroupId = ! empty($validated['exclude_group_id'])
            ? StudyGroup::where('center_id', $center->id)->where('uuid', $validated['exclude_group_id'])->value('id')
            : null;
        $teacherId = ! empty($validated['teacher_id'])
            ? $this->teacherService->findOwnedTeacher($center, $validated['teacher_id'])->id
            : null;

        $prepared = array_map(function ($slot) use ($center) {
            if (! empty($slot['room_id'])) {
                $room = $this->roomService->findOwnedRoom($center, $slot['room_id']);
                $slot['room_id'] = $room->id;
                $slot['hall_name'] = $slot['hall_name'] ?? $room->name_ar;
            }

            return $slot;
        }, $validated['slots']);

        $result = $this->scheduleService->checkConflicts($center, $prepared, $excludeGroupId, $teacherId);

        return $this->successResponse($result, __('messages.center.schedule_checked'));
    }
}
