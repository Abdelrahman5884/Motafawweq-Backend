<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Admin\AdminAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdminAttendanceController extends BaseApiController
{
    public function __construct(
        protected AdminAttendanceService $attendanceService
    ) {}

    /**
     * GET /api/v1/admin/attendance/gates
     */
    public function gates(Request $request): JsonResponse
    {
        $result = $this->attendanceService->getGateOverviewAndLogs([
            'search' => $request->query('search'),
            'center_id' => $request->query('center_id'),
            'center' => $request->query('center'),
            'status' => $request->query('status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return $this->successResponse(
            [
                'summary' => $result['summary'],
                'centers' => $result['centers'],
                'logs' => $result['items'],
            ],
            __('messages.admin.gate_logs_fetched'),
            200,
            $result['meta']
        );
    }

    /**
     * GET /api/v1/admin/attendance/gates/export
     */
    public function exportCsv(Request $request): Response
    {
        $csv = $this->attendanceService->exportGateLogsCsv($this->authenticatedUser(), [
            'search' => $request->query('search'),
            'center_id' => $request->query('center_id'),
            'center' => $request->query('center'),
            'status' => $request->query('status'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="motafawweq-gate-logs-'.now()->format('Y-m-d').'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
