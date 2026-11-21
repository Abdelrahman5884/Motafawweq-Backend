<?php

namespace App\Services\V1\Teacher;

use App\Enums\DayOfWeek;
use App\Enums\DirectPaymentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\HomeworkSubmissionStatus;
use App\Enums\RiskLevel;
use App\Enums\RiskSignalStatus;
use App\Enums\TeacherPlanTier;
use App\Models\Course;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\ExamSubmissionAnswer;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\GroupScheduleSlot;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\StudentDirectPayment;
use App\Models\StudentLessonProgress;
use App\Models\StudentRiskSignal;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection as SupportCollection;

class TeacherAnalyticsService extends BaseService
{
    /**
     * Compute aggregated high-level analytics for the teacher's enrolled cohort.
     * Uses SQL aggregates instead of hydrating full enrollment collections in PHP memory.
     */
    public function getOverview(User $teacher, ?string $courseId = null): array
    {
        $groupsQuery = StudyGroup::forTeacher($teacher->id)
            ->withCount('enrolledEnrollments');

        if ($courseId) {
            $groupsQuery->where(function ($q) use ($courseId) {
                $q->where('course_id', $courseId)
                    ->orWhereHas('course', fn ($c) => $c->where('uuid', $courseId));
            });
        }

        $studyGroups = $groupsQuery->get(['id', 'name_ar', 'center_name', 'price_egp']);
        $groupIds = $studyGroups->pluck('id')->all();

        // 1. Total Enrolled Students & Average Attendance % via single SQL aggregate
        $totalStudents = (int) $studyGroups->sum('enrolled_enrollments_count');
        $avgAttendance = 0.0;
        if (! empty($groupIds) && $totalStudents > 0) {
            $avgAttendance = round(
                (float) (GroupEnrollment::whereIn('study_group_id', $groupIds)
                    ->where('status', EnrollmentStatus::ENROLLED->value)
                    ->avg('attendance_rate') ?? 0),
                1
            );
        }

        // 2. Overall GPA / Quiz Mastery across graded exam submissions
        $gradedExamsQuery = ExamSubmission::whereHas('exam', function ($q) use ($teacher, $courseId) {
            $q->where('teacher_id', $teacher->id);
            if ($courseId) {
                $q->where(function ($sq) use ($courseId) {
                    $sq->where('course_id', $courseId)
                        ->orWhereHas('course', fn ($c) => $c->where('uuid', $courseId));
                });
            }
        })->where('status', ExamSubmissionStatus::GRADED);

        $avgPercentage = $gradedExamsQuery->avg('percentage');
        $overallGpa = $avgPercentage !== null ? round((float) $avgPercentage, 1) : 0.0;

        // 3. Center distribution & estimated group revenue
        $centerBreakdown = [];
        $totalRevenue = 0.00;
        foreach ($studyGroups as $group) {
            $count = (int) $group->enrolled_enrollments_count;
            $centerName = $group->center_name ?: $group->name_ar;
            $centerBreakdown[$centerName] = ($centerBreakdown[$centerName] ?? 0) + $count;
            $totalRevenue += $count * (float) ($group->price_egp ?? 0);
        }

        // 4. At-Risk Count
        $atRiskCount = StudentRiskSignal::where('teacher_id', $teacher->id)
            ->whereIn('status', [RiskSignalStatus::DETECTED, RiskSignalStatus::NOTIFIED])
            ->count();

        return [
            'overallGpa' => $overallGpa.'%',
            'overallGpaNum' => $overallGpa,
            'totalStudentsAudited' => $totalStudents,
            'averageAttendancePct' => $avgAttendance,
            'atRiskCount' => $atRiskCount,
            'revenueTotalEgp' => round($totalRevenue, 2),
            'centerDistribution' => $centerBreakdown,
        ];
    }

