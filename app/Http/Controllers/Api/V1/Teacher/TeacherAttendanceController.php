<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Teacher\ScanAttendanceRequest;
use App\Http\Requests\V1\Teacher\ToggleAttendanceRequest;
use App\Http\Resources\V1\Teacher\GroupAttendanceRecordResource;
use App\Http\Resources\V1\Teacher\GroupAttendanceSessionResource;
use App\Services\V1\Teacher\TeacherAttendanceService;
use Illuminate\Http\JsonResponse;

class TeacherAttendanceController extends BaseApiController
{
    public function __construct(
        protected TeacherAttendanceService $attendanceService
    ) {}

    /**
     * POST /api/v1/teacher/groups/{groupUuid}/attendance/scan
     * Instantly record student attendance via QR scan, Barcode, Passcode, or Student Code.
     */
    public function scan(ScanAttendanceRequest $request, string $groupUuid): JsonResponse
    {
        $result = $this->attendanceService->scanAttendance(
            $this->authenticatedUser(),
            $groupUuid,
            $request->validated('code'),
            $request->validated('method') ?? 'qr_scan',
            $request->validated('notes')
        );

        return $this->successResponse(
            [
                'record'          => new GroupAttendanceRecordResource($result['record']),
                'session'         => new GroupAttendanceSessionResource($result['session']),
                'alreadyRecorded' => $result['alreadyRecorded'],
            ],
            $result['alreadyRecorded']
                ? __('messages.attendance_already_recorded')
                : __('messages.attendance_recorded_success')
        );
    }

    /**
     * POST /api/v1/teacher/groups/{groupUuid}/attendance/toggle
     * Manually toggle attendance status (present / absent) for a student in a session.
     */
    public function toggle(ToggleAttendanceRequest $request, string $groupUuid): JsonResponse
    {
        $record = $this->attendanceService->toggleAttendance(
            $this->authenticatedUser(),
            $groupUuid,
            $request->validated('enrollment_id'),
            $request->validated('status'),
            $request->validated('date'),
            $request->validated('notes')
        );

        return $this->successResponse(
            new GroupAttendanceRecordResource($record),
            __('messages.attendance_toggled_success')
        );
    }

    /**
     * GET /api/v1/teacher/groups/{groupUuid}/attendance/sessions
     * List attendance sessions history with metrics for a study group.
     */
    public function index(string $groupUuid): JsonResponse
    {
        $sessions = $this->attendanceService->listSessions(
            $this->authenticatedUser(),
            $groupUuid
        );

        return $this->successResponse(
            GroupAttendanceSessionResource::collection($sessions),
            __('messages.attendance_sessions_fetched')
        );
    }

    /**
     * GET /api/v1/teacher/groups/{groupUuid}/attendance/sessions/{sessionUuid}
     * Retrieve full attendance roster breakdown for a specific session date.
     */
    public function show(string $groupUuid, string $sessionUuid): JsonResponse
    {
        $details = $this->attendanceService->getSessionDetails(
            $this->authenticatedUser(),
            $groupUuid,
            $sessionUuid
        );

        return $this->successResponse(
            [
                'session' => new GroupAttendanceSessionResource($details['session']),
                'roster'  => $details['roster'],
            ],
            __('messages.attendance_session_fetched')
        );
    }
}

