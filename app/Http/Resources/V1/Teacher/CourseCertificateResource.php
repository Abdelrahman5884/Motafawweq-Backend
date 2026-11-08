<?php

namespace App\Http\Resources\V1\Teacher;

use App\Models\CourseCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CourseCertificate
 */
class CourseCertificateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $serial = $this->certificate_number;
        $baseUrl = config('app.frontend_url', 'https://motafawweq.me');

        return [
            'id'              => $this->uuid,
            'uuid'            => $this->uuid,
            'certNumber'      => $serial,
            'serialId'        => $serial,
            'studentId'       => $this->student_id ? (string) $this->student_id : null,
            'studentNameAr'   => $this->student_name_ar,
            'studentNameEn'   => $this->student_name_en,
            'studentEmail'    => $this->student_email,
            'studentPhone'    => $this->student_phone,
            'courseId'        => $this->course?->uuid,
            'courseNameAr'    => $this->course_name_ar,
            'courseNameEn'    => $this->course_name_en,
            'gradeAr'         => $this->grade_ar,
            'gradePercent'    => (float) $this->grade_percent,
            'score'           => "{$this->grade_percent}%",
            'honorsTitleAr'   => $this->honors_title_ar,
            'honorsTitleEn'   => $this->honors_title_en,
            'issueDate'       => $this->issue_date?->format('Y-m-d'),
            'completionDate'  => $this->issue_date?->format('Y-m-d'),
            'status'          => $this->status?->value ?? 'verified',
            'revokedAt'       => $this->revoked_at?->toIso8601String(),
            'revocationReason'=> $this->revocation_reason,
            'verificationUrl' => "{$baseUrl}/verify/{$serial}",
            'signatureHash'   => $this->signature_hash,
            'createdAt'       => $this->created_at?->toIso8601String(),
        ];
    }
}

