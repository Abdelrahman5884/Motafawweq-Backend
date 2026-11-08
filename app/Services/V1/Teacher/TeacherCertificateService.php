<?php

namespace App\Services\V1\Teacher;

use App\Enums\CertificateStatus;
use App\Models\Course;
use App\Models\CourseCertificate;
use App\Models\User;
use App\Repositories\Contracts\CourseCertificateRepositoryInterface;
use App\Traits\HasSecurityAudit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class TeacherCertificateService
{
    use HasSecurityAudit;

    public function __construct(
        protected CourseCertificateRepositoryInterface $certificateRepository
    ) {}

    public function listCertificates(User $teacher, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->certificateRepository->getForTeacher($teacher->id, $filters, $perPage);
    }

    public function issueCertificate(User $teacher, array $data): CourseCertificate
    {
        $courseId = null;
        if (!empty($data['course_id'])) {
            $course = Course::where('teacher_id', $teacher->id)
                ->where(function ($q) use ($data) {
                    $q->where('id', $data['course_id'])
                        ->orWhere('uuid', $data['course_id']);
                })->first();

            $courseId = $course?->id;
        }

        $studentId = null;
        if (!empty($data['student_id'])) {
            $student = User::find($data['student_id']);
            $studentId = $student?->id;
        }

        $certNumber = CourseCertificate::generateUniqueCertNumber();
        $studentNameAr = trim((string) ($data['student_name_ar'] ?? $data['studentNameAr'] ?? ''));
        $gradePercent = (float) ($data['grade_percent'] ?? $data['gradePercent'] ?? 100.0);
        $issueDate = !empty($data['issue_date']) ? (string) $data['issue_date'] : now()->toDateString();

        $signatureHash = CourseCertificate::computeSignatureHash(
            $certNumber,
            $teacher->id,
            $studentNameAr,
            $gradePercent,
            $issueDate
        );

        /** @var CourseCertificate $certificate */
        $certificate = CourseCertificate::create([
            'certificate_number' => $certNumber,
            'signature_hash'     => $signatureHash,
            'teacher_id'         => $teacher->id,
            'student_id'         => $studentId,
            'course_id'          => $courseId,
            'student_name_ar'    => $studentNameAr,
            'student_name_en'    => $data['student_name_en'] ?? $data['studentNameEn'] ?? null,
            'student_email'      => $data['student_email'] ?? $data['studentEmail'] ?? null,
            'student_phone'      => $data['student_phone'] ?? $data['studentPhone'] ?? null,
            'course_name_ar'     => trim((string) ($data['course_name_ar'] ?? $data['courseNameAr'] ?? 'المقرر الدراسي المعتمد')),
            'course_name_en'     => $data['course_name_en'] ?? $data['courseNameEn'] ?? null,
            'grade_ar'           => $data['grade_ar'] ?? $data['gradeAr'] ?? 'الصف الثالث الثانوي',
            'grade_percent'      => $gradePercent,
            'honors_title_ar'    => trim((string) ($data['honors_title_ar'] ?? $data['honorsTitleAr'] ?? 'شهادة تميز وتفوق بالدرجة النهائية (Full Mark)')),
            'honors_title_en'    => $data['honors_title_en'] ?? $data['honorsTitleEn'] ?? 'Certificate of Academic Excellence',
            'issue_date'         => $issueDate,
            'status'             => CertificateStatus::VERIFIED,
        ]);

        $this->logSecurityEvent(
            'teacher.certificate_issued',
            [
                'teacher_id'         => $teacher->id,
                'certificate_number' => $certNumber,
                'student_name_ar'    => $studentNameAr,
                'signature_hash'     => $signatureHash,
            ],
            'info',
            $certificate
        );

        return $certificate;
    }

    public function bulkIssueCertificates(User $teacher, array $data): array
    {
        $students = $data['students'] ?? [];
        $issuedCertificates = [];

        DB::transaction(function () use ($teacher, $data, $students, &$issuedCertificates) {
            foreach ($students as $studentData) {
                $merged = array_merge($data, $studentData);
                $issuedCertificates[] = $this->issueCertificate($teacher, $merged);
            }
        });

        return $issuedCertificates;
    }

    public function revokeCertificate(User $teacher, string $uuid, ?string $reason = null): CourseCertificate
    {
        $cert = $this->certificateRepository->findByUuidForTeacher($uuid, $teacher->id);

        if (!$cert) {
            $this->logSecurityEvent(
                'teacher.idor_attempt_blocked',
                [
                    'target_type' => 'CourseCertificate',
                    'target_uuid' => $uuid,
                    'actor_id'    => $teacher->id,
                    'action'      => 'revoke',
                ],
                'warning'
            );

            throw (new ModelNotFoundException())->setModel(CourseCertificate::class, [$uuid]);
        }

        $cert->update([
            'status'            => CertificateStatus::REVOKED,
            'revoked_at'        => now(),
            'revocation_reason' => $reason ?: 'تم إلغاء الشهادة بواسطة المعلم المسؤول',
        ]);

        $this->logSecurityEvent(
            'teacher.certificate_revoked',
            [
                'teacher_id'         => $teacher->id,
                'certificate_number' => $cert->certificate_number,
                'reason'             => $reason,
            ],
            'warning',
            $cert
        );

        return $cert;
    }

    public function verifyCertificatePublic(string $certNumber): array
    {
        $cleanNumber = trim(strtoupper($certNumber));
        $cert = $this->certificateRepository->findByCertNumber($cleanNumber);

        if (!$cert) {
            return [
                'isValid'       => false,
                'status'        => 'not_found',
                'certNumber'    => $cleanNumber,
                'messageAr'     => 'الشهادة المطلوبة غير مسجلة بقاعدة بيانات منصة متفوق الرسمية',
                'messageEn'     => 'The requested certificate was not found in the official registry.',
            ];
        }

        $signatureValid = $cert->isValidSignature();
        $isRevoked = ($cert->status === CertificateStatus::REVOKED);

        $teacherProfile = $cert->teacher?->teacherProfile;
        $teacherName = $teacherProfile?->name_ar ?? $cert->teacher?->name ?? 'معلم معتمد';
        $teacherTitle = $teacherProfile?->title_ar ?? 'كبير معلمي المادة ومؤلف سلسلة التفوق';

        return [
            'isValid'          => $signatureValid && !$isRevoked,
            'status'           => $isRevoked ? 'revoked' : ($signatureValid ? 'verified' : 'tampered'),
            'certNumber'       => $cert->certificate_number,
            'serialId'         => $cert->certificate_number,
            'signatureHash'    => $cert->signature_hash,
            'issuerType'       => 'teacher',
            'issuerNameAr'     => $teacherName,
            'issuerNameEn'     => $teacherProfile?->name_en ?? $teacherName,
            'instructorAr'     => $teacherName,
            'instructorEn'     => $teacherProfile?->name_en ?? $teacherName,
            'instructorTitleAr'=> $teacherTitle,
            'instructorTitleEn'=> $teacherProfile?->title_en ?? 'Senior Lecturer',
            'studentNameAr'    => $cert->student_name_ar,
            'studentNameEn'    => $cert->student_name_en ?: $cert->student_name_ar,
            'courseNameAr'     => $cert->course_name_ar,
            'courseNameEn'     => $cert->course_name_en ?: $cert->course_name_ar,
            'gradeAr'          => $cert->grade_ar ?: 'الصف الثالث الثانوي',
            'score'            => "{$cert->grade_percent}%",
            'completionDate'   => $cert->issue_date ? $cert->issue_date->format('Y-m-d') : null,
            'honorsTitleAr'    => $cert->honors_title_ar,
            'revokedAt'        => $cert->revoked_at?->toIso8601String(),
            'revocationReason' => $cert->revocation_reason,
            'tampered'         => !$signatureValid,
        ];
    }
}

