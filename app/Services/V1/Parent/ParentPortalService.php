<?php

namespace App\Services\V1\Parent;

use App\Enums\AttendanceStatus;
use App\Enums\CourseStatus;
use App\Enums\DayOfWeek;
use App\Enums\DirectPaymentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\HomeworkSubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LessonStatus;
use App\Enums\PaymentMethod;
use App\Enums\StudentPlanTier;
use App\Enums\UserRole;
use App\Models\CenterAbsenceAlert;
use App\Models\CenterProfile;
use App\Models\Course;
use App\Models\ExamSubmission;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\ParentInquiry;
use App\Models\ParentProfile;
use App\Models\StudentDirectPayment;
use App\Models\StudentInvoice;
use App\Models\StudentLessonProgress;
use App\Models\StudentRiskSignal;
use App\Models\StudentSubscription;
use App\Models\User;
use App\Services\BaseService;
use App\Services\V1\Student\StudentAccessService;
use App\Services\V1\Student\StudentBillingService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParentPortalService extends BaseService
{
    private const DAY_ORDER = [
        'saturday' => 6,
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
    ];

    public function __construct(
        protected StudentAccessService $studentAccessService,
        protected StudentBillingService $studentBillingService
    ) {}

    /**
     * Strictly verify that a student is linked to this parent account.
     * Logs a security audit event and throws 403 on any IDOR attempt.
     *
     * @throws AuthorizationException
     */
    public function resolveLinkedChild(User $parent, int $studentId): User
    {
        /** @var User|null $child */
        $child = $parent->children()
            ->where('users.id', $studentId)
            ->where('users.role', UserRole::STUDENT->value)
            ->with('studentProfile')
            ->first();

        if (! $child) {
            $this->logSecurityEvent(
                'parent.idor_attempt_blocked',
                [
                    'attempted_by_parent_id' => $parent->id,
                    'target_student_id' => $studentId,
                ],
                'warning',
                $parent
            );

            throw new AuthorizationException(__('messages.parent.child_not_linked'));
        }

        return $child;
    }

    /**
     * Get the authenticated parent profile + summaries for all linked children.
     */
    public function getOverview(User $parent): array
    {
        $profile = $this->ensureParentProfile($parent);

        /** @var Collection<int, User> $children */
        $children = $parent->children()
            ->where('users.role', UserRole::STUDENT->value)
            ->with('studentProfile')
            ->orderBy('parent_student.created_at')
            ->get();

        $childIds = $children->pluck('id')->all();
        $prefetched = $this->prefetchChildrenMetrics($childIds);

        $childrenSummaries = $children
            ->map(fn (User $child) => $this->buildChildSummary($child, $prefetched))
            ->values()
            ->all();

        $inquiriesCount = ParentInquiry::query()
            ->where('parent_id', $parent->id)
            ->count();

        return [
            'parent' => $this->formatParentSettings($parent, $profile),
            'children' => $childrenSummaries,
            'inquiriesCount' => $inquiriesCount,
        ];
    }

    /**
     * Fetch the complete live dashboard snapshot for a single linked child.
     */
    public function getChildDashboard(User $parent, int $studentId): array
    {
        $child = $this->resolveLinkedChild($parent, $studentId);
        $prefetched = $this->prefetchChildrenMetrics([$child->id]);

        return [
            'summary' => $this->buildChildSummary($child, $prefetched),
            'courses' => $this->buildChildCoursesPayload($child),
            'attendance' => $this->buildChildAttendancePayload($child, null, 30),
            'exams' => $this->buildChildExamsPayload($child, 30),
            'homework' => $this->buildChildHomeworkPayload($child, null),
            'financials' => $this->buildChildFinancialsPayload($child),
        ];
    }

    public function getChildCourses(User $parent, int $studentId): array
    {
        $child = $this->resolveLinkedChild($parent, $studentId);

        return $this->buildChildCoursesPayload($child);
    }

    public function getChildAttendance(User $parent, int $studentId, ?string $status = null, int $perPage = 25): array
    {
        $child = $this->resolveLinkedChild($parent, $studentId);

        return $this->buildChildAttendancePayload($child, $status, $perPage);
    }

    public function getChildExams(User $parent, int $studentId, int $perPage = 25): array
    {
        $child = $this->resolveLinkedChild($parent, $studentId);

        return $this->buildChildExamsPayload($child, $perPage);
    }

    public function getChildHomework(User $parent, int $studentId, ?string $status = null): array
    {
        $child = $this->resolveLinkedChild($parent, $studentId);

        return $this->buildChildHomeworkPayload($child, $status);
    }

    public function getChildFinancials(User $parent, int $studentId): array
    {
        $child = $this->resolveLinkedChild($parent, $studentId);

        return $this->buildChildFinancialsPayload($child);
    }

    /**
     * Build a formula-injection-safe UTF-8 CSV exam scorecard for a linked child.
     *
     * @return array{filename: string, csv: string}
     */
    public function exportChildExamsCsv(User $parent, int $studentId): array
    {
        $child = $this->resolveLinkedChild($parent, $studentId);
        $payload = $this->buildChildExamsPayload($child, 200);

        $rows = [
            ['كود الطالب', 'اسم الطالب', 'الاختبار', 'المادة', 'المعلم', 'التاريخ', 'الدرجة', 'الدرجة النهائية', 'النسبة المئوية', 'الترتيب', 'الحالة', 'ملاحظات المعلم'],
        ];

        foreach ($payload['items'] as $item) {
            $rows[] = [
                $child->student_code ?: ('STD-'.$child->id),
                $child->name,
                $item['titleAr'],
                $item['subjectAr'],
                $item['teacherAr'],
                $item['date'] ?? '',
                $item['score'] !== null ? (string) $item['score'] : '—',
                (string) $item['maxScore'],
                $item['percent'],
                $item['rankAr'],
                $item['statusAr'],
                $item['notesAr'],
            ];
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, array_map([$this, 'sanitizeCsvCell'], $row));
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $this->logSecurityEvent(
            'parent.scorecard_exported',
            [
                'parent_id' => $parent->id,
                'student_id' => $child->id,
                'rows_count' => count($payload['items']),
            ],
            'info',
            $child
        );

        $safeCode = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) ($child->student_code ?: 'student-'.$child->id)) ?: ('student-'.$child->id);

        return [
            'filename' => "scorecard-{$safeCode}-".now()->format('Y-m-d').'.csv',
            'csv' => $csv,
        ];
    }

    /**
     * Link a student to the authenticated parent using student_code OR center barcode.
     */
    public function linkChildByCode(User $parent, string $rawCode): array
    {
        $normalized = strtoupper(trim($rawCode));

        /** @var User|null $student */
        $student = User::query()
            ->where('role', UserRole::STUDENT->value)
            ->whereRaw('UPPER(student_code) = ?', [$normalized])
            ->with('studentProfile')
            ->first();

        if (! $student) {
            $enrollment = GroupEnrollment::query()
                ->whereRaw('UPPER(student_barcode) = ?', [$normalized])
                ->with(['student.studentProfile'])
                ->first();

            if ($enrollment?->student && $enrollment->student->isStudent()) {
                $student = $enrollment->student;
            }
        }

        if (! $student || ! $student->isActive()) {
            throw ValidationException::withMessages([
                'student_code' => [__('messages.parent.child_code_not_found')],
            ]);
        }

        $alreadyLinked = $parent->children()
            ->where('users.id', $student->id)
            ->exists();

        if ($alreadyLinked) {
            throw ValidationException::withMessages([
                'student_code' => [__('messages.parent.child_already_linked')],
            ]);
        }

        $this->transaction(function () use ($parent, $student): void {
            $parent->children()->syncWithoutDetaching([$student->id]);
        });

        $this->logSecurityEvent(
            'parent.child_linked',
            [
                'parent_id' => $parent->id,
                'student_id' => $student->id,
                'student_code' => $student->student_code,
            ],
            'info',
            $student
        );

        $prefetched = $this->prefetchChildrenMetrics([$student->id]);

        return $this->buildChildSummary($student, $prefetched);
    }

    /**
     * Unlink a previously linked student from this parent account.
     */
    public function unlinkChild(User $parent, int $studentId): void
    {
        $child = $this->resolveLinkedChild($parent, $studentId);

        $this->transaction(function () use ($parent, $child): void {
            $parent->children()->detach($child->id);
        });

        $this->logSecurityEvent(
            'parent.child_unlinked',
            [
                'parent_id' => $parent->id,
                'student_id' => $child->id,
            ],
            'info',
            $child
        );
    }

    /**
     * List inquiries submitted by this parent (optionally filtered to a specific linked child).
     */
    public function listInquiries(User $parent, ?int $studentId = null, int $perPage = 15): array
    {
        if ($studentId !== null) {
            $this->resolveLinkedChild($parent, $studentId);
        }

        $query = ParentInquiry::query()
            ->where('parent_id', $parent->id)
            ->with([
                'student:id,name,student_code',
                'center:id,name',
                'center.centerProfile:id,user_id,name_ar,name_en,phone',
                'teacher:id,name',
            ])
            ->latest();

        if ($studentId !== null) {
            $query->where('student_id', $studentId);
        }

        $paginator = $query->paginate(min(max($perPage, 5), 50));

        return [
            'items' => collect($paginator->items())
                ->map(fn (ParentInquiry $inquiry) => $this->formatInquiry($inquiry))
                ->values()
                ->all(),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Submit and persist a new inquiry to the child's center and/or teacher.
     */
    public function createInquiry(User $parent, array $data): array
    {
        $child = null;
        if (! empty($data['student_id'])) {
            $child = $this->resolveLinkedChild($parent, (int) $data['student_id']);
        } else {
            $child = $parent->children()
                ->where('users.role', UserRole::STUDENT->value)
                ->orderBy('parent_student.created_at')
                ->first();
        }

        $centerId = null;
        $teacherId = null;

        if ($child) {
            $activeEnrollments = GroupEnrollment::query()
                ->where('student_id', $child->id)
                ->where('status', EnrollmentStatus::ENROLLED)
                ->with('group:id,center_id,teacher_id')
                ->get();

            $centerId = $activeEnrollments
                ->pluck('group.center_id')
                ->filter()
                ->first();

            $allowedTeacherIds = $activeEnrollments
                ->pluck('group.teacher_id')
                ->filter()
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if (! empty($data['teacher_id'])) {
                $requestedTeacherId = (int) $data['teacher_id'];
                if (in_array($requestedTeacherId, $allowedTeacherIds, true)) {
                    $teacherId = $requestedTeacherId;
                }
            } elseif (in_array($data['recipient_type'], ['teacher', 'both'], true) && ! empty($allowedTeacherIds)) {
                $teacherId = $allowedTeacherIds[0];
            }
        }

        /** @var ParentInquiry $inquiry */
        $inquiry = $this->transaction(function () use ($parent, $child, $centerId, $teacherId, $data) {
            return ParentInquiry::create([
                'parent_id' => $parent->id,
                'student_id' => $child?->id,
                'center_id' => $centerId,
                'teacher_id' => $teacherId,
                'recipient_type' => $data['recipient_type'],
                'subject' => $data['subject'],
                'message' => $data['message'],
                'status' => 'open',
            ]);
        });

        $inquiry->load([
            'student:id,name,student_code',
            'center:id,name',
            'center.centerProfile:id,user_id,name_ar,name_en,phone',
            'teacher:id,name',
        ]);

        $this->logSecurityEvent(
            'parent.inquiry_created',
            [
                'parent_id' => $parent->id,
                'student_id' => $child?->id,
                'inquiry_uuid' => $inquiry->uuid,
                'recipient_type' => $inquiry->recipient_type,
            ],
            'info',
            $inquiry
        );

        return $this->formatInquiry($inquiry);
    }

    /**
     * Fetch parent profile & notification settings.
     */
    public function getSettings(User $parent): array
    {
        $profile = $this->ensureParentProfile($parent);

        return $this->formatParentSettings($parent, $profile);
    }

    /**
     * Persist parent profile, contact numbers, and notification preferences.
     */
    public function updateSettings(User $parent, array $data): array
    {
        $profile = $this->ensureParentProfile($parent);

        $this->transaction(function () use ($parent, $profile, $data): void {
            $userUpdates = [];
            if (array_key_exists('name', $data)) {
                $userUpdates['name'] = $data['name'];
            }
            if (array_key_exists('phone', $data)) {
                $userUpdates['phone'] = $data['phone'] !== '' ? $data['phone'] : null;
            }
            if (array_key_exists('email', $data)) {
                $userUpdates['email'] = $data['email'];
            }
            if ($userUpdates !== []) {
                $parent->fill($userUpdates)->save();
            }

            $profileUpdates = [];
            foreach ([
                'emergency_phone',
                'notify_tuition_renewal',
                'notify_achievements_certificates',
                'preferred_lang',
                'preferred_theme',
            ] as $field) {
                if (array_key_exists($field, $data)) {
                    $profileUpdates[$field] = $data[$field];
                }
            }
            if ($profileUpdates !== []) {
                $profile->fill($profileUpdates)->save();
            }
        });

        $parent->refresh();
        $profile->refresh();

        $this->logSecurityEvent(
            'parent.settings_updated',
            [
                'parent_id' => $parent->id,
                'updated_fields' => array_keys($data),
            ],
            'info',
            $parent
        );

        return $this->formatParentSettings($parent, $profile);
    }

    /* ===================================================================== */
    /* Internal Aggregation Builders (Zero-N+1, Real DB Data Only) */
    /* ===================================================================== */

    protected function ensureParentProfile(User $parent): ParentProfile
    {
        return ParentProfile::firstOrCreate(
            ['user_id' => $parent->id],
            [
                'notify_tuition_renewal' => true,
                'notify_achievements_certificates' => true,
                'preferred_lang' => 'ar',
                'preferred_theme' => 'system',
            ]
        );
    }

    /**
     * Batch-prefetch enrollments, attendance, exam metrics, lesson progress,
     * risk signals, and absence alerts for a set of children to avoid N+1 queries.
     *
     * @param  array<int>  $childIds
     */
    protected function prefetchChildrenMetrics(array $childIds): array
    {
        if ($childIds === []) {
            return [
                'enrollments' => collect(),
                'attendanceRecords' => collect(),
                'examSubmissions' => collect(),
                'lessonProgress' => collect(),
                'riskSignals' => collect(),
                'absenceAlerts' => collect(),
                'homeworkSubmissions' => collect(),
            ];
        }

        $enrollments = GroupEnrollment::query()
            ->whereIn('student_id', $childIds)
            ->where('status', EnrollmentStatus::ENROLLED)
            ->with([
                'group.teacher:id,name,avatar',
                'group.teacher.teacherProfile:id,user_id,name_ar,name_en,title_ar,subject_ar,subject_en',
                'group.center:id,name,phone',
                'group.center.centerProfile:id,user_id,name_ar,name_en,phone',
                'group.centerRoom:id,name_ar,name_en,floor_label',
                'group.scheduleSlots',
                'group.course:id,title_ar,title_en,subject_ar,subject_en,lessons_count',
            ])
            ->get()
            ->groupBy('student_id');

        $attendanceRecords = GroupAttendanceRecord::query()
            ->whereIn('student_id', $childIds)
            ->with([
                'session:id,study_group_id,session_date,title_ar',
                'group:id,name_ar,name_en,subject_ar,subject_en,hall_name,teacher_id,teacher_name_override,center_room_id',
                'group.teacher:id,name',
                'group.centerRoom:id,name_ar,name_en,floor_label',
            ])
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id');

        $examSubmissions = ExamSubmission::query()
            ->whereIn('student_id', $childIds)
            ->where('status', '!=', ExamSubmissionStatus::IN_PROGRESS->value)
            ->with([
                'exam:id,teacher_id,course_id,title_ar,title_en,course_title_ar,full_mark',
                'exam.teacher:id,name',
                'exam.course:id,subject_ar,subject_en',
            ])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_id');

        $lessonProgress = StudentLessonProgress::query()
            ->whereIn('student_id', $childIds)
            ->select(['student_id', 'course_id', 'lesson_id', 'is_completed', 'progress_percent', 'updated_at'])
            ->get()
            ->groupBy('student_id');

        $riskSignals = StudentRiskSignal::query()
            ->whereIn('student_id', $childIds)
            ->with('teacher:id,name')
            ->latest()
            ->get()
            ->groupBy('student_id');

        $absenceAlerts = CenterAbsenceAlert::query()
            ->whereIn('student_id', $childIds)
            ->with('studyGroup:id,name_ar,subject_ar')
            ->latest('sent_at')
            ->limit(30)
            ->get()
            ->groupBy('student_id');

        $homeworkSubmissions = HomeworkSubmission::query()
            ->whereIn('student_id', $childIds)
            ->with('homework:id,title_ar,teacher_id', 'homework.teacher:id,name')
            ->latest('graded_at')
            ->get()
            ->groupBy('student_id');

        return [
            'enrollments' => $enrollments,
            'attendanceRecords' => $attendanceRecords,
            'examSubmissions' => $examSubmissions,
            'lessonProgress' => $lessonProgress,
            'riskSignals' => $riskSignals,
            'absenceAlerts' => $absenceAlerts,
            'homeworkSubmissions' => $homeworkSubmissions,
        ];
    }

    protected function buildChildSummary(User $child, array $prefetched): array
    {
        $profile = $child->studentProfile;
        /** @var Collection<int, GroupEnrollment> $enrollments */
        $enrollments = $prefetched['enrollments']->get($child->id, collect());
        /** @var Collection<int, GroupAttendanceRecord> $attendanceRecords */
        $attendanceRecords = $prefetched['attendanceRecords']->get($child->id, collect());
        /** @var Collection<int, ExamSubmission> $examSubmissions */
        $examSubmissions = $prefetched['examSubmissions']->get($child->id, collect());
        /** @var Collection<int, StudentLessonProgress> $lessonProgress */
        $lessonProgress = $prefetched['lessonProgress']->get($child->id, collect());
        /** @var Collection<int, StudentRiskSignal> $riskSignals */
        $riskSignals = $prefetched['riskSignals']->get($child->id, collect());
        /** @var Collection<int, CenterAbsenceAlert> $absenceAlerts */
        $absenceAlerts = $prefetched['absenceAlerts']->get($child->id, collect());
        /** @var Collection<int, HomeworkSubmission> $homeworkSubmissions */
        $homeworkSubmissions = $prefetched['homeworkSubmissions']->get($child->id, collect());

        // Attendance stats: prefer real GroupAttendanceRecord rows; fall back to GroupEnrollment aggregates
        $totalAttendanceRecords = $attendanceRecords->count();
        if ($totalAttendanceRecords > 0) {
            $presentCount = $attendanceRecords->filter(function (GroupAttendanceRecord $record) {
                $val = $record->status instanceof AttendanceStatus ? $record->status->value : (string) $record->status;

                return in_array($val, [AttendanceStatus::PRESENT->value, AttendanceStatus::LATE->value], true);
            })->count();
            $attendanceRate = (int) round(($presentCount / $totalAttendanceRecords) * 100);
        } else {
            $totalAttendanceRecords = (int) $enrollments->sum('total_sessions');
            $presentCount = (int) $enrollments->sum('attended_sessions');
            $attendanceRate = $totalAttendanceRecords > 0
                ? (int) round(($presentCount / $totalAttendanceRecords) * 100)
                : ($enrollments->isNotEmpty() ? (int) round((float) $enrollments->avg('attendance_rate')) : 0);
        }

        // Exam GPA stats
        $gradedExams = $examSubmissions->filter(function (ExamSubmission $sub) {
            $val = $sub->status instanceof ExamSubmissionStatus ? $sub->status->value : (string) $sub->status;

            return $val === ExamSubmissionStatus::GRADED->value && $sub->percentage !== null;
        });

        $gradedCount = $gradedExams->count();
        $avgQuizScore = $gradedCount > 0
            ? (int) round((float) $gradedExams->avg('percentage'))
            : ($enrollments->isNotEmpty() ? (int) round((float) $enrollments->avg('avg_quiz_score')) : 0);

        [$gradeStandingAr, $gradeStandingEn] = $this->resolveGradeStanding($avgQuizScore, $gradedCount > 0);

        // Lesson completion counts
        $completedLessons = $lessonProgress->where('is_completed', true)->count();
        $entitlements = $this->studentAccessService->getEntitlements($child);
        $totalLessons = ! empty($entitlements['course_ids'])
            ? Lesson::query()
                ->whereIn('course_id', $entitlements['course_ids'])
                ->where('status', LessonStatus::PUBLISHED)
                ->count()
            : 0;

        // Grade, School & Center resolution
        $gradeAr = $profile?->grade_ar
            ?: $enrollments->pluck('group.grade_ar')->filter()->first()
            ?: 'غير محدد بعد';
        $gradeEn = $enrollments->pluck('group.grade_en')->filter()->first() ?: $gradeAr;
        $trackAr = $profile?->track_ar;
        $gradeDisplayAr = $trackAr ? "{$gradeAr} - {$trackAr}" : $gradeAr;
        $gradeShortAr = $this->shortenGradeAr($gradeAr);

        $firstCenterGroup = $enrollments->first(fn (GroupEnrollment $e) => $e->group?->center_id !== null)?->group;
        /** @var CenterProfile|null $centerProfile */
        $centerProfile = $firstCenterGroup?->center?->centerProfile;
        $centerAr = $centerProfile?->name_ar
            ?: $firstCenterGroup?->center_name
            ?: $profile?->center_name
            ?: null;
        $centerEn = $centerProfile?->name_en ?: $centerAr;
        $centerPhone = $centerProfile?->phone ?: $firstCenterGroup?->center?->phone;

        $schoolAr = $profile?->school_name ?: null;

        // Next upcoming session computed from active group schedule slots
        [$nextSessionAr, $nextSessionEn, $nextSessionConfirmed] = $this->resolveNextSession($enrollments);

        // Latest teacher note (priority: latest graded exam feedback -> latest graded hw feedback -> active risk signal)
        [$teacherNotesAr, $teacherNotesEn, $teacherNotesAuthor] = $this->resolveLatestTeacherNote(
            $gradedExams,
            $homeworkSubmissions,
            $riskSignals
        );

        // Latest gate/attendance record
        /** @var GroupAttendanceRecord|null $latestRecord */
        $latestRecord = $attendanceRecords->first();
        $latestAttendance = $latestRecord ? $this->formatAttendanceRecord($latestRecord) : null;

        // Contactable teachers list for the "Contact Center / Teacher" modal
        $teachersContacts = $enrollments
            ->map(function (GroupEnrollment $enrollment) {
                $group = $enrollment->group;
                $teacher = $group?->teacher;
                if (! $teacher) {
                    return null;
                }

                return [
                    'id' => (int) $teacher->id,
                    'name' => $group->teacher_name_override ?: ($teacher->teacherProfile?->name_ar ?: $teacher->name),
                    'subjectAr' => $group->subject_ar ?: ($teacher->teacherProfile?->subject_ar ?: $group->name_ar),
                ];
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();

        $streakDays = max(
            (int) ($profile?->current_streak_days ?? 0),
            (int) ($enrollments->max('streak_days') ?? 0)
        );

        return [
            'id' => (int) $child->id,
            'studentCode' => $child->student_code ?: ($enrollments->pluck('student_barcode')->filter()->first() ?: 'STD-'.$child->id),
            'name' => $child->name,
            'nameAr' => $child->name,
            'email' => $child->email,
            'phone' => $child->phone,
            'avatar' => $child->avatar ?: null,
            'grade' => $gradeEn,
            'gradeAr' => $gradeDisplayAr,
            'gradeShortAr' => $gradeShortAr,
            'gradeShortEn' => $gradeEn,
            'trackAr' => $trackAr,
            'school' => $schoolAr,
            'schoolAr' => $schoolAr,
            'center' => $centerEn,
            'centerAr' => $centerAr,
            'centerPhone' => $centerPhone,
            'attendanceRate' => $attendanceRate,
            'presentSessionsCount' => $presentCount,
            'totalSessionsCount' => $totalAttendanceRecords,
            'avgQuizScore' => $avgQuizScore,
            'gradedExamsCount' => $gradedCount,
            'gradeStandingAr' => $gradeStandingAr,
            'gradeStandingEn' => $gradeStandingEn,
            'streakDays' => $streakDays,
            'completedLessons' => $completedLessons,
            'totalLessons' => $totalLessons,
            'coursesCount' => count($entitlements['course_ids']),
            'activeGroupsCount' => $enrollments->count(),
            'nextSessionAr' => $nextSessionAr,
            'nextSession' => $nextSessionEn,
            'nextSessionConfirmed' => $nextSessionConfirmed,
            'teacherNotesAr' => $teacherNotesAr,
            'teacherNotes' => $teacherNotesEn,
            'teacherNotesAuthor' => $teacherNotesAuthor,
            'latestAttendance' => $latestAttendance,
            'recentAlerts' => $absenceAlerts->take(5)->map(fn (CenterAbsenceAlert $a) => [
                'id' => (int) $a->id,
                'date' => $a->session_date?->format('Y-m-d'),
                'groupNameAr' => $a->studyGroup?->name_ar ?: $a->studyGroup?->subject_ar,
                'messageAr' => $a->message_ar,
                'channel' => $a->channel,
                'sentAt' => $a->sent_at?->toIso8601String(),
            ])->values()->all(),
            'contacts' => [
                'centerNameAr' => $centerAr,
                'centerPhone' => $centerPhone,
                'teachers' => $teachersContacts,
            ],
        ];
    }

    protected function buildChildCoursesPayload(User $child): array
    {
        $entitlements = $this->studentAccessService->getEntitlements($child);

        $enrollments = GroupEnrollment::query()
            ->where('student_id', $child->id)
            ->where('status', EnrollmentStatus::ENROLLED)
            ->with([
                'group.teacher:id,name,avatar',
                'group.teacher.teacherProfile:id,user_id,name_ar,name_en,title_ar,subject_ar,subject_en',
                'group.centerRoom:id,name_ar,name_en,floor_label',
                'group.course:id,title_ar,title_en,subject_ar,subject_en',
            ])
            ->get();

        $enrollmentsByCourse = $enrollments
            ->filter(fn (GroupEnrollment $e) => $e->group?->course_id !== null)
            ->keyBy(fn (GroupEnrollment $e) => (int) $e->group->course_id);

        $courses = ! empty($entitlements['course_ids'])
            ? Course::query()
                ->whereIn('id', $entitlements['course_ids'])
                ->where('status', CourseStatus::PUBLISHED)
                ->with([
                    'teacher:id,name,avatar',
                    'teacher.teacherProfile:id,user_id,name_ar,name_en,title_ar,subject_ar,subject_en',
                ])
                ->get()
            : collect();

        $courseIds = $courses->pluck('id')->all();

        // Batch lesson counts & student progress per course
        $lessonCountsByCourse = ! empty($courseIds)
            ? Lesson::query()
                ->whereIn('course_id', $courseIds)
                ->where('status', LessonStatus::PUBLISHED)
                ->selectRaw('course_id, COUNT(*) as total')
                ->groupBy('course_id')
                ->pluck('total', 'course_id')
            : collect();

        $progressRows = ! empty($courseIds)
            ? StudentLessonProgress::query()
                ->where('student_id', $child->id)
                ->whereIn('course_id', $courseIds)
                ->with('lesson:id,title_ar,title_en')
                ->orderByDesc('updated_at')
                ->get()
                ->groupBy('course_id')
            : collect();

        // Batch exam averages per course
        $examAvgByCourse = ! empty($courseIds)
            ? ExamSubmission::query()
                ->join('exams', 'exams.id', '=', 'exam_submissions.exam_id')
                ->where('exam_submissions.student_id', $child->id)
                ->where('exam_submissions.status', ExamSubmissionStatus::GRADED->value)
                ->whereIn('exams.course_id', $courseIds)
                ->selectRaw('exams.course_id as course_id, AVG(exam_submissions.percentage) as avg_pct')
                ->groupBy('exams.course_id')
                ->pluck('avg_pct', 'course_id')
            : collect();

        $items = [];

        foreach ($courses as $course) {
            /** @var GroupEnrollment|null $enrollment */
            $enrollment = $enrollmentsByCourse->get($course->id);
            $group = $enrollment?->group;
            $courseProgressRows = $progressRows->get($course->id, collect());
            $totalLessons = max((int) ($lessonCountsByCourse->get($course->id) ?? 0), (int) $course->lessons_count);
            $completedLessons = $courseProgressRows->where('is_completed', true)->count();
            $progressPct = $totalLessons > 0
                ? min(100, (int) round(($completedLessons / $totalLessons) * 100))
                : 0;

            /** @var StudentLessonProgress|null $lastProg */
            $lastProg = $courseProgressRows->first();
            $lastLessonAr = $lastProg?->lesson?->title_ar ?: 'لم يبدأ الطالب الدراسة في هذا المقرر بعد';
            $lastLessonEn = $lastProg?->lesson?->title_en ?: ($lastProg?->lesson?->title_ar ?: 'Not started yet');

            $avgScoreVal = $examAvgByCourse->has($course->id)
                ? (int) round((float) $examAvgByCourse->get($course->id))
                : ($enrollment && $enrollment->avg_quiz_score > 0 ? (int) $enrollment->avg_quiz_score : null);

            $attendanceVal = $enrollment ? (int) $enrollment->attendance_rate : null;
            $teacherProfile = $course->teacher?->teacherProfile;
            $roomLabel = $group?->centerRoom?->name_ar ?: ($group?->hall_name ?: 'منصة أونلاين');

            $items[] = [
                'id' => 'course-'.$course->id,
                'source' => 'course',
                'nameAr' => $course->title_ar ?: ($course->subject_ar ?: 'مقرر دراسي'),
                'name' => $course->title_en ?: ($course->title_ar ?: 'Course'),
                'subjectAr' => $course->subject_ar ?: $course->title_ar,
                'teacherNameAr' => $teacherProfile?->name_ar ?: ($course->teacher?->name ?: 'المعلم'),
                'teacherName' => $teacherProfile?->name_en ?: ($course->teacher?->name ?: 'Teacher'),
                'teacherTitleAr' => $teacherProfile?->title_ar ?: ($course->subject_ar ? "معلم {$course->subject_ar}" : ''),
                'teacherAvatar' => $course->teacher?->avatar ?: null,
                'progress' => $progressPct,
                'completedLessons' => $completedLessons,
                'totalLessons' => $totalLessons,
                'lastLessonAr' => $lastLessonAr,
                'lastLesson' => $lastLessonEn,
                'avgScore' => $avgScoreVal !== null ? "{$avgScoreVal}%" : '—',
                'avgScoreValue' => $avgScoreVal,
                'attendance' => $attendanceVal !== null ? "{$attendanceVal}%" : '—',
                'attendanceValue' => $attendanceVal,
                'room' => $roomLabel,
                'scheduleAr' => $group?->schedule_ar,
            ];
        }

        // Also include standalone center/teacher StudyGroups that don't have a linked published Course
        $coveredCourseIds = array_map('intval', $courseIds);
        foreach ($enrollments as $enrollment) {
            $group = $enrollment->group;
            if (! $group) {
                continue;
            }
            if ($group->course_id !== null && in_array((int) $group->course_id, $coveredCourseIds, true)) {
                continue;
            }

            $teacherProfile = $group->teacher?->teacherProfile;
            $totalSessions = (int) $enrollment->total_sessions;
            $attendedSessions = (int) $enrollment->attended_sessions;
            $progressPct = $totalSessions > 0 ? min(100, (int) round(($attendedSessions / $totalSessions) * 100)) : 0;

            $items[] = [
                'id' => 'group-'.$group->id,
                'source' => 'group',
                'nameAr' => $group->subject_ar ?: $group->name_ar,
                'name' => $group->subject_en ?: ($group->name_en ?: $group->name_ar),
                'subjectAr' => $group->subject_ar ?: $group->name_ar,
                'teacherNameAr' => $group->teacher_name_override ?: ($teacherProfile?->name_ar ?: ($group->teacher?->name ?: 'المعلم')),
                'teacherName' => $group->teacher_name_override ?: ($teacherProfile?->name_en ?: ($group->teacher?->name ?: 'Teacher')),
                'teacherTitleAr' => $teacherProfile?->title_ar ?: ($group->name_ar ?: ''),
                'teacherAvatar' => $group->teacher?->avatar ?: null,
                'progress' => $progressPct,
                'completedLessons' => $attendedSessions,
                'totalLessons' => $totalSessions,
                'lastLessonAr' => $group->schedule_ar ?: 'مجموعة دراسية نشطة بالسنتر',
                'lastLesson' => $group->schedule_en ?: ($group->schedule_ar ?: 'Active Center Study Group'),
                'avgScore' => $enrollment->avg_quiz_score > 0 ? "{$enrollment->avg_quiz_score}%" : '—',
                'avgScoreValue' => $enrollment->avg_quiz_score > 0 ? (int) $enrollment->avg_quiz_score : null,
                'attendance' => "{$enrollment->attendance_rate}%",
                'attendanceValue' => (int) $enrollment->attendance_rate,
                'room' => $group->centerRoom?->name_ar ?: ($group->hall_name ?: ($group->center_name ?: 'السنتر')),
                'scheduleAr' => $group->schedule_ar,
            ];
        }

        return [
            'summary' => [
                'totalCourses' => count($items),
                'avgProgress' => count($items) > 0
                    ? (int) round(collect($items)->avg('progress'))
                    : 0,
            ],
            'items' => $items,
        ];
    }

    protected function buildChildAttendancePayload(User $child, ?string $statusFilter = null, int $perPage = 25): array
    {
        $baseQuery = GroupAttendanceRecord::query()
            ->where('student_id', $child->id);

        $statusCounts = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $presentCount = (int) ($statusCounts->get(AttendanceStatus::PRESENT->value) ?? 0);
        $lateCount = (int) ($statusCounts->get(AttendanceStatus::LATE->value) ?? 0);
        $absentCount = (int) ($statusCounts->get(AttendanceStatus::ABSENT->value) ?? 0);
        $excusedCount = (int) ($statusCounts->get(AttendanceStatus::EXCUSED->value) ?? 0);
        $totalCount = $presentCount + $lateCount + $absentCount + $excusedCount;
        $rate = $totalCount > 0 ? (int) round((($presentCount + $lateCount) / $totalCount) * 100) : 0;

        $listQuery = (clone $baseQuery)
            ->with([
                'session:id,study_group_id,session_date,title_ar',
                'group:id,name_ar,name_en,subject_ar,subject_en,hall_name,teacher_id,teacher_name_override,center_room_id',
                'group.teacher:id,name',
                'group.centerRoom:id,name_ar,name_en,floor_label',
            ])
            ->orderByDesc('scanned_at')
            ->orderByDesc('id');

        if ($statusFilter && in_array($statusFilter, AttendanceStatus::values(), true)) {
            $listQuery->where('status', $statusFilter);
        }

        $paginator = $listQuery->paginate(min(max($perPage, 5), 100));

        return [
            'summary' => [
                'rate' => $rate,
                'presentCount' => $presentCount,
                'lateCount' => $lateCount,
                'absentCount' => $absentCount,
                'excusedCount' => $excusedCount,
                'totalCount' => $totalCount,
            ],
            'items' => collect($paginator->items())
                ->map(fn (GroupAttendanceRecord $record) => $this->formatAttendanceRecord($record))
                ->values()
                ->all(),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    protected function buildChildExamsPayload(User $child, int $perPage = 25): array
    {
        $baseQuery = ExamSubmission::query()
            ->where('student_id', $child->id)
            ->where('status', '!=', ExamSubmissionStatus::IN_PROGRESS->value);

        $gradedStats = (clone $baseQuery)
            ->where('status', ExamSubmissionStatus::GRADED->value)
            ->selectRaw('COUNT(*) as total_graded, AVG(percentage) as avg_pct, SUM(CASE WHEN is_passed = 1 THEN 1 ELSE 0 END) as passed_count, SUM(CASE WHEN is_full_mark = 1 THEN 1 ELSE 0 END) as full_mark_count')
            ->first();

        $paginator = (clone $baseQuery)
            ->with([
                'exam:id,teacher_id,course_id,title_ar,title_en,course_title_ar,full_mark',
                'exam.teacher:id,name',
                'exam.course:id,subject_ar,subject_en',
            ])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 5), 200));

        $submissions = collect($paginator->items());
        $examIds = $submissions->pluck('exam_id')->filter()->unique()->values()->all();

        // Batch cohort scores per exam to compute accurate ranks without N+1 queries
        $scoresByExam = ! empty($examIds)
            ? ExamSubmission::query()
                ->whereIn('exam_id', $examIds)
                ->where('status', ExamSubmissionStatus::GRADED->value)
                ->whereNotNull('score')
                ->select(['exam_id', 'student_id', 'score'])
                ->get()
                ->groupBy('exam_id')
            : collect();

        $items = $submissions->map(function (ExamSubmission $sub) use ($scoresByExam) {
            $exam = $sub->exam;
            $statusVal = $sub->status instanceof ExamSubmissionStatus ? $sub->status->value : (string) $sub->status;
            $isGraded = $statusVal === ExamSubmissionStatus::GRADED->value;
            $maxScore = (int) ($sub->full_mark ?: ($exam?->full_mark ?: 100));
            $scoreVal = $isGraded && $sub->score !== null ? round((float) $sub->score, 1) : null;
            $pctVal = $isGraded && $sub->percentage !== null
                ? (int) round((float) $sub->percentage)
                : ($scoreVal !== null && $maxScore > 0 ? (int) round(($scoreVal / $maxScore) * 100) : null);

            $rankAr = $isGraded ? 'تم الرصد' : 'بانتظار التصحيح';
            $rankEn = $isGraded ? 'Graded' : 'Pending grading';

            if ($isGraded && $scoreVal !== null && $scoresByExam->has($sub->exam_id)) {
                $cohort = $scoresByExam->get($sub->exam_id);
                $higherCount = $cohort->filter(fn ($row) => (float) $row->score > (float) $sub->score)->count();
                $rank = $higherCount + 1;
                $totalCohort = $cohort->count();
                $rankAr = "المركز {$rank} من {$totalCohort}";
                $rankEn = "Rank #{$rank} of {$totalCohort}";
            }

            $dateObj = $sub->submitted_at ?: $sub->created_at;
            $subjectAr = $exam?->course?->subject_ar ?: ($exam?->course_title_ar ?: 'اختبار عام');
            $subjectEn = $exam?->course?->subject_en ?: $subjectAr;

            return [
                'id' => $sub->uuid,
                'titleAr' => $exam?->title_ar ?: 'اختبار دوري',
                'titleEn' => $exam?->title_en ?: ($exam?->title_ar ?: 'Periodic Exam'),
                'subjectAr' => $subjectAr,
                'subjectEn' => $subjectEn,
                'teacherAr' => $exam?->teacher?->name ?: 'المعلم',
                'date' => $dateObj?->format('Y-m-d'),
                'score' => $scoreVal,
                'maxScore' => $maxScore,
                'percent' => $pctVal !== null ? "{$pctVal}%" : '—',
                'percentValue' => $pctVal,
                'rankAr' => $rankAr,
                'rankEn' => $rankEn,
                'status' => $statusVal,
                'statusAr' => $sub->status instanceof ExamSubmissionStatus ? $sub->status->labelAr() : $statusVal,
                'isPassed' => $sub->is_passed,
                'isFullMark' => (bool) $sub->is_full_mark,
                'notesAr' => $sub->teacher_feedback
                    ?: ($isGraded
                        ? ($sub->is_full_mark
                            ? 'أداء استثنائي وحصول على الدرجة النهائية.'
                            : ($sub->is_passed ? 'أداء جيد ومستوى مستقر في الاختبار.' : 'يحتاج لمراجعة الأجزاء غير المكتملة مع المعلم.'))
                        : 'تم التسليم وبانتظار مراجعة ورصد الدرجة من المعلم.'),
            ];
        })->values()->all();

        return [
            'summary' => [
                'avgPercent' => $gradedStats && $gradedStats->avg_pct !== null ? (int) round((float) $gradedStats->avg_pct) : 0,
                'totalGraded' => (int) ($gradedStats->total_graded ?? 0),
                'passedCount' => (int) ($gradedStats->passed_count ?? 0),
                'fullMarkCount' => (int) ($gradedStats->full_mark_count ?? 0),
            ],
            'items' => $items,
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    protected function buildChildHomeworkPayload(User $child, ?string $statusFilter = null): array
    {
        $entitlements = $this->studentAccessService->getEntitlements($child);

        $homeworks = (! empty($entitlements['teacher_ids']) || ! empty($entitlements['course_ids']))
            ? Homework::query()
                ->where(function ($q) use ($entitlements): void {
                    if (! empty($entitlements['teacher_ids'])) {
                        $q->whereIn('teacher_id', $entitlements['teacher_ids']);
                    }
                    if (! empty($entitlements['course_ids'])) {
                        $q->orWhereIn('course_id', $entitlements['course_ids']);
                    }
                })
                ->with(['teacher:id,name', 'course:id,subject_ar,subject_en'])
                ->latest()
                ->limit(100)
                ->get()
            : collect();

        $submissionsByHw = $homeworks->isNotEmpty()
            ? HomeworkSubmission::query()
                ->where('student_id', $child->id)
                ->whereIn('homework_id', $homeworks->pluck('id'))
                ->get()
                ->keyBy('homework_id')
            : collect();

        $counts = ['total' => 0, 'graded' => 0, 'submitted' => 0, 'pending' => 0, 'rejected' => 0, 'overdue' => 0];
        $allItems = [];

        foreach ($homeworks as $hw) {
            /** @var HomeworkSubmission|null $sub */
            $sub = $submissionsByHw->get($hw->id);
            $rawSubStatus = $sub?->status instanceof HomeworkSubmissionStatus
                ? $sub->status->value
                : ($sub?->status ?? null);

            if ($rawSubStatus === HomeworkSubmissionStatus::GRADED->value) {
                $status = 'graded';
                $statusAr = 'تم التصحيح والتقييم';
                $statusEn = 'Graded';
            } elseif ($rawSubStatus === HomeworkSubmissionStatus::PENDING->value) {
                $status = 'submitted';
                $statusAr = 'تم التسليم وبانتظار التصحيح';
                $statusEn = 'Submitted (Pending Review)';
            } elseif ($rawSubStatus === HomeworkSubmissionStatus::REJECTED->value) {
                $status = 'rejected';
                $statusAr = 'مطلوب إعادة التسليم';
                $statusEn = 'Resubmission Needed';
            } else {
                $status = 'pending';
                $statusAr = 'لم يُسلَّم بعد';
                $statusEn = 'Not Submitted Yet';
            }

            $isOverdue = false;
            if ($hw->due_date && in_array($status, ['pending', 'rejected'], true)) {
                $dueTime = preg_match('/^\d{2}:\d{2}/', (string) $hw->due_time)
                    ? substr((string) $hw->due_time, 0, 5).':59'
                    : '23:59:59';
                try {
                    $isOverdue = Carbon::parse($hw->due_date->format('Y-m-d').' '.$dueTime)->isPast();
                } catch (\Throwable) {
                    $isOverdue = $hw->due_date->copy()->endOfDay()->isPast();
                }
            }

            $counts['total']++;
            $counts[$status]++;
            if ($isOverdue) {
                $counts['overdue']++;
            }

            $deadlineStr = $hw->due_date?->format('Y-m-d');
            $deadlineAr = $status === 'graded'
                ? 'تم التسليم والتصحيح'
                : ($deadlineStr ? ($hw->due_time ? "{$deadlineStr} ({$hw->due_time})" : $deadlineStr) : 'مفتوح');

            $earnedScore = $status === 'graded' && $sub?->score !== null ? round((float) $sub->score, 1) : null;
            $notesAr = $sub?->feedback_ar ?: match ($status) {
                'graded' => 'تم تصحيح الواجب ورصد الدرجة بنجاح.',
                'submitted' => 'تم تسليم الواجب من قِبل الطالب وجارٍ مراجعته من المعلم.',
                'rejected' => 'طلب المعلم تعديل الإجابة وإعادة رفع الواجب.',
                default => $isOverdue
                    ? 'تجاوز هذا الواجب موعد التسليم المحدد — يرجى متابعة الطالب لإنجازه.'
                    : 'واجب مسند للطالب وبانتظار التسليم قبل الموعد المحدد.',
            };

            $allItems[] = [
                'id' => $hw->uuid,
                'titleAr' => $hw->title_ar,
                'subjectAr' => $hw->course?->subject_ar ?: ($hw->course_title_ar ?: 'مادة دراسية'),
                'teacherAr' => $hw->teacher?->name ?: 'المعلم',
                'deadline' => $deadlineStr,
                'deadlineAr' => $deadlineAr,
                'isOverdue' => $isOverdue,
                'status' => $status,
                'statusAr' => $statusAr,
                'statusEn' => $statusEn,
                'maxScore' => (int) ($sub?->max_score ?: ($hw->max_grade ?: 20)),
                'earnedScore' => $earnedScore,
                'notesAr' => $notesAr,
            ];
        }

        $filteredItems = ($statusFilter && in_array($statusFilter, ['graded', 'submitted', 'pending', 'rejected'], true))
            ? array_values(array_filter($allItems, fn ($item) => $item['status'] === $statusFilter))
            : $allItems;

        return [
            'summary' => $counts,
            'items' => $filteredItems,
        ];
    }

    protected function buildChildFinancialsPayload(User $child): array
    {
        $activeTier = $this->studentBillingService->resolveActivePlanTier($child);
        $activeSub = StudentSubscription::query()
            ->where('student_id', $child->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        $platformInvoices = StudentInvoice::query()
            ->where('student_id', $child->id)
            ->latest('paid_at')
            ->latest('id')
            ->limit(50)
            ->get();

        $directPayments = StudentDirectPayment::query()
            ->where('student_id', $child->id)
            ->with(['course:id,title_ar,title_en', 'studyGroup:id,name_ar,name_en', 'teacher:id,name'])
            ->latest('paid_at')
            ->latest('id')
            ->limit(50)
            ->get();

        $enrollmentsWithDues = GroupEnrollment::query()
            ->where('student_id', $child->id)
            ->where('status', EnrollmentStatus::ENROLLED)
            ->where(function ($q): void {
                $q->where('due_amount_egp', '>', 0)
                    ->orWhereIn('payment_status', ['overdue', 'pending']);
            })
            ->with(['group:id,name_ar,subject_ar,center_name'])
            ->get();

        $ledger = collect();
        $totalPaid = 0.0;
        $pendingAmount = 0.0;
        $dueAmount = 0.0;

        foreach ($platformInvoices as $inv) {
            $statusVal = $inv->status instanceof InvoiceStatus ? $inv->status->value : (string) $inv->status;
            $amount = round((float) $inv->final_amount_egp, 2);

            if ($statusVal === InvoiceStatus::PAID->value) {
                $totalPaid += $amount;
            } elseif ($statusVal === InvoiceStatus::PENDING->value) {
                $pendingAmount += $amount;
            }

            $dateObj = $inv->paid_at ?: $inv->created_at;
            $methodLabel = $inv->payment_method instanceof PaymentMethod ? $inv->payment_method->label() : (string) $inv->payment_method;

            $ledger->push([
                'id' => 'inv-'.$inv->uuid,
                'source' => 'platform_invoice',
                'receiptNo' => '#'.$inv->invoice_number,
                'date' => $dateObj?->format('Y-m-d') ?? '',
                'sortTs' => $dateObj?->timestamp ?? 0,
                'descriptionAr' => $inv->description_ar ?: 'اشتراك باقة المنصة',
                'descriptionEn' => $inv->description_en ?: ($inv->description_ar ?: 'Platform Subscription'),
                'methodAr' => $methodLabel,
                'methodEn' => $inv->payment_method instanceof PaymentMethod ? strtoupper($inv->payment_method->value) : $methodLabel,
                'amountEgp' => $amount,
                'amountFormatted' => number_format($amount, 0).' ج.م',
                'status' => $statusVal,
                'statusAr' => match ($statusVal) {
                    InvoiceStatus::PAID->value => 'مسدد بالكامل',
                    InvoiceStatus::PENDING->value => 'قيد المراجعة',
                    default => 'غير مكتمل',
                },
                'statusEn' => match ($statusVal) {
                    InvoiceStatus::PAID->value => 'Paid',
                    InvoiceStatus::PENDING->value => 'Pending',
                    default => 'Failed',
                },
            ]);
        }

        foreach ($directPayments as $pay) {
            $statusVal = $pay->status instanceof DirectPaymentStatus ? $pay->status->value : (string) $pay->status;
            $amount = round((float) $pay->amount_egp, 2);

            if ($statusVal === DirectPaymentStatus::CONFIRMED->value) {
                $totalPaid += $amount;
            } elseif ($statusVal === DirectPaymentStatus::PENDING->value) {
                $pendingAmount += $amount;
            }

            $dateObj = $pay->paid_at ?: $pay->created_at;
            $methodLabel = $pay->payment_method instanceof PaymentMethod ? $pay->payment_method->label() : (string) $pay->payment_method;
            $targetNameAr = $pay->course_name_ar
                ?: $pay->course?->title_ar
                ?: $pay->studyGroup?->name_ar
                ?: 'اشتراك مقرر دراسي';
            $teacherSuffix = $pay->teacher?->name ? " ({$pay->teacher->name})" : '';

            $ledger->push([
                'id' => 'pay-'.$pay->uuid,
                'source' => 'direct_payment',
                'receiptNo' => '#'.$pay->reference_num,
                'date' => $dateObj?->format('Y-m-d') ?? '',
                'sortTs' => $dateObj?->timestamp ?? 0,
                'descriptionAr' => $targetNameAr.$teacherSuffix,
                'descriptionEn' => ($pay->course_name_en ?: $pay->course?->title_en ?: $targetNameAr).$teacherSuffix,
                'methodAr' => $methodLabel,
                'methodEn' => $pay->payment_method instanceof PaymentMethod ? strtoupper($pay->payment_method->value) : $methodLabel,
                'amountEgp' => $amount,
                'amountFormatted' => number_format($amount, 0).' ج.م',
                'status' => $statusVal === DirectPaymentStatus::CONFIRMED->value ? 'paid' : $statusVal,
                'statusAr' => match ($statusVal) {
                    DirectPaymentStatus::CONFIRMED->value => 'مسدد ومؤكد',
                    DirectPaymentStatus::PENDING->value => 'قيد التأكيد',
                    default => 'مرفوض',
                },
                'statusEn' => match ($statusVal) {
                    DirectPaymentStatus::CONFIRMED->value => 'Confirmed',
                    DirectPaymentStatus::PENDING->value => 'Pending',
                    default => 'Rejected',
                },
            ]);
        }

        foreach ($enrollmentsWithDues as $enr) {
            $amount = round((float) $enr->due_amount_egp, 2);
            if ($amount <= 0) {
                continue;
            }
            $dueAmount += $amount;
            $dateObj = $enr->payment_due_since ?: $enr->updated_at;
            $groupTitle = $enr->group?->name_ar ?: ($enr->group?->subject_ar ?: 'مجموعة دراسية');

            $ledger->push([
                'id' => 'due-'.$enr->uuid,
                'source' => 'group_due',
                'receiptNo' => 'DUE-'.$enr->id,
                'date' => $dateObj?->format('Y-m-d') ?? '',
                'sortTs' => $dateObj?->timestamp ?? 0,
                'descriptionAr' => "مستحق اشتراك المجموعة — {$groupTitle}",
                'descriptionEn' => "Outstanding group tuition — {$groupTitle}",
                'methodAr' => 'استقبال السنتر / إلكتروني',
                'methodEn' => 'Center Reception / Online',
                'amountEgp' => $amount,
                'amountFormatted' => number_format($amount, 0).' ج.م',
                'status' => 'overdue',
                'statusAr' => 'مستحق السداد',
                'statusEn' => 'Due',
            ]);
        }

        $sortedLedger = $ledger
            ->sortByDesc('sortTs')
            ->map(function (array $row) {
                unset($row['sortTs']);

                return $row;
            })
            ->values()
            ->all();

        return [
            'summary' => [
                'totalPaidEgp' => round($totalPaid, 2),
                'pendingAmountEgp' => round($pendingAmount, 2),
                'dueAmountEgp' => round($dueAmount, 2),
                'activePlanTier' => $activeTier->value,
                'activePlanAr' => $activeTier instanceof StudentPlanTier ? $activeTier->labelAr() : 'الباقة المجانية',
                'activePlanEn' => $activeTier instanceof StudentPlanTier ? $activeTier->labelEn() : 'Free Plan',
                'renewsAt' => $activeSub?->renews_at?->format('Y-m-d'),
            ],
            'invoices' => $sortedLedger,
        ];
    }

    protected function formatAttendanceRecord(GroupAttendanceRecord $record): array
    {
        $sessionDate = $record->session?->session_date ?: $record->scanned_at ?: $record->created_at;
        $group = $record->group;
        $teacherName = $group?->teacher_name_override ?: $group?->teacher?->name;
        $subjectBaseAr = $group?->subject_ar ?: ($group?->name_ar ?: 'حصة دراسية');
        $subjectBaseEn = $group?->subject_en ?: ($group?->name_en ?: $subjectBaseAr);

        $roomLabel = $group?->centerRoom?->name_ar
            ? trim($group->centerRoom->name_ar.($group->centerRoom->floor_label ? " - {$group->centerRoom->floor_label}" : ''))
            : ($group?->hall_name ?: 'قاعة السنتر');

        $statusVal = $record->status instanceof AttendanceStatus ? $record->status->value : (string) $record->status;
        $statusAr = match ($statusVal) {
            AttendanceStatus::PRESENT->value => 'حاضر في الموعد',
            AttendanceStatus::LATE->value => 'متأخر عن الموعد',
            AttendanceStatus::ABSENT->value => 'غائب عن الحصة',
            AttendanceStatus::EXCUSED->value => 'غياب بعذر مقبول',
            default => $statusVal,
        };
        $statusEn = match ($statusVal) {
            AttendanceStatus::PRESENT->value => 'Present on time',
            AttendanceStatus::LATE->value => 'Late arrival',
            AttendanceStatus::ABSENT->value => 'Absent',
            AttendanceStatus::EXCUSED->value => 'Excused',
            default => $statusVal,
        };

        $scanTime = $record->scanned_at
            ? $record->scanned_at->format('h:i A')
            : ($statusVal === AttendanceStatus::ABSENT->value ? '—' : ($record->created_at?->format('h:i A') ?? '—'));

        $scanTimeAr = str_replace(['AM', 'PM'], ['ص', 'م'], $scanTime);

        return [
            'id' => $record->uuid,
            'date' => $sessionDate?->format('Y-m-d') ?? '',
            'dateAr' => $sessionDate ? $this->formatArabicDate($sessionDate) : '—',
            'subjectAr' => $teacherName ? "{$subjectBaseAr} ({$teacherName})" : $subjectBaseAr,
            'subjectEn' => $teacherName ? "{$subjectBaseEn} ({$teacherName})" : $subjectBaseEn,
            'roomAr' => $roomLabel,
            'scanTime' => $scanTimeAr,
            'scanTimeEn' => $scanTime,
            'status' => $statusVal,
            'statusAr' => $statusAr,
            'statusEn' => $statusEn,
            'notes' => $record->notes,
        ];
    }

    protected function formatInquiry(ParentInquiry $inquiry): array
    {
        $centerName = $inquiry->center?->centerProfile?->name_ar ?: $inquiry->center?->name;

        return [
            'id' => $inquiry->uuid,
            'studentId' => $inquiry->student_id ? (int) $inquiry->student_id : null,
            'studentName' => $inquiry->student?->name,
            'recipientType' => $inquiry->recipient_type,
            'centerNameAr' => $centerName,
            'teacherName' => $inquiry->teacher?->name,
            'subject' => $inquiry->subject,
            'message' => $inquiry->message,
            'status' => $inquiry->status,
            'statusAr' => match ($inquiry->status) {
                'replied' => 'تم الرد',
                'closed' => 'مغلق',
                default => 'قيد المراجعة',
            },
            'replyText' => $inquiry->reply_text,
            'repliedAt' => $inquiry->replied_at?->toIso8601String(),
            'createdAt' => $inquiry->created_at?->toIso8601String(),
        ];
    }

    protected function formatParentSettings(User $parent, ParentProfile $profile): array
    {
        return [
            'id' => (int) $parent->id,
            'name' => $parent->name,
            'email' => $parent->email,
            'phone' => $parent->phone ?? '',
            'emergencyPhone' => $profile->emergency_phone ?? '',
            'avatar' => $parent->avatar ?: null,
            'notifyTuitionRenewal' => (bool) $profile->notify_tuition_renewal,
            'notifyAchievementsCertificates' => (bool) $profile->notify_achievements_certificates,
            'preferredLang' => $profile->preferred_lang ?: 'ar',
            'preferredTheme' => $profile->preferred_theme ?: 'system',
        ];
    }

    /**
     * Compute the next upcoming scheduled session from the student's active study groups.
     *
     * @param  Collection<int, GroupEnrollment>  $enrollments
     * @return array{0: string, 1: string, 2: bool}
     */
    protected function resolveNextSession(Collection $enrollments): array
    {
        $now = now();
        $bestDiffMinutes = null;
        $bestAr = null;
        $bestEn = null;

        foreach ($enrollments as $enrollment) {
            $group = $enrollment->group;
            if (! $group) {
                continue;
            }

            foreach ($group->scheduleSlots as $slot) {
                $dayEnum = $slot->day_of_week instanceof DayOfWeek
                    ? $slot->day_of_week
                    : DayOfWeek::tryFrom((string) $slot->day_of_week);

                if (! $dayEnum) {
                    continue;
                }

                $targetDow = self::DAY_ORDER[$dayEnum->value] ?? null;
                if ($targetDow === null) {
                    continue;
                }

                $daysAhead = ($targetDow - $now->dayOfWeek + 7) % 7;
                $timeStr = preg_match('/^\d{2}:\d{2}/', (string) $slot->start_time)
                    ? substr((string) $slot->start_time, 0, 5)
                    : '16:00';

                $candidate = $now->copy()->addDays($daysAhead)->setTimeFromTimeString($timeStr);
                if ($candidate->lessThanOrEqualTo($now)) {
                    $candidate->addDays(7);
                }

                $diffMinutes = $now->diffInMinutes($candidate);
                if ($bestDiffMinutes === null || $diffMinutes < $bestDiffMinutes) {
                    $bestDiffMinutes = $diffMinutes;
                    $subjectAr = $group->subject_ar ?: $group->name_ar;
                    $subjectEn = $group->subject_en ?: ($group->name_en ?: $subjectAr);
                    $hall = $slot->hall_name ?: ($group->centerRoom?->name_ar ?: ($group->hall_name ?: ''));
                    $timeAr = str_replace(['AM', 'PM'], ['ص', 'م'], $candidate->format('h:i A'));
                    $timeEn = $candidate->format('h:i A');
                    $hallSuffixAr = $hall ? " ({$hall})" : '';
                    $hallSuffixEn = $hall ? " ({$hall})" : '';
                    $dayPrefixAr = $daysAhead === 0 ? 'اليوم' : ($daysAhead === 1 ? 'غداً' : $dayEnum->labelAr());
                    $dayPrefixEn = $daysAhead === 0 ? 'Today' : ($daysAhead === 1 ? 'Tomorrow' : $dayEnum->labelEn());

                    $bestAr = "{$dayPrefixAr} {$timeAr} — {$subjectAr}{$hallSuffixAr}";
                    $bestEn = "{$dayPrefixEn} {$timeEn} — {$subjectEn}{$hallSuffixEn}";
                }
            }
        }

        if ($bestAr !== null) {
            return [$bestAr, $bestEn, true];
        }

        // Fall back to textual schedule_ar on any enrolled group
        $firstGroup = $enrollments->first(fn (GroupEnrollment $e) => ! empty($e->group?->schedule_ar))?->group;
        if ($firstGroup) {
            $subjectAr = $firstGroup->subject_ar ?: $firstGroup->name_ar;
            $subjectEn = $firstGroup->subject_en ?: ($firstGroup->name_en ?: $subjectAr);

            return [
                "{$subjectAr} — {$firstGroup->schedule_ar}",
                "{$subjectEn} — ".($firstGroup->schedule_en ?: $firstGroup->schedule_ar),
                true,
            ];
        }

        return [
            'لا توجد حصص مجدولة حالياً — عند انضمام الطالب لمجموعة ستظهر مواعيده هنا تلقائياً.',
            'No scheduled sessions yet — upcoming classes appear automatically once enrolled in a study group.',
            false,
        ];
    }

    /**
     * Resolve the latest real teacher feedback/note from graded exams, graded homework, or active risk signals.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    protected function resolveLatestTeacherNote(
        Collection $gradedExams,
        Collection $homeworkSubmissions,
        Collection $riskSignals
    ): array {
        /** @var ExamSubmission|null $examWithFeedback */
        $examWithFeedback = $gradedExams->first(fn (ExamSubmission $s) => ! empty(trim((string) $s->teacher_feedback)));
        if ($examWithFeedback) {
            $author = $examWithFeedback->exam?->teacher?->name;

            return [
                trim((string) $examWithFeedback->teacher_feedback),
                trim((string) $examWithFeedback->teacher_feedback),
                $author,
            ];
        }

        /** @var HomeworkSubmission|null $hwWithFeedback */
        $hwWithFeedback = $homeworkSubmissions->first(fn (HomeworkSubmission $s) => ! empty(trim((string) $s->feedback_ar)));
        if ($hwWithFeedback) {
            $author = $hwWithFeedback->homework?->teacher?->name;

            return [
                trim((string) $hwWithFeedback->feedback_ar),
                trim((string) $hwWithFeedback->feedback_ar),
                $author,
            ];
        }

        /** @var StudentRiskSignal|null $signal */
        $signal = $riskSignals->first();
        if ($signal && ! empty(trim((string) $signal->risk_reasons_ar))) {
            return [
                trim((string) $signal->risk_reasons_ar),
                trim((string) $signal->risk_reasons_ar),
                $signal->teacher?->name,
            ];
        }

        return [
            'لا توجد ملاحظات مسجلة من المعلمين حتى الآن. ستظهر هنا فور رصد المعلم لتقييم الاختبارات أو الواجبات.',
            'No teacher remarks recorded yet. Feedback will appear here once teachers grade exams or assignments.',
            null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function resolveGradeStanding(int $avgScore, bool $hasGradedExams): array
    {
        if (! $hasGradedExams && $avgScore === 0) {
            return ['لا توجد اختبارات بعد', 'No exams yet'];
        }

        return match (true) {
            $avgScore >= 90 => ['تقدير امتياز', 'Excellent'],
            $avgScore >= 80 => ['جيد جداً', 'Very Good'],
            $avgScore >= 65 => ['جيد', 'Good'],
            $avgScore >= 50 => ['مقبول', 'Pass'],
            default => ['يحتاج متابعة', 'Needs Support'],
        };
    }

    protected function shortenGradeAr(string $gradeAr): string
    {
        $map = [
            'الصف الثالث الثانوي' => '3 ثانوي',
            'الصف الثاني الثانوي' => '2 ثانوي',
            'الصف الأول الثانوي' => '1 ثانوي',
            'الصف الثالث الإعدادي' => '3 إعدادي',
            'الصف الثاني الإعدادي' => '2 إعدادي',
            'الصف الأول الإعدادي' => '1 إعدادي',
            'الصف السادس الابتدائي' => '6 ابتدائي',
            'الصف الخامس الابتدائي' => '5 ابتدائي',
            'الصف الرابع الابتدائي' => '4 ابتدائي',
        ];

        foreach ($map as $full => $short) {
            if (str_contains($gradeAr, $full)) {
                return $short;
            }
        }

        return mb_substr($gradeAr, 0, 18);
    }

    protected function formatArabicDate(Carbon $date): string
    {
        $days = [
            0 => 'الأحد',
            1 => 'الإثنين',
            2 => 'الثلاثاء',
            3 => 'الأربعاء',
            4 => 'الخميس',
            5 => 'الجمعة',
            6 => 'السبت',
        ];
        $months = [
            1 => 'يناير',
            2 => 'فبراير',
            3 => 'مارس',
            4 => 'أبريل',
            5 => 'مايو',
            6 => 'يونيو',
            7 => 'يوليو',
            8 => 'أغسطس',
            9 => 'سبتمبر',
            10 => 'أكتوبر',
            11 => 'نوفمبر',
            12 => 'ديسمبر',
        ];

        $dayName = $days[$date->dayOfWeek] ?? '';
        $monthName = $months[$date->month] ?? '';

        return trim("{$dayName} {$date->day} {$monthName} {$date->year}");
    }

    protected function sanitizeCsvCell(mixed $value): string
    {
        $str = (string) ($value ?? '');
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$str;
        }

        return $str;
    }
}
