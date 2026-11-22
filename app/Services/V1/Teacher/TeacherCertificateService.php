<?php

namespace App\Services\V1\Teacher;

use App\Enums\CertificateStatus;
use App\Enums\LessonStatus;
use App\Models\Course;
use App\Models\CourseCertificate;
use App\Models\CourseLeague;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\User;
use App\Repositories\Contracts\CourseCertificateRepositoryInterface;
use App\Traits\HasSecurityAudit;
use Carbon\Carbon;
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

    /**
     * Provide live eligibility context for issuing League & 100%-Course-Completion certificates.
     *
     * @return array<string, mixed>
     */
    public function getEligibilityContext(User $teacher, ?string $courseIdentifier = null): array
    {
        // Auto-expire any active leagues whose end date has passed
        CourseLeague::forTeacher($teacher->id)
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now())
            ->update(['is_active' => false]);

        $leagues = CourseLeague::forTeacher($teacher->id)
            ->with(['course:id,uuid,title_ar', 'participants.student:id,name,email,phone,avatar,student_code'])
            ->orderByDesc('crowned_at')
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->get();

        $leaguesPayload = $leagues->map(function (CourseLeague $lg) {
            $concluded = $lg->isConcluded();
            $participants = $concluded
                ? $lg->participants
                    ->sortBy('rank')
                    ->values()
                    ->map(fn (LeagueParticipant $p) => [
                        'id' => $p->uuid,
                        'studentId' => (string) $p->student_id,
                        'nameAr' => $p->student?->name ?: 'طالب',
                        'email' => $p->student?->email,
                        'phone' => $p->student?->phone,
                        'avatar' => $p->student?->avatar,
                        'studentCode' => $p->student?->student_code,
                        'rank' => (int) $p->rank,
                        'score' => (int) $p->xp_points,
                    ])->all()
                : [];

            return [
                'id' => $lg->uuid,
                'titleAr' => $lg->title_ar,
                'courseId' => $lg->course?->uuid,
                'courseNameAr' => $lg->course_name_ar ?: ($lg->course?->title_ar ?? 'المقرر الدراسي'),
                'isActive' => (bool) $lg->is_active && ! $concluded,
                'isCrowned' => $lg->isCrowned(),
                'isConcluded' => $concluded,
                'crownedAt' => $lg->crowned_at?->toIso8601String(),
                'endsAt' => $lg->ends_at?->toIso8601String(),
                'totalCompetitors' => $lg->participants->count(),
                'participants' => $participants,
            ];
        })->values()->all();

        $courseCompletion = null;
        if (! empty($courseIdentifier)) {
            $rawCourseId = trim($courseIdentifier);
            $course = Course::where('teacher_id', $teacher->id)
                ->where(function ($q) use ($rawCourseId) {
                    if (is_numeric($rawCourseId)) {
                        $q->where('id', (int) $rawCourseId);
                    } else {
                        $q->where('uuid', $rawCourseId);
                    }
                })
                ->first();

            if ($course) {
                $totalPublished = Lesson::where('course_id', $course->id)
                    ->where('status', LessonStatus::PUBLISHED)
                    ->count();

                $completedByStudent = $totalPublished > 0
                    ? StudentLessonProgress::where('course_id', $course->id)
                        ->where('is_completed', true)
                        ->whereHas('lesson', fn ($q) => $q->where('status', LessonStatus::PUBLISHED))
                        ->select(['student_id', DB::raw('COUNT(DISTINCT lesson_id) as done_count')])
                        ->groupBy('student_id')
                        ->pluck('done_count', 'student_id')
                    : collect();

                $studentMap = [];
                foreach ($completedByStudent as $sid => $done) {
                    $doneInt = min($totalPublished, (int) $done);
                    $percent = $totalPublished > 0 ? (int) round(($doneInt / $totalPublished) * 100) : 0;
                    $studentMap[(string) $sid] = [
                        'completedLessons' => $doneInt,
                        'totalLessons' => $totalPublished,
                        'percent' => $percent,
                        'eligible' => $totalPublished > 0 && $doneInt >= $totalPublished,
                    ];
                }

                $courseCompletion = [
                    'courseId' => $course->uuid,
                    'courseTitleAr' => $course->title_ar,
                    'totalPublishedLessons' => $totalPublished,
                    'byStudent' => $studentMap,
                ];
            }
        }

        return [
            'leagues' => $leaguesPayload,
            'courseCompletion' => $courseCompletion,
        ];
    }

    /**
     * Resolve certificate issuance track (general | course_completion | league).
     */
    protected function resolveCertificateType(array $data): string
    {
        $explicit = strtolower(trim((string) ($data['certificate_type'] ?? '')));
        if (in_array($explicit, ['general', 'course_completion', 'league'], true)) {
            return $explicit;
        }

        if (! empty($data['league_id'])) {
            return 'league';
        }

        $honorsAr = trim((string) ($data['honors_title_ar'] ?? $data['honorsTitleAr'] ?? ''));
        $honorsEn = strtolower(trim((string) ($data['honors_title_en'] ?? $data['honorsTitleEn'] ?? '')));

        if (
            str_contains($honorsAr, 'الدوري')
            || str_contains($honorsEn, 'league')
        ) {
            return 'league';
        }

        if (
            str_contains($honorsAr, 'إتمام الكورس')
            || str_contains($honorsAr, 'اتمام الكورس')
            || str_contains($honorsAr, 'إتمام المقرر')
            || str_contains($honorsAr, 'اتمام المقرر')
            || str_contains($honorsEn, 'course completion')
        ) {
            return 'course_completion';
        }

        return 'general';
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

        $studentNameAr = trim((string) ($data['student_name_ar'] ?? $data['studentNameAr'] ?? $student?->name ?? ''));
        $certificateType = $this->resolveCertificateType($data);
        $issuerType = 'teacher';
        $certPrefix = 'BIO';
        $metadata = null;

        // ── Strict Rule 1: League certificates ONLY allowed after league ends or teacher crowns it ──
        if ($certificateType === 'league') {
            /** @var CourseLeague|null $league */
            $league = null;
            if (! empty($data['league_id'])) {
                $rawLeagueId = trim((string) $data['league_id']);
                $league = CourseLeague::forTeacher($teacher->id)
                    ->where(function ($q) use ($rawLeagueId) {
                        if (is_numeric($rawLeagueId)) {
                            $q->where('id', (int) $rawLeagueId);
                        } else {
                            $q->where('uuid', $rawLeagueId);
                        }
                    })
                    ->first();
            } else {
                $leagueQuery = CourseLeague::forTeacher($teacher->id)
                    ->orderByDesc('crowned_at')
                    ->orderByDesc('ends_at')
                    ->orderByDesc('id');
                if ($courseId) {
                    $league = (clone $leagueQuery)->where('course_id', $courseId)->first()
                        ?? $leagueQuery->first();
                } else {
                    $league = $leagueQuery->first();
                }
            }

            if ($league && $league->is_active && $league->ends_at && $league->ends_at->isPast()) {
                $league->update(['is_active' => false]);
            }

            if (! $league || ! $league->isConcluded()) {
                throw ValidationException::withMessages([
                    'league_id' => ['لا يمكن إصدار أو إعلان شهادة الدوري قبل انتهاء موعد الدوري أو إعلان تتويج الدوري وإيقافه أولاً.'],
                ]);
            }

            $issuerType = 'league';
            $certPrefix = 'LGE';
            $courseId ??= $league->course_id;
            $metadata = [
                'league_key' => 'course:'.$league->uuid,
                'league_uuid' => $league->uuid,
                'is_republic' => false,
            ];

            if ($student) {
                $part = LeagueParticipant::where('course_league_id', $league->id)
                    ->where('student_id', $student->id)
                    ->first();
                if ($part) {
                    $rank = (int) $part->rank;
                    $points = (int) $part->xp_points;
                    $metadata['rank'] = $rank;
                    $metadata['points'] = $points;
                    $metadata['rankAr'] = match ($rank) {
                        1 => 'المركز الأول 🥇',
                        2 => 'المركز الثاني 🥈',
                        3 => 'المركز الثالث 🥉',
                        default => "المركز #{$rank}",
                    };
                    $metadata['rankEn'] = match ($rank) {
                        1 => '1st Place 🥇',
                        2 => '2nd Place 🥈',
                        3 => '3rd Place 🥉',
                        default => "Rank #{$rank}",
                    };
                    $metadata['pointsAr'] = number_format($points).' نقطة';
                    $metadata['pointsEn'] = number_format($points).' pts';
                }
            }
        }

        // ── Strict Rule 2: Course Completion certificates REQUIRE student to complete 100% of course ──
        if ($certificateType === 'course_completion') {
            if (! $course) {
                throw ValidationException::withMessages([
                    'course_id' => ['يجب اختيار المقرر الدراسي المرتبط لإصدار شهادة إتمام الكورس.'],
                ]);
            }
            if (! $student) {
                throw ValidationException::withMessages([
                    'student_id' => ['يجب اختيار طالب مسجل على المنصة للتحقق من إكماله 100% من الكورس.'],
                ]);
            }

            $totalPublished = Lesson::where('course_id', $course->id)
                ->where('status', LessonStatus::PUBLISHED)
                ->count();

            if ($totalPublished === 0) {
                throw ValidationException::withMessages([
                    'course_id' => ['لا يمكن إصدار شهادة إتمام الكورس لعدم وجود حصص منشورة في هذا المقرر بعد.'],
                ]);
            }

            $completedLessons = StudentLessonProgress::where('student_id', $student->id)
                ->where('course_id', $course->id)
                ->where('is_completed', true)
                ->whereHas('lesson', fn ($q) => $q->where('status', LessonStatus::PUBLISHED))
                ->distinct('lesson_id')
                ->count('lesson_id');

            if ($completedLessons < $totalPublished) {
                $percent = (int) round(($completedLessons / $totalPublished) * 100);
                throw ValidationException::withMessages([
                    'student_id' => [
                        "لا يمكن إصدار شهادة إتمام الكورس للطالب ({$studentNameAr}) قبل إكمال 100% من حصص المقرر المنشورة (المكتمل حالياً: {$completedLessons} من {$totalPublished} حصة — {$percent}%).",
                    ],
                ]);
            }

            $certPrefix = 'CRS';
        }

        $certNumber = CourseCertificate::generateUniqueCertNumber($certPrefix);
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
            'issuer_type' => $issuerType,
            'metadata' => $metadata,
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