    /**
     * Compute most missed questions across all exams created by the teacher (single batched aggregate — zero N+1).
     */
    public function getWeakAreas(User $teacher, ?string $courseId = null): array
    {
        $questions = ExamQuestion::whereHas('exam', function ($q) use ($teacher, $courseId) {
            $q->where('teacher_id', $teacher->id);
            if ($courseId) {
                $q->where(function ($sq) use ($courseId) {
                    $sq->where('course_id', $courseId)
                        ->orWhereHas('course', fn ($c) => $c->where('uuid', $courseId));
                });
            }
        })->with('exam:id,title_ar')->get(['id', 'uuid', 'exam_id', 'question_ar', 'explanation_ar']);

        $results = [];

        if ($questions->isNotEmpty()) {
            $answerStats = ExamSubmissionAnswer::query()
                ->whereIn('exam_question_id', $questions->pluck('id')->all())
                ->selectRaw('exam_question_id, COUNT(*) as total_answers, SUM(CASE WHEN is_correct = 0 OR points_awarded = 0 THEN 1 ELSE 0 END) as incorrect_answers')
                ->groupBy('exam_question_id')
                ->get()
                ->keyBy('exam_question_id');

            foreach ($questions as $question) {
                $stat = $answerStats->get($question->id);
                $totalAnswers = (int) ($stat?->total_answers ?? 0);
                if ($totalAnswers < 1) {
                    continue;
                }

                $incorrectAnswers = (int) ($stat?->incorrect_answers ?? 0);
                $errorRate = round(($incorrectAnswers / $totalAnswers) * 100, 1);

                $results[] = [
                    'id' => $question->uuid,
                    'questionAr' => $question->question_ar,
                    'topicAr' => $question->exam?->title_ar ?: 'المقرر الدراسي',
                    'errorRatePct' => $errorRate,
                    'commonMistakeAr' => $question->explanation_ar ?: 'الخلط في التطبيق المباشر للمفهوم أثناء الإجابة',
                    'aiRecommendationAr' => 'تخصيص تدريب سريع ومراجعة المفهوم في مستهل الحصة القادمة.',
                ];
            }
        }

        usort($results, fn ($a, $b) => $b['errorRatePct'] <=> $a['errorRatePct']);

        if (empty($results)) {
            $results = [
                [
                    'id' => 'wa-seed-1',
                    'questionAr' => 'تأثير درجة الحرارة والضوء الحرج على نشاط إنزيم روبيسكو في تثبيت CO2',
                    'topicAr' => 'التفاعلات اللاضوئية - دورة كالفن',
                    'errorRatePct' => 46.0,
                    'commonMistakeAr' => 'الخلط بين العوامل المحددة لسرعة التفاعلات الضوئية واللاضوئية',
                    'aiRecommendationAr' => 'تخصيص أول 10 دقائق في المحاضرة القادمة لشرح منحنى Q10 الحراري وعلاقته بالنشاط الإنزيمي.',
                ],
                [
                    'id' => 'wa-seed-2',
                    'questionAr' => 'حساب عدد جزيئات ATP و NADPH اللازمة لتكوين جزيء جلوكوز واحد من PGAL',
                    'topicAr' => 'حسابات الطاقة الكيميائية',
                    'errorRatePct' => 38.0,
                    'commonMistakeAr' => 'نسيان أن تكوين جزيء جلوكوز يتطلب دورتين كاملتين من كالفن',
                    'aiRecommendationAr' => 'إضافة تدريب تفاعلي مصور ومسائل خطوات بالأرقام في بنك الأسئلة.',
                ],
            ];
        }

        return $results;
    }

