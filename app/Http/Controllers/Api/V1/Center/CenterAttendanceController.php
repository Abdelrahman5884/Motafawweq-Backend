<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Center\ScanCenterAttendanceRequest;
use App\Services\V1\Center\CenterAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CenterAttendanceController extends BaseApiController
{
    public function __construct(
        protected CenterAttendanceService $attendanceService
    ) {}

    /**
     * POST /api/v1/center/groups/{uuid}/attendance/scan
     */
    public function scanInGroup(ScanCenterAttendanceRequest $request, string $uuid): JsonResponse
    {
        $v = $request->validated();

        $result = $this->attendanceService->scanInGroup(
            $this->authenticatedUser(),
            $uuid,
            $v['code'],
            $v['method'] ?? 'qr_scan',
            $v['date'] ?? null,
            $v['notes'] ?? null
        );

        $message = $result['alreadyRecorded']
            ? __('messages.attendance_already_recorded')
            : __('messages.attendance_recorded_success');

        return $this->successResponse($result, $message);
    }

    /**
     * POST /api/v1/center/groups/{uuid}/attendance/toggle
     */
    public function toggleInGroup(Request $request, string $uuid): JsonResponse
    {
        $request->merge(array_filter([
            'enrollment_id' => $request->input('enrollment_id', $request->input('enrollmentId')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'enrollment_id' => ['required', 'uuid'],
            'status' => ['nullable', 'string', Rule::in(AttendanceStatus::values())],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $result = $this->attendanceService->toggleInGroup(
            $this->authenticatedUser(),
            $uuid,
            $validated['enrollment_id'],
            $validated['status'] ?? null,
            $validated['date'] ?? null,
            $validated['notes'] ?? null
        );

        return $this->successResponse($result, __('messages.attendance_toggled_success'));
    }

    /**
     * GET /api/v1/center/groups/{uuid}/attendance/sessions
     */
    public function groupSessions(string $uuid): JsonResponse
    {
        $sessions = $this->attendanceService->listGroupSessions($this->authenticatedUser(), $uuid);

        return $this->successResponse(
            $sessions,
            __('messages.attendance_sessions_fetched'),
            meta: ['total' => count($sessions)]
        );
    }

    /**
     * GET /api/v1/center/groups/{uuid}/attendance/sessions/{sessionUuid}
     */
    public function groupSessionShow(string $uuid, string $sessionUuid): JsonResponse
    {
        $details = $this->attendanceService->getSessionDetails(
            $this->authenticatedUser(),
            $uuid,
            $sessionUuid
        );

        return $this->successResponse($details, __('messages.attendance_session_fetched'));
    }

    /**
     * POST /api/v1/center/attendance/smart-scan
     */
    public function smartGateScan(ScanCenterAttendanceRequest $request): JsonResponse
    {
        $v = $request->validated();

        $result = $this->attendanceService->smartGateScan(
            $this->authenticatedUser(),
            $v['code'],
            $v['simulated_day'] ?? null,
            $v['simulated_time'] ?? null
        );

        return $this->successResponse($result, $result['messageAr']);
    }

    /**
     * GET /api/v1/center/attendance/records
     */
    public function records(Request $request): JsonResponse
    {
        $data = $this->attendanceService->listAttendanceRecords(
            $this->authenticatedUser(),
            $request->only(['group_id', 'branch_id', 'date', 'status', 'search'])
        );

        return $this->successResponse($data, __('messages.center.attendance_records_fetched'));
    }

    /**
     * POST /api/v1/center/attendance/absence-alerts
     */
    public function sendAbsenceAlert(Request $request): JsonResponse
    {
        $request->merge(array_filter([
            'group_id' => $request->input('group_id', $request->input('groupId')),
            'enrollment_id' => $request->input('enrollment_id', $request->input('enrollmentId')),
            'session_date' => $request->input('session_date', $request->input('sessionDate')),
            'parent_phone' => $request->input('parent_phone', $request->input('parentPhone')),
            'message_ar' => $request->input('message_ar', $request->input('messageAr')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'group_id' => ['required', 'uuid'],
            'enrollment_id' => ['required', 'uuid'],
            'session_date' => ['nullable', 'date'],
            'parent_phone' => ['nullable', 'string', 'max:40'],
            'channel' => ['nullable', 'string', Rule::in(['whatsapp', 'sms', 'in_app'])],
            'message_ar' => ['nullable', 'string', 'max:500'],
        ]);

        $alert = $this->attendanceService->sendAbsenceAlert(
            $this->authenticatedUser(),
            $validated
        );

        return $this->createdResponse($alert, __('messages.center.absence_alert_sent'));
    }

    /**
     * GET /api/v1/center/attendance/export
     */
    public function exportCsv(Request $request): Response
    {
        $csv = $this->attendanceService->exportAttendanceCsv(
            $this->authenticatedUser(),
            $request->only(['group_id', 'branch_id', 'date', 'status', 'search'])
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="center-attendance.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
