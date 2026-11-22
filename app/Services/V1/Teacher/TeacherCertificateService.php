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
use Illuminate\Validation\ValidationException;

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

    /**
     * @return array{total: int, verifiedCount: int, revokedCount: int, averageGrade: float}
     */
    public function getStats(User $teacher): array
    {
        return $this->certificateRepository->getTeacherStats($teacher->id);
    }

    public function issueCertificate(User $teacher, array $data): CourseCertificate
    {
        $course = null;
        if (! empty($data['course_id'])) {
            $rawCourseId = trim((string) $data['course_id']);
            $course = Course::where('teacher_id', $teacher->id)
                ->where(function ($q) use ($rawCourseId) {
                    if (is_numeric($rawCourseId)) {
                        $q->where('id', (int) $rawCourseId);
                    } else {
                        $q->where('uuid', $rawCourseId);
                    }
                })->first();
        }
        $courseId = $course?->id;

        /** @var User|null $student */
        $student = null;
        if (! empty($data['student_id'])) {
            $rawStudentId = trim((string) $data['student_id']);
            $candidate = is_numeric($rawStudentId)
                ? User::find((int) $rawStudentId)
                : User::where('uuid', $rawStudentId)
                    ->orWhere('student_code', $rawStudentId)
                    ->first();

            if ($candidate && $candidate->isStudent()) {
                $student = $candidate;
            }
        }

        $rawEmail = trim((string) ($data['student_email'] ?? $data['studentEmail'] ?? ''));
        if ($student === null && $rawEmail !== '') {
            $candidate = User::whereRaw('LOWER(email) = ?', [strtolower($rawEmail)])->first();
            if ($candidate && $candidate->isStudent()) {
                $student = $candidate;
            }
        }
        $studentId = $student?->id;

        $certNumber = CourseCertificate::generateUniqueCertNumber();
        $studentNameAr = trim((string) ($data['student_name_ar'] ?? $data['studentNameAr'] ?? $student?->name ?? ''));
        $gradePercent = round(min(100.0, max(0.0, (float) ($data['grade_percent'] ?? $data['gradePercent'] ?? 100.0))), 2);
        $issueDate = ! empty($data['issue_date']) ? substr(trim((string) $data['issue_date']), 0, 10) : now()->toDateString();

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
            'signature_hash' => $signatureHash,
            'teacher_id' => $teacher->id,
            'student_id' => $studentId,
            'course_id' => $courseId,
            'student_name_ar' => $studentNameAr,
            'student_name_en' => $data['student_name_en'] ?? $data['studentNameEn'] ?? null,
            'student_email' => ($rawEmail !== '' ? $rawEmail : null) ?? $student?->email,
            'student_phone' => ($data['student_phone'] ?? $data['studentPhone'] ?? null) ?: $student?->phone,
            'course_name_ar' => trim((string) ($data['course_name_ar'] ?? $data['courseNameAr'] ?? $course?->title_ar ?? 'المقرر الدراسي المعتمد')),
            'course_name_en' => ($data['course_name_en'] ?? $data['courseNameEn'] ?? null) ?: $course?->title_en,
            'grade_ar' => ($data['grade_ar'] ?? $data['gradeAr'] ?? null) ?: ($course?->grade_ar ?? 'الصف الثالث الثانوي'),
            'grade_percent' => $gradePercent,
            'honors_title_ar' => trim((string) ($data['honors_title_ar'] ?? $data['honorsTitleAr'] ?? 'شهادة تميز وتفوق بالدرجة النهائية (Full Mark)')),
            'honors_title_en' => $data['honors_title_en'] ?? $data['honorsTitleEn'] ?? 'Certificate of Academic Excellence',
            'issue_date' => $issueDate,
            'status' => CertificateStatus::VERIFIED,
            'issuer_type' => 'teacher',
        ]);

        $this->logSecurityEvent(
            'teacher.certificate_issued',
            [
                'teacher_id' => $teacher->id,
                'certificate_number' => $certNumber,
                'student_name_ar' => $studentNameAr,
                'signature_hash' => $signatureHash,
            ],
            'info',
            $certificate
        );

        return $certificate->load(['teacher.teacherProfile', 'student', 'course']);
    }

    public function bulkIssueCertificates(User $teacher, array $data): array
    {
        $students = $data['students'] ?? [];
        $sharedData = $data;
        unset($sharedData['students']);

        $issuedCertificates = [];

        DB::transaction(function () use ($teacher, $sharedData, $students, &$issuedCertificates) {
            foreach ($students as $studentData) {
                $merged = array_merge($sharedData, $studentData);
                $issuedCertificates[] = $this->issueCertificate($teacher, $merged);
            }
        });

        return $issuedCertificates;
    }

    public function revokeCertificate(User $teacher, string $uuid, ?string $reason = null): CourseCertificate
    {
        $cert = $this->certificateRepository->findByUuidForTeacher($uuid, $teacher->id);

        if (! $cert) {
            $this->logSecurityEvent(
                'teacher.idor_attempt_blocked',
                [
                    'target_type' => 'CourseCertificate',
                    'target_uuid' => $uuid,
                    'actor_id' => $teacher->id,
                    'action' => 'revoke',
                ],
                'warning'
            );

            throw (new ModelNotFoundException)->setModel(CourseCertificate::class, [$uuid]);
        }

        if ($cert->status === CertificateStatus::REVOKED) {
            throw ValidationException::withMessages([
                'certificate' => ['هذه الشهادة ملغاة بالفعل.'],
            ]);
        }

        $cert->update([
            'status' => CertificateStatus::REVOKED,
            'revoked_at' => now(),
            'revocation_reason' => trim((string) $reason) ?: 'تم إلغاء الشهادة بواسطة المعلم المسؤول',
        ]);

        $this->logSecurityEvent(
            'teacher.certificate_revoked',
            [
                'teacher_id' => $teacher->id,
                'certificate_number' => $cert->certificate_number,
                'reason' => $reason,
            ],
            'warning',
            $cert
        );

        return $cert->fresh(['teacher.teacherProfile', 'student', 'course']);
    }

    public function verifyCertificatePublic(string $certNumber): array
    {
        $cleanNumber = trim(strtoupper($certNumber));
        $cert = $this->certificateRepository->findByCertNumber($cleanNumber);

        if (! $cert) {
            return [
                'isValid' => false,
                'status' => 'not_found',
                'certNumber' => $cleanNumber,
                'messageAr' => 'الشهادة المطلوبة غير مسجلة بقاعدة بيانات منصة متفوق الرسمية',
                'messageEn' => 'The requested certificate was not found in the official registry.',
            ];
        }

        $signatureValid = $cert->isValidSignature();
        $isRevoked = ($cert->status === CertificateStatus::REVOKED);

        $teacherProfile = $cert->teacher?->teacherProfile;
        $teacherName = $teacherProfile?->name_ar ?? $cert->teacher?->name ?? 'معلم معتمد';
        $teacherTitle = $teacherProfile?->title_ar ?? 'كبير معلمي المادة ومؤلف سلسلة التفوق';
        $issuerType = in_array($cert->issuer_type, ['teacher', 'ministry', 'league'], true)
            ? $cert->issuer_type
            : 'teacher';
        $meta = is_array($cert->metadata) ? $cert->metadata : [];
        $gradePercent = (float) $cert->grade_percent;
        $scoreLabel = fmod($gradePercent, 1.0) === 0.0
            ? sprintf('%d%%', (int) $gradePercent)
            : sprintf('%.1f%%', $gradePercent);

        return [
            'isValid' => $signatureValid && ! $isRevoked,
            'status' => $isRevoked ? 'revoked' : ($signatureValid ? 'verified' : 'tampered'),
            'certNumber' => $cert->certificate_number,
            'serialId' => $cert->certificate_number,
            'signatureHash' => $cert->signature_hash,
            'issuerType' => $issuerType,
            'category' => $issuerType,
            'issuerNameAr' => $issuerType === 'teacher' ? $teacherName : 'إدارة منصة متفوّق التعليمية',
            'issuerNameEn' => $issuerType === 'teacher' ? ($teacherProfile?->name_en ?? $teacherName) : 'Motafawweq Platform Administration',
            'instructorAr' => $teacherName,
            'instructorEn' => $teacherProfile?->name_en ?? $teacherName,
            'instructorTitleAr' => $teacherTitle,
            'instructorTitleEn' => $teacherProfile?->title_en ?? 'Senior Lecturer',
            'studentNameAr' => $cert->student_name_ar,
            'studentNameEn' => $cert->student_name_en ?: $cert->student_name_ar,
            'courseNameAr' => $cert->course_name_ar,
            'courseNameEn' => $cert->course_name_en ?: $cert->course_name_ar,
            'gradeAr' => $cert->grade_ar ?: 'الصف الثالث الثانوي',
            'score' => $scoreLabel,
            'scoreEn' => $scoreLabel,
            'completionRate' => '100%',
            'completionDate' => $cert->issue_date ? $cert->issue_date->format('Y-m-d') : null,
            'completionDateEn' => $cert->issue_date?->format('F j, Y'),
            'honorsTitleAr' => $cert->honors_title_ar,
            'honorsTitleEn' => $cert->honors_title_en ?: 'Certificate of Academic Excellence',
            'rankAr' => $meta['rankAr'] ?? null,
            'rankEn' => $meta['rankEn'] ?? null,
            'pointsAr' => $meta['pointsAr'] ?? null,
            'pointsEn' => $meta['pointsEn'] ?? null,
            'revokedAt' => $cert->revoked_at?->toIso8601String(),
            'revocationReason' => $cert->revocation_reason,
            'tampered' => ! $signatureValid,
        ];
    }
}