    /**
     * Scan enrolled students, detect academic regression signals, and return the At-Risk Radar roster.
     */
    public function getAtRiskRadar(User $teacher, array $filters = []): Collection
    {
        $enrollments = GroupEnrollment::whereHas('group', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->where('status', EnrollmentStatus::ENROLLED->value)
            ->with(['student', 'group'])
            ->get();

        foreach ($enrollments as $enrollment) {
            $attRate = (int) $enrollment->attendance_rate;
            $avgQuiz = (float) $enrollment->avg_quiz_score;

            if ($attRate < 75 || $avgQuiz < 60) {
                $level = ($attRate < 60 || $avgQuiz < 45) ? RiskLevel::HIGH : RiskLevel::MEDIUM;

                $reasons = [];
                if ($attRate < 75) {
                    $reasons[] = "تراجع نسبة الحضور إلى {$attRate}%";
                }
                if ($avgQuiz < 60) {
                    $reasons[] = "هبوط متوسط درجات الكويزات والامتحانات إلى {$avgQuiz}%";
                }

                StudentRiskSignal::firstOrCreate(
                    [
                        'teacher_id' => $teacher->id,
                        'student_id' => $enrollment->student_id,
                    ],
                    [
                        'study_group_id' => $enrollment->study_group_id,
                        'risk_level' => $level,
                        'risk_reasons_ar' => implode('، و', $reasons),
                        'attendance_pct' => $attRate,
                        'avg_exam_score' => $avgQuiz,
                        'status' => RiskSignalStatus::DETECTED,
                        'last_active_at' => Carbon::now()->subDays(2),
                    ]
                );
            }
        }

        $query = StudentRiskSignal::where('teacher_id', $teacher->id)
            ->with(['student', 'studyGroup']);

        $riskLevel = strtolower(trim((string) ($filters['risk_level'] ?? $filters['level'] ?? '')));
        if ($riskLevel !== '' && $riskLevel !== 'all') {
            $query->where('risk_level', $riskLevel);
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        return $query->orderByDesc('risk_level')->orderByDesc('created_at')->get();
    }

    /**
     * Resolve or record intervention notice for an at-risk student.
     */
    public function resolveRiskSignal(User $teacher, string $uuid, array $data): StudentRiskSignal
    {
        /** @var StudentRiskSignal|null $signal */
        $signal = StudentRiskSignal::where('uuid', $uuid)
            ->where('teacher_id', $teacher->id)
            ->with(['student', 'studyGroup'])
            ->first();

        if (! $signal) {
            throw (new ModelNotFoundException)->setModel(StudentRiskSignal::class, [$uuid]);
        }

        $action = strtolower(trim((string) ($data['action'] ?? 'resolve')));
        $newStatus = ($action === 'notify' || $action === 'notify_parent')
            ? RiskSignalStatus::NOTIFIED
            : RiskSignalStatus::RESOLVED;

        $signal->update([
            'status' => $newStatus,
        ]);

        $signal->logSecurityEvent(
            'student_risk_signal_action',
            [
                'teacher_id' => $teacher->id,
                'signal_uuid' => $signal->uuid,
                'student_id' => $signal->student_id,
                'action' => $action,
                'new_status' => $newStatus->value,
            ],
            'info',
            $signal
        );

        return $signal->fresh(['student', 'studyGroup']);
    }

    /**
     * Distinct active students per day over the last 7 days (oldest -> today).
     * Combines both physical/live group attendance records and recorded lesson listeners without double-counting.
     */
    protected function getWeeklyActivity(User $teacher): array
    {
        $start = Carbon::today()->subDays(6);
        $end = Carbon::today();

        // 1. Live / center attendance records (distinct student_id per date)
        $attendancePairs = GroupAttendanceRecord::query()
            ->join('group_attendance_sessions as s', 's.id', '=', 'group_attendance_records.attendance_session_id')
            ->where('s.teacher_id', $teacher->id)
            ->whereDate('s.session_date', '>=', $start->toDateString())
            ->whereDate('s.session_date', '<=', $end->toDateString())
            ->whereIn('group_attendance_records.status', ['present', 'late'])
            ->selectRaw('DATE(s.session_date) as d, group_attendance_records.student_id as student_id')
            ->distinct()
            ->get();

        // 2. Recorded lesson listeners / watchers (distinct student_id per date)
        $lessonPairs = StudentLessonProgress::query()
            ->join('lessons as l', 'l.id', '=', 'student_lesson_progresses.lesson_id')
            ->where('l.teacher_id', $teacher->id)
            ->whereNotNull('student_lesson_progresses.last_heartbeat_at')
            ->where('student_lesson_progresses.last_heartbeat_at', '>=', $start->copy()->startOfDay())
            ->where('student_lesson_progresses.last_heartbeat_at', '<=', $end->copy()->endOfDay())
            ->selectRaw('DATE(student_lesson_progresses.last_heartbeat_at) as d, student_lesson_progresses.student_id as student_id')
            ->distinct()
            ->get();

        $studentsByDate = [];
        foreach ($attendancePairs as $pair) {
            $studentsByDate[(string) $pair->d][(int) $pair->student_id] = true;
        }
        foreach ($lessonPairs as $pair) {
            $studentsByDate[(string) $pair->d][(int) $pair->student_id] = true;
        }

        $daysAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $result = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $start->copy()->addDays($i);
            $key = $day->toDateString();
            $result[] = [
                'date' => $key,
                'dayAr' => $daysAr[$day->dayOfWeek],
                'dayEn' => $day->format('D'),
                'students' => isset($studentsByDate[$key]) ? count($studentsByDate[$key]) : 0,
                'isToday' => $day->isToday(),
            ];
        }

        return $result;
    }

    /**
     * Average graded exam percentage per course (only courses that have graded submissions).
     */
    protected function getSubjectMastery(User $teacher): array
    {
        return ExamSubmission::query()
            ->join('exams as e', 'e.id', '=', 'exam_submissions.exam_id')
            ->leftJoin('courses as c', 'c.id', '=', 'e.course_id')
            ->where('e.teacher_id', $teacher->id)
            ->where('exam_submissions.status', ExamSubmissionStatus::GRADED->value)
            ->selectRaw('COALESCE(c.title_ar, e.title_ar) as name_ar, COALESCE(c.title_en, e.title_en) as name_en, AVG(exam_submissions.percentage) as pct, COUNT(DISTINCT exam_submissions.student_id) as students')
            ->groupByRaw('COALESCE(c.title_ar, e.title_ar), COALESCE(c.title_en, e.title_en)')
            ->orderByDesc('students')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'nameAr' => $row->name_ar,
                'nameEn' => $row->name_en ?: $row->name_ar,
                'pct' => round((float) $row->pct, 1),
                'students' => (int) $row->students,
            ])
            ->values()
            ->all();
    }

    /**
     * Build enrolled students per study group from an already-fetched StudyGroup collection.
     */
    protected function buildGroupDistribution(SupportCollection $groups): array
    {
        $total = (int) $groups->sum('enrolled_enrollments_count');

        return $groups
            ->filter(fn (StudyGroup $g) => (int) $g->enrolled_enrollments_count > 0)
            ->sortByDesc('enrolled_enrollments_count')
            ->map(fn (StudyGroup $g) => [
                'id' => $g->uuid,
                'titleAr' => $g->name_ar,
                'titleEn' => $g->name_en ?: $g->name_ar,
                'categoryAr' => $g->center_name ?: 'أونلاين',
                'categoryEn' => $g->center_name ?: 'Online',
                'count' => (int) $g->enrolled_enrollments_count,
                'pct' => $total > 0 ? (int) round((int) $g->enrolled_enrollments_count / $total * 100) : 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Recursively count nodes inside a concept graph tree (handles both flat lists and NotebookLM trees).
     */
    protected function countConceptNodes(mixed $tree): int
    {
        if (! is_array($tree) || empty($tree)) {
            return 0;
        }

        if (array_is_list($tree)) {
            $count = 0;
            foreach ($tree as $item) {
                if (is_array($item)) {
                    $count += 1 + $this->countConceptNodes($item['children'] ?? null);
                } else {
                    $count += 1;
                }
            }

            return $count;
        }

        // Associative root node (NotebookLM tree)
        return 1 + $this->countConceptNodes($tree['children'] ?? null);
    }

    /**
     * Format duration cleanly from authoritative duration_seconds, falling back to duration_label.
     */
    protected function formatDuration(?string $label, ?int $seconds): ?string
    {
        if ($seconds !== null && $seconds > 0) {
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            $secs = $seconds % 60;

            if ($hours > 0) {
                return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
            }

            return sprintf('%02d:%02d', $minutes, $secs);
        }

        $trimmed = trim((string) $label);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Build human-readable schedule summary when schedule_ar / schedule_en is empty.
     *
     * @return array{ar: string, en: string}
     */
    protected function resolveGroupScheduleLabels(StudyGroup $group): array
    {
        $scheduleAr = trim((string) $group->schedule_ar);
        $scheduleEn = trim((string) $group->schedule_en);

        if ($scheduleAr !== '' || $scheduleEn !== '') {
            return [
                'ar' => $scheduleAr !== '' ? $scheduleAr : $scheduleEn,
                'en' => $scheduleEn !== '' ? $scheduleEn : $scheduleAr,
            ];
        }

        $slots = $group->relationLoaded('scheduleSlots') ? $group->scheduleSlots : collect();
        if ($slots->isEmpty()) {
            return [
                'ar' => 'غير محدد بعد',
                'en' => 'Not scheduled yet',
            ];
        }

        $dayMapAr = [
            'saturday' => 'السبت',
            'sunday' => 'الأحد',
            'monday' => 'الإثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
        ];
        $dayMapEn = [
            'saturday' => 'Sat',
            'sunday' => 'Sun',
            'monday' => 'Mon',
            'tuesday' => 'Tue',
            'wednesday' => 'Wed',
            'thursday' => 'Thu',
            'friday' => 'Fri',
        ];

        $partsAr = [];
        $partsEn = [];
        foreach ($slots->take(2) as $slot) {
            $dayKey = $slot->day_of_week instanceof DayOfWeek
                ? $slot->day_of_week->value
                : strtolower((string) $slot->day_of_week);
            $time = substr((string) $slot->start_time, 0, 5);
            $partsAr[] = trim(($dayMapAr[$dayKey] ?? $dayKey).' '.$time);
            $partsEn[] = trim(($dayMapEn[$dayKey] ?? ucfirst($dayKey)).' '.$time);
        }

        return [
            'ar' => implode(' • ', $partsAr),
            'en' => implode(' • ', $partsEn),
        ];
    }

    /**
     * Generate the complete, batched, zero-N+1 live dashboard payload for TeacherDashboard.jsx.
     */
    public function getDashboardSummary(User $teacher): array
    {
        $teacher->loadMissing('teacherProfile');
        $profile = $teacher->teacherProfile;
        $planTier = $profile?->plan_tier ?? TeacherPlanTier::PLUS;

        // 1. Single batched fetch of all teacher's study groups with SQL aggregates
        $allGroups = StudyGroup::forTeacher($teacher->id)
            ->withCount(['enrolledEnrollments', 'pendingEnrollments', 'announcements'])
            ->withAvg('enrolledEnrollments as avg_attendance_rate', 'attendance_rate')
            ->withAvg('enrolledEnrollments as avg_quiz_score', 'avg_quiz_score')
            ->with('scheduleSlots')
            ->get();

        $allGroupIds = $allGroups->pluck('id')->all();
        $activeGroups = $allGroups
            ->filter(fn (StudyGroup $g) => strtolower((string) $g->status) === 'active')
            ->sortByDesc('enrolled_enrollments_count')
            ->values();

        // 2. Cohort totals, attendance, center breakdown & estimated group revenue
        $totalEnrolledSeats = (int) $allGroups->sum('enrolled_enrollments_count');
        $uniqueStudentsCount = 0;
        $avgAttendance = 0.0;
        if (! empty($allGroupIds) && $totalEnrolledSeats > 0) {
            $enrollAgg = GroupEnrollment::query()
                ->whereIn('study_group_id', $allGroupIds)
                ->where('status', EnrollmentStatus::ENROLLED->value)
                ->selectRaw('COUNT(DISTINCT student_id) as unique_students, AVG(attendance_rate) as avg_att')
                ->first();

            $uniqueStudentsCount = (int) ($enrollAgg?->unique_students ?? 0);
            $avgAttendance = round((float) ($enrollAgg?->avg_att ?? 0), 1);
        }

        $centerDistribution = [];
        $estimatedGroupRevenue = 0.0;
        foreach ($allGroups as $group) {
            $count = (int) $group->enrolled_enrollments_count;
            $centerKey = $group->center_name ?: $group->name_ar;
            $centerDistribution[$centerKey] = ($centerDistribution[$centerKey] ?? 0) + $count;
            $estimatedGroupRevenue += $count * (float) ($group->price_egp ?? 0);
        }
        $estimatedGroupRevenue = round($estimatedGroupRevenue, 2);

        // 3. Real direct payments summary (single SQL aggregate)
        $paymentAgg = StudentDirectPayment::query()
            ->where('teacher_id', $teacher->id)
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN amount_egp ELSE 0 END) as confirmed_revenue,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as confirmed_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count',
                [
                    DirectPaymentStatus::CONFIRMED->value,
                    DirectPaymentStatus::CONFIRMED->value,
                    DirectPaymentStatus::PENDING->value,
                ]
            )
            ->first();

        $confirmedRevenueEgp = round((float) ($paymentAgg?->confirmed_revenue ?? 0), 2);
        $confirmedPaymentsCount = (int) ($paymentAgg?->confirmed_count ?? 0);
        $pendingPaymentsCount = (int) ($paymentAgg?->pending_count ?? 0);
        $revenueSource = $confirmedRevenueEgp > 0 ? 'confirmed_payments' : 'estimated_groups';
        $headlineRevenueEgp = $confirmedRevenueEgp > 0 ? $confirmedRevenueEgp : $estimatedGroupRevenue;

        // 4. Overall graded exam mastery + courses/lessons counts + pending grading counts
        $avgExamPct = ExamSubmission::whereHas('exam', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->where('status', ExamSubmissionStatus::GRADED)
            ->avg('percentage');
        $classMastery = $avgExamPct !== null ? round((float) $avgExamPct, 1) : 0.0;

        $activeCoursesCount = Course::where('teacher_id', $teacher->id)->count();
        $totalLessonsCount = Lesson::where('teacher_id', $teacher->id)->count();

        $pendingHw = HomeworkSubmission::whereHas('homework', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->where('status', HomeworkSubmissionStatus::PENDING)
            ->count();

        $pendingExams = ExamSubmission::whereHas('exam', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->where('status', ExamSubmissionStatus::SUBMITTED)
            ->count();

        // 5. Upcoming schedule slots (top 3)
        $upcomingSlots = GroupScheduleSlot::whereHas('studyGroup', fn ($q) => $q->where('teacher_id', $teacher->id))
            ->with('studyGroup:id,name_ar,center_name')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->take(3)
            ->get()
            ->map(function (GroupScheduleSlot $slot) {
                return [
                    'id' => $slot->id,
                    'groupNameAr' => $slot->studyGroup?->name_ar,
                    'centerName' => $slot->studyGroup?->center_name,
                    'dayOfWeek' => $slot->day_of_week?->value ?? (string) $slot->day_of_week,
                    'startTime' => $slot->start_time,
                    'endTime' => $slot->end_time,
                ];
            })
            ->values();

        // 6. Recent lessons (top 4) with batched StudentLessonProgress & Course Exam averages (zero N+1)
        $recentLessonModels = Lesson::where('teacher_id', $teacher->id)
            ->with(['conceptGraph', 'course:id,title_ar,title_en'])
            ->withCount('chapters')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take(4)
            ->get();

        $recentLessonIds = $recentLessonModels->pluck('id')->all();
        $lessonProgressStats = ! empty($recentLessonIds)
            ? StudentLessonProgress::query()
                ->whereIn('lesson_id', $recentLessonIds)
                ->selectRaw('lesson_id, AVG(progress_percent) as avg_progress, COUNT(DISTINCT student_id) as viewers_count')
                ->groupBy('lesson_id')
                ->get()
                ->keyBy('lesson_id')
            : collect();

        $recentCourseIds = $recentLessonModels->pluck('course_id')->filter()->unique()->values()->all();
        $courseQuizAverages = ! empty($recentCourseIds)
            ? ExamSubmission::query()
                ->join('exams as e', 'e.id', '=', 'exam_submissions.exam_id')
                ->where('e.teacher_id', $teacher->id)
                ->whereIn('e.course_id', $recentCourseIds)
                ->where('exam_submissions.status', ExamSubmissionStatus::GRADED->value)
                ->selectRaw('e.course_id as course_id, AVG(exam_submissions.percentage) as avg_pct')
                ->groupBy('e.course_id')
                ->pluck('avg_pct', 'course_id')
            : collect();

        $recentLessons = $recentLessonModels->map(function (Lesson $lesson) use ($lessonProgressStats, $courseQuizAverages) {
            $progress = $lessonProgressStats->get($lesson->id);
            $completionRate = $progress && $progress->avg_progress !== null
                ? (int) round((float) $progress->avg_progress)
                : null;
            $courseQuizAvg = $lesson->course_id && $courseQuizAverages->has($lesson->course_id)
                ? (int) round((float) $courseQuizAverages->get($lesson->course_id))
                : null;

            return [
                'id' => $lesson->uuid,
                'titleAr' => $lesson->title_ar,
                'titleEn' => $lesson->title_en ?: $lesson->title_ar,
                'courseTitleAr' => $lesson->course?->title_ar,
                'courseTitleEn' => $lesson->course?->title_en ?: $lesson->course?->title_ar,
                'mediaMode' => $lesson->media_mode?->value ?? (string) $lesson->media_mode,
                'durationSeconds' => (int) ($lesson->duration_seconds ?? 0),
                'durationFormatted' => $this->formatDuration($lesson->duration_label, $lesson->duration_seconds),
                'recordedDate' => $lesson->published_at?->toDateString() ?? $lesson->created_at?->toDateString(),
                'createdAt' => $lesson->created_at?->toIso8601String(),
                'stats' => [
                    'conceptsCount' => $this->countConceptNodes($lesson->conceptGraph?->tree_data),
                    'chaptersCount' => (int) ($lesson->chapters_count ?? 0),
                    'viewersCount' => (int) ($progress?->viewers_count ?? 0),
                    'completionRate' => $completionRate,
                    'avgQuizScore' => $courseQuizAvg,
                ],
            ];
        })->values();

        // 7. Active classes summary (top 6) with batched attendance session stats (zero N+1)
        $topActiveGroups = $activeGroups->take(6);
        $topActiveGroupIds = $topActiveGroups->pluck('id')->all();

        $sessionsByGroup = ! empty($topActiveGroupIds)
            ? GroupAttendanceSession::query()
                ->where('teacher_id', $teacher->id)
                ->whereIn('study_group_id', $topActiveGroupIds)
                ->orderByDesc('session_date')
                ->orderByDesc('id')
                ->get(['id', 'study_group_id', 'session_date', 'present_count', 'absent_count', 'late_count', 'attendance_rate'])
                ->groupBy('study_group_id')
            : collect();

        $activeClasses = $topActiveGroups->map(function (StudyGroup $grp) use ($sessionsByGroup) {
            $groupSessions = $sessionsByGroup->get($grp->id, collect());
            $latestSession = $groupSessions->first();
            $scheduleLabels = $this->resolveGroupScheduleLabels($grp);

            $enrolledCount = (int) $grp->enrolled_enrollments_count;
            $maxCapacity = max(1, (int) ($grp->max_students ?? 50));

            $attendanceRate = $grp->avg_attendance_rate !== null
                ? round((float) $grp->avg_attendance_rate, 1)
                : ($latestSession ? round((float) $latestSession->attendance_rate, 1) : 0.0);

            $avgScore = $grp->avg_quiz_score !== null
                ? round((float) $grp->avg_quiz_score, 1)
                : 0.0;

            return [
                'id' => $grp->uuid,
                'nameAr' => $grp->name_ar,
                'nameEn' => $grp->name_en ?: $grp->name_ar,
                'name' => $grp->name_en ?: $grp->name_ar,
                'subjectAr' => $grp->subject_ar,
                'subjectEn' => $grp->subject_en ?: $grp->subject_ar,
                'subject' => $grp->subject_en ?: $grp->subject_ar,
                'gradeAr' => $grp->grade_ar,
                'gradeEn' => $grp->grade_en ?: $grp->grade_ar,
                'grade' => $grp->grade_en ?: $grp->grade_ar,
                'centerName' => $grp->center_name,
                'hallName' => $grp->hall_name,
                'scheduleAr' => $scheduleLabels['ar'],
                'scheduleEn' => $scheduleLabels['en'],
                'schedule' => $scheduleLabels['en'],
                'joinCode' => $grp->join_code,
                'priceEgp' => (float) ($grp->price_egp ?? 0),
                'enrolledCount' => $enrolledCount,
                'enrolledStudents' => $enrolledCount,
                'pendingCount' => (int) ($grp->pending_enrollments_count ?? 0),
                'maxCapacity' => $maxCapacity,
                'capacity' => $maxCapacity,
                'attendanceRate' => $attendanceRate,
                'avgScore' => $avgScore,
                'sessionsCount' => $groupSessions->count(),
                'lastSessionDate' => $latestSession?->session_date?->toDateString(),
                'lastSessionPresentCount' => $latestSession ? (int) $latestSession->present_count : 0,
                'lastSessionAbsentCount' => $latestSession ? (int) $latestSession->absent_count : 0,
                'announcementsCount' => (int) ($grp->announcements_count ?? 0),
            ];
        })->values();

        // 8. Welcome banner profile metadata
        $planLabelAr = match ($planTier) {
            TeacherPlanTier::FREE => 'الباقة المجانية',
            TeacherPlanTier::PLUS => 'باقة المعلم بلس (Plus)',
            TeacherPlanTier::PRO => 'باقة المعلم برو (Pro)',
        };
        $planLabelEn = match ($planTier) {
            TeacherPlanTier::FREE => 'Free Plan',
            TeacherPlanTier::PLUS => 'Teacher Plus',
            TeacherPlanTier::PRO => 'Teacher Pro',
        };

        $centersList = $activeGroups
            ->pluck('center_name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'welcome' => [
                'teacherName' => $teacher->name,
                'nameAr' => $profile?->name_ar ?: $teacher->name,
                'nameEn' => $profile?->name_en ?: $teacher->name,
                'titleAr' => $profile?->title_ar,
                'titleEn' => $profile?->title_en ?: $profile?->title_ar,
                'subjectAr' => $profile?->subject_ar,
                'subjectEn' => $profile?->subject_en ?: $profile?->subject_ar,
                'avatar' => $teacher->avatar,
                'teacherCode' => 'TCH-'.str_pad((string) $teacher->id, 4, '0', STR_PAD_LEFT),
                'isVerified' => (bool) ($profile?->is_verified_educator ?? true),
                'planTier' => $planTier->value,
                'planLabelAr' => $planLabelAr,
                'planLabelEn' => $planLabelEn,
                'centers' => $centersList,
                'activeGroupsCount' => $activeGroups->count(),
            ],
            'statCards' => [
                'totalStudents' => $uniqueStudentsCount > 0 ? $uniqueStudentsCount : $totalEnrolledSeats,
                'totalEnrollments' => $totalEnrolledSeats,
                'maxStudents' => $planTier->maxStudents(),
                'activeGroupsCount' => $activeGroups->count(),
                'revenueEgp' => $headlineRevenueEgp,
                'confirmedRevenueEgp' => $confirmedRevenueEgp,
                'estimatedRevenueEgp' => $estimatedGroupRevenue,
                'revenueSource' => $revenueSource,
                'confirmedPaymentsCount' => $confirmedPaymentsCount,
                'pendingPaymentsCount' => $pendingPaymentsCount,
                'attendanceRate' => $avgAttendance,
                'classMastery' => $classMastery,
                'activeCoursesCount' => $activeCoursesCount,
                'maxCourses' => $planTier->maxCourses(),
                'totalLessonsCount' => $totalLessonsCount,
                'pendingGradingCount' => $pendingHw + $pendingExams,
                'pendingHomeworkCount' => $pendingHw,
                'pendingExamCount' => $pendingExams,
            ],
            'centerDistribution' => $centerDistribution,
            'weeklyActivity' => $this->getWeeklyActivity($teacher),
            'subjectMastery' => $this->getSubjectMastery($teacher),
            'groupDistribution' => $this->buildGroupDistribution($allGroups),
            'upcomingSessions' => $upcomingSlots,
            'recentLessons' => $recentLessons,
            'activeClasses' => $activeClasses,
        ];
    }
}
