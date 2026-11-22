<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Enums\DayOfWeek;
use App\Http\Controllers\Api\BaseApiController;
use App\Services\V1\Center\CenterDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CenterDashboardController extends BaseApiController
{
    public function __construct(
        protected CenterDashboardService $dashboardService
    ) {}

    /**
     * GET /api/v1/center/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        $period = in_array($request->query('period'), ['today', 'month', 'year'], true)
            ? (string) $request->query('period')
            : 'month';

        $data = $this->dashboardService->getDashboard(
            $this->authenticatedUser(),
            $request->query('branch_id'),
            $period,
            $request->query('queue_day')
        );

        return $this->successResponse($data, __('messages.center.dashboard_fetched'));
    }

    /**
     * POST /api/v1/center/queue/complete
     */
    public function completeQueueItem(Request $request): JsonResponse
    {
        $request->merge(array_filter([
            'group_id' => $request->input('group_id', $request->input('groupId')),
            'slot_id' => $request->input('slot_id', $request->input('slotId')),
            'queue_date' => $request->input('queue_date', $request->input('queueDate')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'group_id' => ['required', 'uuid'],
            'slot_id' => ['nullable', 'uuid'],
            'queue_date' => ['nullable', 'date'],
        ]);

        $item = $this->dashboardService->completeQueueItem(
            $this->authenticatedUser(),
            $validated['group_id'],
            $validated['slot_id'] ?? null,
            $validated['queue_date'] ?? null
        );

        return $this->successResponse($item, __('messages.center.queue_item_completed'));
    }

    /**
     * POST /api/v1/center/queue/restore
     */
    public function restoreQueueItem(Request $request): JsonResponse
    {
        $request->merge(array_filter([
            'group_id' => $request->input('group_id', $request->input('groupId')),
            'slot_id' => $request->input('slot_id', $request->input('slotId')),
            'queue_date' => $request->input('queue_date', $request->input('queueDate')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'group_id' => ['required', 'uuid'],
            'slot_id' => ['nullable', 'uuid'],
            'queue_date' => ['nullable', 'date'],
        ]);

        $this->dashboardService->restoreQueueItem(
            $this->authenticatedUser(),
            $validated['group_id'],
            $validated['slot_id'] ?? null,
            $validated['queue_date'] ?? null
        );

        return $this->successResponse(null, __('messages.center.queue_item_restored'));
    }

    /**
     * POST /api/v1/center/queue/reset
     */
    public function resetQueue(Request $request): JsonResponse
    {
        $request->merge(array_filter([
            'queue_date' => $request->input('queue_date', $request->input('queueDate')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'day' => ['nullable', 'string', Rule::in(DayOfWeek::values())],
            'queue_date' => ['nullable', 'date'],
        ]);

        $resetCount = $this->dashboardService->resetQueue(
            $this->authenticatedUser(),
            $validated['day'] ?? null,
            $validated['queue_date'] ?? null
        );

        return $this->successResponse(['resetCount' => $resetCount], __('messages.center.queue_reset'));
    }

    /**
     * GET /api/v1/center/reports/executive
     */
    public function executiveReport(Request $request): JsonResponse
    {
        $period = in_array($request->query('period'), ['weekly', 'monthly', 'term'], true)
            ? (string) $request->query('period')
            : 'weekly';

        $report = $this->dashboardService->getExecutiveReport($this->authenticatedUser(), $period);

        return $this->successResponse($report, __('messages.center.executive_report_fetched'));
    }
}
