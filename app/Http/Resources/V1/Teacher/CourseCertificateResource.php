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
        $baseUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $teacherProfile = $this->teacher?->teacherProfile;
        $teacherNameAr = $teacherProfile?->name_ar ?: ($this->teacher?->name ?: 'معلم معتمد');
        $teacherNameEn = $teacherProfile?->name_en ?: $teacherNameAr;
        $teacherTitleAr = $teacherProfile?->title_ar ?: 'معلم معتمد على منصة متفوّق';
        $teacherTitleEn = $teacherProfile?->title_en ?: 'Certified Motafawweq Educator';

        $gradePercent = (float) $this->grade_percent;
        $scoreLabel = fmod($gradePercent, 1.0) === 0.0
            ? sprintf('%d%%', (int) $gradePercent)
            : sprintf('%.1f%%', $gradePercent);

        return [
            'id' => $this->uuid,
            'uuid' => $this->uuid,
            'certNumber' => $serial,
            'serialId' => $serial,
            'issuerType' => $this->issuer_type ?: 'teacher',
            'category' => $this->issuer_type ?: 'teacher',
            'issuerNameAr' => $teacherNameAr,
            'issuerNameEn' => $teacherNameEn,
            'instructorAr' => $teacherNameAr,
            'instructorEn' => $teacherNameEn,
            'instructorTitleAr' => $teacherTitleAr,
            'instructorTitleEn' => $teacherTitleEn,
            'studentId' => $this->student_id ? (string) $this->student_id : null,
            'studentCode' => $this->student?->student_code,
            'studentAvatar' => $this->student?->avatar,
            'studentNameAr' => $this->student_name_ar,
            'studentNameEn' => $this->student_name_en ?: $this->student_name_ar,
            'studentEmail' => $this->student_email,
            'studentPhone' => $this->student_phone,
            'courseId' => $this->course?->uuid,
            'courseNameAr' => $this->course_name_ar,
            'courseNameEn' => $this->course_name_en ?: $this->course_name_ar,
            'gradeAr' => $this->grade_ar,
            'gradePercent' => $gradePercent,
            'score' => $scoreLabel,
            'scoreEn' => $scoreLabel,
            'honorsTitleAr' => $this->honors_title_ar,
            'honorsTitleEn' => $this->honors_title_en ?: 'Certificate of Academic Excellence',
            'issueDate' => $this->issue_date?->format('Y-m-d'),
            'completionDate' => $this->issue_date?->format('Y-m-d'),
            'status' => $this->status?->value ?? 'verified',
            'revokedAt' => $this->revoked_at?->toIso8601String(),
            'revocationReason' => $this->revocation_reason,
            'verifyPath' => "/verify/{$serial}",
            'verificationUrl' => "{$baseUrl}/verify/{$serial}",
            'signatureHash' => $this->signature_hash,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
