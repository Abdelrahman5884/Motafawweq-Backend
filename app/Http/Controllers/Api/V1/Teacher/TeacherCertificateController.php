<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Teacher\BulkIssueCertificateRequest;
use App\Http\Requests\V1\Teacher\IssueCertificateRequest;
use App\Http\Requests\V1\Teacher\RevokeCertificateRequest;
use App\Http\Resources\V1\Teacher\CourseCertificateResource;
use App\Services\V1\Teacher\TeacherCertificateService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherCertificateController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TeacherCertificateService $certificateService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'course_id' => $request->query('course_id'),
            'status' => $request->query('status'),
        ];

        $teacher = $request->user();
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $paginator = $this->certificateService->listCertificates($teacher, $filters, $perPage);
        $stats = $this->certificateService->getStats($teacher);

        return $this->successResponse(
            CourseCertificateResource::collection($paginator->items()),
            __('messages.certificates.retrieved'),
            meta: [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
                'stats' => $stats,
            ]
        );
    }

    public function store(IssueCertificateRequest $request): JsonResponse
    {
        $certificate = $this->certificateService->issueCertificate(
            $request->user(),
            $request->validated()
        );

        return $this->created(
            new CourseCertificateResource($certificate),
            __('messages.certificates.issued_successfully')
        );
    }

    public function bulkStore(BulkIssueCertificateRequest $request): JsonResponse
    {
        $certificates = $this->certificateService->bulkIssueCertificates(
            $request->user(),
            $request->validated()
        );

        return $this->created(
            CourseCertificateResource::collection($certificates),
            __('messages.certificates.bulk_issued_successfully')
        );
    }

    public function revoke(RevokeCertificateRequest $request, string $uuid): JsonResponse
    {
        $certificate = $this->certificateService->revokeCertificate(
            $request->user(),
            $uuid,
            $request->validated('reason')
        );

        return $this->success(
            new CourseCertificateResource($certificate),
            __('messages.certificates.revoked_successfully')
        );
    }
}
