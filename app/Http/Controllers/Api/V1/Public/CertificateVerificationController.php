<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\V1\Teacher\TeacherCertificateService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CertificateVerificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected TeacherCertificateService $certificateService
    ) {}

    public function verify(string $serialNumber): JsonResponse
    {
        $result = $this->certificateService->verifyCertificatePublic($serialNumber);

        if (!$result['isValid'] && ($result['status'] ?? '') === 'not_found') {
            return $this->notFound($result['messageAr'] ?? 'الشهادة المطلوبة غير مسجلة.');
        }

        return $this->success($result, 'تم فحص وتدقيق بيانات الشهادة بنجاح.');
    }
}

