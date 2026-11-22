<?php

namespace App\Http\Controllers\Api\V1\Center;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\V1\Center\StoreCenterExpenseRequest;
use App\Services\V1\Center\CenterFinancialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CenterFinancialController extends BaseApiController
{
    public function __construct(
        protected CenterFinancialService $financialService
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $data = $this->financialService->getOverview(
            $this->authenticatedUser(),
            $request->query('period'),
            $request->query('branch_id')
        );

        return $this->successResponse($data, __('messages.center.financials_fetched'));
    }

    public function expenses(Request $request): JsonResponse
    {
        $items = $this->financialService->listExpenses(
            $this->authenticatedUser(),
            $request->only(['branch_id', 'category', 'period'])
        );

        return $this->successResponse(
            $items,
            __('messages.center.expenses_fetched'),
            meta: ['total' => count($items)]
        );
    }

    public function storeExpense(StoreCenterExpenseRequest $request): JsonResponse
    {
        $expense = $this->financialService->createExpense(
            $this->authenticatedUser(),
            $request->validated()
        );

        return $this->createdResponse($expense, __('messages.center.expense_created'));
    }

    public function destroyExpense(string $uuid): JsonResponse
    {
        $this->financialService->deleteExpense($this->authenticatedUser(), $uuid);

        return $this->successResponse(null, __('messages.center.expense_deleted'));
    }

    public function exportExpensesCsv(Request $request): Response
    {
        $csv = $this->financialService->exportExpensesCsv(
            $this->authenticatedUser(),
            $request->only(['branch_id', 'category', 'period'])
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="center-expenses.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $list = $this->financialService->listTeacherSettlements(
            $this->authenticatedUser(),
            $request->query('period')
        );

        return $this->successResponse(
            $list,
            __('messages.center.settlements_fetched'),
            meta: ['total' => count($list)]
        );
    }

    public function approveSettlement(Request $request, string $teacherUuid): JsonResponse
    {
        $request->merge(array_filter([
            'reference_note' => $request->input('reference_note', $request->input('referenceNote')),
        ], fn ($v) => $v !== null));

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'reference_note' => ['nullable', 'string', 'max:255'],
        ]);

        $settlement = $this->financialService->approveTeacherSettlement(
            $this->authenticatedUser(),
            $teacherUuid,
            $validated['period'] ?? null,
            $validated['reference_note'] ?? null
        );

        return $this->successResponse($settlement, __('messages.center.settlement_approved'));
    }

    public function dues(Request $request): JsonResponse
    {
        $dues = $this->financialService->listOutstandingDues(
            $this->authenticatedUser(),
            $request->query('branch_id')
        );

        return $this->successResponse($dues, __('messages.center.dues_fetched'));
    }

    public function remindDue(string $enrollmentUuid): JsonResponse
    {
        $result = $this->financialService->remindStudentDue(
            $this->authenticatedUser(),
            $enrollmentUuid
        );

        return $this->successResponse($result, __('messages.center.due_reminder_sent'));
    }

    public function markDuePaid(string $enrollmentUuid): JsonResponse
    {
        $result = $this->financialService->markDuePaid(
            $this->authenticatedUser(),
            $enrollmentUuid
        );

        return $this->successResponse($result, __('messages.center.due_marked_paid'));
    }
}
