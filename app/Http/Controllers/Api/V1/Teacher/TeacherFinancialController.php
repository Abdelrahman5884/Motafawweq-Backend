<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Teacher\DirectPaymentActionRequest;
use App\Http\Resources\V1\Teacher\StudentDirectPaymentResource;
use App\Services\V1\Teacher\TeacherFinancialService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherFinancialController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TeacherFinancialService $financialService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'method' => $request->query('method'),
            'status' => $request->query('status'),
        ];

        $overview = $this->financialService->getFinancialOverview(
            $request->user(),
            $filters,
            (int) $request->query('per_page', 25)
        );

        return $this->success([
            'earnings'     => $overview['earnings'],
            'transactions' => StudentDirectPaymentResource::collection($overview['transactions']->items()),
            'pagination'   => [
                'currentPage' => $overview['transactions']->currentPage(),
                'lastPage'    => $overview['transactions']->lastPage(),
                'perPage'     => $overview['transactions']->perPage(),
                'total'       => $overview['transactions']->total(),
            ],
        ], __('messages.financials.retrieved'));
    }

    public function confirm(DirectPaymentActionRequest $request, string $uuid): JsonResponse
    {
        $payment = $this->financialService->confirmDirectPayment(
            $request->user(),
            $uuid
        );

        return $this->success(
            new StudentDirectPaymentResource($payment),
            __('messages.financials.payment_confirmed')
        );
    }

    public function reject(DirectPaymentActionRequest $request, string $uuid): JsonResponse
    {
        $payment = $this->financialService->rejectDirectPayment(
            $request->user(),
            $uuid,
            $request->validated('reason')
        );

        return $this->success(
            new StudentDirectPaymentResource($payment),
            __('messages.financials.payment_rejected')
        );
    }
}

