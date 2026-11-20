<?php

namespace App\Services\V1;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Models\CourseLeague;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\LeagueParticipant;
use App\Models\StudentLessonProgress;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for Motafawweq League scoring rules, tier thresholds,
 * live activity-to-league XP synchronization, and authoritative participant metrics.
 */
class LeagueRulesService
{
    public const XP_EXAM_FULL_MARK = 50;

    public const XP_EXAM_PASSED = 30;

    public const XP_EXAM_PARTICIPATION = 10;

    public const XP_LESSON_COMPLETED = 25;

    public const XP_HOMEWORK_SUBMITTED = 15;

    public const XP_STREAK_PER_DAY = 20;

    /**
     * Resolve exact XP awarded for an exam submission based on official league rules.
     */
    public function resolveExamXp(bool $isFullMark, bool $isPassed): int
    {
        if ($isFullMark) {
            return self::XP_EXAM_FULL_MARK;
        }

        if ($isPassed) {
            return self::XP_EXAM_PASSED;
        }

        return self::XP_EXAM_PARTICIPATION;
    }

    /**
     * Resolve official Arabic rank badge title.
     */
    public function resolveRankBadgeAr(int $rank, bool $isGeneral = false): string
    {
        return match ($rank) {
            1 => $isGeneral ? 'بطل الجمهورية' : 'بطل الكورس',
            2 => $isGeneral ? 'وصيف الجمهورية' : 'وصيف الكورس',
            3 => 'المركز الثالث',
            4, 5, 6 => 'نجم متألق',
            default => 'منافس نشط',
        };
    }

    /**
     * Official scoring rules returned directly from the server engine.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getScoringRules(): array
    {
        return [
            [
                'id' => 'exam-full-mark',
                'points' => self::XP_EXAM_FULL_MARK,
                'pointsLabelAr' => '+'.self::XP_EXAM_FULL_MARK.' نقطة',
                'pointsLabelEn' => '+'.self::XP_EXAM_FULL_MARK.' XP',
                'titleAr' => 'تقفيل الامتحان بالدرجة النهائية (100%)',
                'titleEn' => 'Perfect Exam Score (100%)',
                'descAr' => 'تحصل تلقائياً على '.self::XP_EXAM_FULL_MARK.' نقطة عند تحقيق الدرجة النهائية الكاملة في أي امتحان معتمد.',
                'descEn' => 'Earn '.self::XP_EXAM_FULL_MARK.' XP automatically when scoring 100% on any graded exam.',
            ],
            [
                'id' => 'exam-passed',
                'points' => self::XP_EXAM_PASSED,
                'pointsLabelAr' => '+'.self::XP_EXAM_PASSED.' نقطة',
                'pointsLabelEn' => '+'.self::XP_EXAM_PASSED.' XP',
                'titleAr' => 'اجتياز الامتحان بنجاح',
                'titleEn' => 'Passing an Exam',
                'descAr' => 'تحصل على '.self::XP_EXAM_PASSED.' نقطة فور اجتياز درجة النجاح في الامتحان (أو +'.self::XP_EXAM_PARTICIPATION.' نقاط للمشاركة والإتمام).',
                'descEn' => 'Earn '.self::XP_EXAM_PASSED.' XP when passing an exam (or +'.self::XP_EXAM_PARTICIPATION.' XP for completion).',
            ],
            [
                'id' => 'lesson-completed',
                'points' => self::XP_LESSON_COMPLETED,
                'pointsLabelAr' => '+'.self::XP_LESSON_COMPLETED.' نقطة',
                'pointsLabelEn' => '+'.self::XP_LESSON_COMPLETED.' XP',
                'titleAr' => 'إتمام حصة دراسية كاملة',
                'titleEn' => 'Completing a Lesson',
                'descAr' => 'يُضاف '.self::XP_LESSON_COMPLETED.' نقطة مرة واحدة لكل حصة تنهي مشاهدتها وإتمامها داخل مقرراتك.',
                'descEn' => 'Earn '.self::XP_LESSON_COMPLETED.' XP once for each published lesson you complete.',
            ],
            [
                'id' => 'homework-submitted',
                'points' => self::XP_HOMEWORK_SUBMITTED,
                'pointsLabelAr' => '+'.self::XP_HOMEWORK_SUBMITTED.' نقطة',
                'pointsLabelEn' => '+'.self::XP_HOMEWORK_SUBMITTED.' XP',
                'titleAr' => 'تسليم الواجب الدراسي',
                'titleEn' => 'Submitting Homework',
                'descAr' => 'تحصل على '.self::XP_HOMEWORK_SUBMITTED.' نقطة تلقائياً عند تسليم كل واجب دراسي مطلوب من معلميك.',
                'descEn' => 'Earn '.self::XP_HOMEWORK_SUBMITTED.' XP automatically upon submitting an assigned homework.',
            ],
            [
                'id' => 'daily-streak',
                'points' => self::XP_STREAK_PER_DAY,
                'pointsLabelAr' => '+'.self::XP_STREAK_PER_DAY.' نقطة / يوم',
                'pointsLabelEn' => '+'.self::XP_STREAK_PER_DAY.' XP / day',
                'titleAr' => 'بونص الاستمرار اليومي (الاستريك)',
                'titleEn' => 'Daily Streak Bonus',
                'descAr' => 'كل يوم متواصل تفتح فيه المنصة يرفع رصيدك بـ '.self::XP_STREAK_PER_DAY.' نقطة ويكسر التعادل في المراكز.',
                'descEn' => 'Each consecutive active day adds '.self::XP_STREAK_PER_DAY.' XP and acts as the primary tie-breaker.',
            ],
            [
                'id' => 'teacher-bonus',
                'points' => null,
                'pointsLabelAr' => 'مكافأة المعلم',
                'pointsLabelEn' => 'Teacher Bonus',
                'titleAr' => 'نقاط التميز التشجيعية من المعلم',
                'titleEn' => 'Teacher Excellence Bonus',
                'descAr' => 'يمكن لمعلم المقرر منح نقاط XP إضافية للطلاب المتميزين في دوري الكورس وتُحتسب في الترتيب فوراً.',
                'descEn' => 'Teachers can award bonus XP inside course leagues for outstanding participation.',
            ],
        ];
    }

    /**
     * Official league tier ladder matching App\Enums\LeagueTier.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTiersDefinition(): array
    {
        return [
            [
                'id' => LeagueTier::DIAMOND->value,
                'nameAr' => LeagueTier::DIAMOND->labelAr(),
                'nameEn' => 'Diamond League',
                'minXp' => LeagueTier::DIAMOND->minXp(),
                'maxXp' => null,
                'color' => '#0EA5E9',
            ],
            [
                'id' => LeagueTier::GOLD->value,
                'nameAr' => LeagueTier::GOLD->labelAr(),
                'nameEn' => 'Gold League',
                'minXp' => LeagueTier::GOLD->minXp(),
                'maxXp' => LeagueTier::DIAMOND->minXp() - 1,
                'color' => '#F59E0B',
            ],
            [
                'id' => LeagueTier::SILVER->value,
                'nameAr' => LeagueTier::SILVER->labelAr(),
                'nameEn' => 'Silver League',
                'minXp' => LeagueTier::SILVER->minXp(),
                'maxXp' => LeagueTier::GOLD->minXp() - 1,
                'color' => '#64748B',
            ],
            [
                'id' => LeagueTier::BRONZE->value,
                'nameAr' => LeagueTier::BRONZE->labelAr(),
                'nameEn' => 'Bronze League',
                'minXp' => LeagueTier::BRONZE->minXp(),
                'maxXp' => LeagueTier::SILVER->minXp() - 1,
                'color' => '#B45309',
            ],
        ];
    }

    /**
     * Resolve next tier progression info for a given XP score.
     *
     * @return array<string, mixed>
     */
    public function resolveTierProgress(int $xp): array
    {
        $current = LeagueTier::resolveFromXp($xp);

        $next = match ($current) {
            LeagueTier::BRONZE => LeagueTier::SILVER,
            LeagueTier::SILVER => LeagueTier::GOLD,
            LeagueTier::GOLD => LeagueTier::DIAMOND,
            LeagueTier::DIAMOND => null,
        };

        if (! $next) {
            return [
                'tier' => $current->value,
                'tierLabelAr' => $current->labelAr(),
                'nextTier' => null,
                'nextTierLabelAr' => null,
                'xpToNextTier' => 0,
                'tierProgressPercent' => 100,
            ];
        }

        $floor = $current->minXp();
        $ceiling = $next->minXp();
        $span = max(1, $ceiling - $floor);
        $progress = (int) min(100, max(0, round((($xp - $floor) / $span) * 100)));

        return [
            'tier' => $current->value,
            'tierLabelAr' => $current->labelAr(),
            'nextTier' => $next->value,
            'nextTierLabelAr' => $next->labelAr(),
            'xpToNextTier' => max(0, $ceiling - $xp),
            'tierProgressPercent' => $progress,
        ];
    }

    /**
     * Dynamic prizes & honors matching league duration and official Top-10 certificate eligibility.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPrizesForLeague(?string $durationType = 'monthly', bool $isGeneral = false): array
    {
        $durationEnum = LeagueDuration::tryFrom((string) $durationType);
        $cadenceAr = match ($durationEnum) {
            LeagueDuration::WEEKLY => 'نهاية كل أسبوع',
            LeagueDuration::HALF_YEAR => 'نهاية نصف العام',
            LeagueDuration::FULL_YEAR => 'نهاية العام الدراسي',
            default => $isGeneral ? 'نهاية كل جولة' : 'نهاية الشهر',
        };
        $cadenceEn = match ($durationEnum) {
            LeagueDuration::WEEKLY => 'End of Week',
            LeagueDuration::HALF_YEAR => 'Half-Year',
            LeagueDuration::FULL_YEAR => 'Full-Year',
            default => $isGeneral ? 'End of Round' : 'End of Month',
        };

        return [
            [
                'rankRange' => '1',
                'rankAr' => 'المركز الأول (#1)',
                'rankEn' => '1st Place (#1)',
                'titleAr' => $isGeneral
                    ? 'وسام «بطل الجمهورية» وتصدر منصة التتويج + شهادة تفوق رسمية موثّقة'
                    : 'وسام «بطل الكورس» وتصدر لوحة الشرف + شهادة تفوق معتمدة من المعلم',
                'titleEn' => $isGeneral
                    ? 'National Champion badge, #1 Podium spotlight & verified honor certificate'
                    : 'Course Champion badge, #1 Podium spotlight & teacher honor certificate',
                'cadenceAr' => $cadenceAr,
                'cadenceEn' => $cadenceEn,
            ],
            [
                'rankRange' => '2',
                'rankAr' => 'المركز الثاني (#2)',
                'rankEn' => '2nd Place (#2)',
                'titleAr' => $isGeneral
                    ? 'وسام «وصيف الجمهورية» الفضي + شهادة تقدير موثّقة'
                    : 'وسام «وصيف الكورس» الفضي + تكريم في منصة التتويج',
                'titleEn' => 'Runner-up silver honor badge & podium recognition',
                'cadenceAr' => $cadenceAr,
                'cadenceEn' => $cadenceEn,
            ],
            [
                'rankRange' => '3',
                'rankAr' => 'المركز الثالث (#3)',
                'rankEn' => '3rd Place (#3)',
                'titleAr' => 'وسام «المركز الثالث» البرونزي + تثبيت الاسم في منصة التتويج',
                'titleEn' => '3rd Place bronze honor badge & podium spotlight',
                'cadenceAr' => $cadenceAr,
                'cadenceEn' => $cadenceEn,
            ],
            [
                'rankRange' => '4-10',
                'rankAr' => 'المراكز #4 إلى #10',
                'rankEn' => 'Top 10 (#4 – #10)',
                'titleAr' => 'تفعيل استحقاق «شهادة أبطال الدوري» تلقائياً داخل صفحة الشهادات',
                'titleEn' => 'Automatically unlocks League Champions certificate eligibility',
                'cadenceAr' => $cadenceAr,
                'cadenceEn' => $cadenceEn,
            ],
        ];
    }

    /**
     * Compute real, batched performance metrics for a list of students (zero N+1 queries).
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, array<string, mixed>>
     */
    public function computeMetricsForStudents(array $studentIds, ?int $teacherId = null, ?int $courseId = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
        if (empty($ids)) {
            return [];
        }

        // 1. Profiles (real streak & school name)
        $profiles = StudentProfile::whereIn('student_id', $ids)
            ->get(['student_id', 'current_streak_days', 'school_name', 'center_name', 'governorate'])
            ->keyBy('student_id');

        // 2. Enrollments (real attendance rate & group name)
        $enrollmentQuery = GroupEnrollment::whereIn('student_id', $ids)
            ->where('status', EnrollmentStatus::ENROLLED)
            ->with('group:id,teacher_id,name_ar,center_name');
        if ($teacherId !== null) {
            $enrollmentQuery->whereHas('group', fn ($q) => $q->where('teacher_id', $teacherId));
        }
        $enrollmentsByStudent = $enrollmentQuery->get()->groupBy('student_id');

        // 3. Exam submissions (real solved, passed, full-mark counts)
        $examQuery = ExamSubmission::query()
            ->whereIn('exam_submissions.student_id', $ids)
            ->whereIn('exam_submissions.status', [
                ExamSubmissionStatus::SUBMITTED->value,
                ExamSubmissionStatus::GRADED->value,
            ]);

        if ($teacherId !== null || $courseId !== null) {
            $examQuery->join('exams', 'exams.id', '=', 'exam_submissions.exam_id');
            if ($teacherId !== null) {
                $examQuery->where('exams.teacher_id', $teacherId);
            }
            if ($courseId !== null) {
                $examQuery->where(function ($q) use ($courseId) {
                    $q->where('exams.course_id', $courseId)->orWhereNull('exams.course_id');
                });
            }
        }

        $examAgg = $examQuery
            ->select([
                'exam_submissions.student_id',
                DB::raw('COUNT(*) as exams_solved'),
                DB::raw('SUM(CASE WHEN exam_submissions.is_full_mark = 1 THEN 1 ELSE 0 END) as perfect_exams'),
                DB::raw('SUM(CASE WHEN exam_submissions.is_passed = 1 AND exam_submissions.is_full_mark = 0 THEN 1 ELSE 0 END) as passed_non_perfect'),
            ])
            ->groupBy('exam_submissions.student_id')
            ->get()
            ->keyBy('student_id');

        // 4. Completed lessons
        $lessonQuery = StudentLessonProgress::query()
            ->whereIn('student_lesson_progresses.student_id', $ids)
            ->where('student_lesson_progresses.is_completed', true);

        if ($courseId !== null) {
            $lessonQuery->where('student_lesson_progresses.course_id', $courseId);
        } elseif ($teacherId !== null) {
            $lessonQuery->join('courses', 'courses.id', '=', 'student_lesson_progresses.course_id')
                ->where('courses.teacher_id', $teacherId);
        }

        $lessonAgg = $lessonQuery
            ->select([
                'student_lesson_progresses.student_id',
                DB::raw('COUNT(*) as lessons_completed'),
            ])
            ->groupBy('student_lesson_progresses.student_id')
            ->pluck('lessons_completed', 'student_id');

        // 5. Homeworks (real submitted count & homework rate %)
        $hwBaseQuery = Homework::query();
        if ($teacherId !== null) {
            $hwBaseQuery->where('teacher_id', $teacherId);
        }
        if ($courseId !== null) {
            $hwBaseQuery->where(function ($q) use ($courseId) {
                $q->where('course_id', $courseId)->orWhereNull('course_id');
            });
        }
        $scopedHomeworkIds = $hwBaseQuery->pluck('id');
        $totalScopedHomeworks = $scopedHomeworkIds->count();

        $hwSubmittedAgg = $totalScopedHomeworks > 0
            ? HomeworkSubmission::query()
                ->whereIn('student_id', $ids)
                ->whereIn('homework_id', $scopedHomeworkIds)
                ->select(['student_id', DB::raw('COUNT(DISTINCT homework_id) as hw_submitted')])
                ->groupBy('student_id')
                ->pluck('hw_submitted', 'student_id')
            : collect();

        $result = [];
        foreach ($ids as $sid) {
            /** @var StudentProfile|null $prof */
            $prof = $profiles->get($sid);
            $stdEnrollments = $enrollmentsByStudent->get($sid, collect());
            /** @var GroupEnrollment|null $firstEnrollment */
            $firstEnrollment = $stdEnrollments->first();

            $streakDays = max(
                (int) ($prof?->current_streak_days ?? 0),
                (int) ($stdEnrollments->max('streak_days') ?? 0)
            );
            $attendanceRate = $stdEnrollments->isNotEmpty()
                ? (int) round((float) $stdEnrollments->avg('attendance_rate'))
                : 0;

            $ex = $examAgg->get($sid);
            $examsSolved = (int) ($ex?->exams_solved ?? 0);
            $perfectExams = (int) ($ex?->perfect_exams ?? 0);
            $passedNonPerfect = (int) ($ex?->passed_non_perfect ?? 0);
            $otherExams = max(0, $examsSolved - $perfectExams - $passedNonPerfect);

            $lessonsCompleted = (int) ($lessonAgg[$sid] ?? 0);
            $homeworksSubmitted = (int) ($hwSubmittedAgg[$sid] ?? 0);
            $homeworkRate = $totalScopedHomeworks > 0
                ? (int) min(100, round(($homeworksSubmitted / $totalScopedHomeworks) * 100))
                : 0;

            $ruleXp = ($perfectExams * self::XP_EXAM_FULL_MARK)
                + ($passedNonPerfect * self::XP_EXAM_PASSED)
                + ($otherExams * self::XP_EXAM_PARTICIPATION)
                + ($lessonsCompleted * self::XP_LESSON_COMPLETED)
                + ($homeworksSubmitted * self::XP_HOMEWORK_SUBMITTED)
                + ($streakDays * self::XP_STREAK_PER_DAY);

            $schoolAr = trim((string) ($prof?->school_name ?: $prof?->governorate ?: $firstEnrollment?->group?->center_name ?: ''));
            $groupAr = trim((string) ($firstEnrollment?->group?->name_ar ?: ''));

            $result[$sid] = [
                'streakDays' => $streakDays,
                'attendanceRate' => min(100, max(0, $attendanceRate)),
                'homeworkRate' => $homeworkRate,
                'examsSolved' => $examsSolved,
                'perfectExams' => $perfectExams,
                'lessonsStudied' => $lessonsCompleted,
                'homeworksSubmitted' => $homeworksSubmitted,
                'ruleXp' => $ruleXp,
                'schoolAr' => $schoolAr,
                'groupAr' => $groupAr,
            ];
        }

        return $result;
    }

    /**
     * Automatically credit all matching active course leagues when a student completes an activity.
     */
    public function creditActiveLeagues(User $student, int $xpDelta, ?int $teacherId = null, ?int $courseId = null): void
    {
        if ($xpDelta <= 0 || ($teacherId === null && $courseId === null)) {
            return;
        }

        $now = Carbon::now();
        $activeLeagues = CourseLeague::query()
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->where(function ($q) use ($teacherId, $courseId) {
                if ($teacherId !== null) {
                    $q->where('teacher_id', $teacherId);
                }
                if ($courseId !== null) {
                    $q->where(function ($sub) use ($courseId) {
                        $sub->where('course_id', $courseId)->orWhereNull('course_id');
                    });
                }
            })
            ->get();

        if ($activeLeagues->isEmpty()) {
            return;
        }

        $enrolledGroupIds = GroupEnrollment::where('student_id', $student->id)
            ->where('status', EnrollmentStatus::ENROLLED)
            ->pluck('study_group_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($activeLeagues as $league) {
            if ($league->study_group_id && ! in_array((int) $league->study_group_id, $enrolledGroupIds, true)) {
                continue;
            }

            $metrics = $this->computeMetricsForStudents([$student->id], (int) $league->teacher_id, $league->course_id ? (int) $league->course_id : null)[$student->id] ?? null;

            /** @var LeagueParticipant|null $participant */
            $participant = LeagueParticipant::where('course_league_id', $league->id)
                ->where('student_id', $student->id)
                ->first();

            if ($participant) {
                $newXp = (int) $participant->xp_points + $xpDelta;
                $participant->update([
                    'xp_points' => $newXp,
                    'streak_days' => (int) ($metrics['streakDays'] ?? $participant->streak_days),
                    'exams_solved_count' => (int) ($metrics['examsSolved'] ?? $participant->exams_solved_count),
                    'perfect_exams_count' => (int) ($metrics['perfectExams'] ?? $participant->perfect_exams_count),
                    'homework_rate' => (int) ($metrics['homeworkRate'] ?? $participant->homework_rate),
                    'attendance_rate' => (int) ($metrics['attendanceRate'] ?? $participant->attendance_rate),
                    'school_ar' => $metrics['schoolAr'] ?: $participant->school_ar,
                    'group_ar' => $metrics['groupAr'] ?: $participant->group_ar,
                ]);
            } else {
                $initialXp = max($xpDelta, (int) ($metrics['ruleXp'] ?? $xpDelta));
                LeagueParticipant::create([
                    'course_league_id' => $league->id,
                    'student_id' => $student->id,
                    'rank' => 1,
                    'previous_rank' => null,
                    'xp_points' => $initialXp,
                    'streak_days' => (int) ($metrics['streakDays'] ?? 0),
                    'badge_title_ar' => 'منافس نشط',
                    'tier' => LeagueTier::resolveFromXp($initialXp)->value,
                    'movement' => 'same',
                    'school_ar' => $metrics['schoolAr'] ?: null,
                    'group_ar' => $metrics['groupAr'] ?: $league->group_name_ar,
                    'perfect_exams_count' => (int) ($metrics['perfectExams'] ?? 0),
                    'exams_solved_count' => (int) ($metrics['examsSolved'] ?? 0),
                    'homework_rate' => (int) ($metrics['homeworkRate'] ?? 0),
                    'attendance_rate' => (int) ($metrics['attendanceRate'] ?? 0),
                ]);
            }

            $league->recalculateRanksAndMetrics();
        }
    }

    /**
     * Enforce expiration rules and synchronize live participant metrics on a CourseLeague without clobbering manual bonuses.
     */
    public function syncCourseLeague(CourseLeague $league): CourseLeague
    {
        if ($league->is_active && $league->ends_at && $league->ends_at->isPast()) {
            $league->update(['is_active' => false]);
        }

        $changed = false;

        // Auto-enroll eligible enrolled students who joined after the league started
        if ($league->is_active) {
            $eligibleEnrollments = GroupEnrollment::where('status', EnrollmentStatus::ENROLLED)
                ->whereHas('group', function ($q) use ($league) {
                    $q->where('teacher_id', $league->teacher_id);
                    if ($league->study_group_id) {
                        $q->where('id', $league->study_group_id);
                    }
                })
                ->with('group:id,name_ar')
                ->get();

            $existingStudentIds = LeagueParticipant::where('course_league_id', $league->id)
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $missingEnrollments = $eligibleEnrollments
                ->unique('student_id')
                ->filter(fn (GroupEnrollment $e) => ! in_array((int) $e->student_id, $existingStudentIds, true));

            if ($missingEnrollments->isNotEmpty()) {
                $missingIds = $missingEnrollments->pluck('student_id')->all();
                $metricsMap = $this->computeMetricsForStudents($missingIds, (int) $league->teacher_id, $league->course_id ? (int) $league->course_id : null);

                foreach ($missingEnrollments as $enrollment) {
                    $m = $metricsMap[$enrollment->student_id] ?? null;
                    $initialXp = (int) ($m['ruleXp'] ?? 0);

                    LeagueParticipant::firstOrCreate(
                        [
                            'course_league_id' => $league->id,
                            'student_id' => $enrollment->student_id,
                        ],
                        [
                            'rank' => 1,
                            'previous_rank' => null,
                            'xp_points' => $initialXp,
                            'streak_days' => (int) ($m['streakDays'] ?? $enrollment->streak_days ?? 0),
                            'badge_title_ar' => 'منافس نشط',
                            'tier' => LeagueTier::resolveFromXp($initialXp)->value,
                            'movement' => 'same',
                            'school_ar' => $m['schoolAr'] ?: null,
                            'group_ar' => $enrollment->group?->name_ar ?: $league->group_name_ar,
                            'perfect_exams_count' => (int) ($m['perfectExams'] ?? 0),
                            'exams_solved_count' => (int) ($m['examsSolved'] ?? 0),
                            'homework_rate' => (int) ($m['homeworkRate'] ?? 0),
                            'attendance_rate' => (int) ($m['attendanceRate'] ?? $enrollment->attendance_rate ?? 0),
                        ]
                    );
                    $changed = true;
                }
            }
        }

        // Refresh live study metrics & ensure tier matches current xp_points
        $participants = LeagueParticipant::where('course_league_id', $league->id)->get();
        if ($participants->isNotEmpty()) {
            $metricsMap = $this->computeMetricsForStudents(
                $participants->pluck('student_id')->all(),
                (int) $league->teacher_id,
                $league->course_id ? (int) $league->course_id : null
            );

            foreach ($participants as $p) {
                $m = $metricsMap[(int) $p->student_id] ?? null;
                if (! $m) {
                    continue;
                }

                $updates = [];
                if ((int) $p->streak_days !== (int) $m['streakDays'] && $m['streakDays'] > 0) {
                    $updates['streak_days'] = (int) $m['streakDays'];
                }
                if ((int) $p->exams_solved_count !== (int) $m['examsSolved']) {
                    $updates['exams_solved_count'] = (int) $m['examsSolved'];
                }
                if ((int) $p->perfect_exams_count !== (int) $m['perfectExams']) {
                    $updates['perfect_exams_count'] = (int) $m['perfectExams'];
                }
                if ((int) $p->homework_rate !== (int) $m['homeworkRate']) {
                    $updates['homework_rate'] = (int) $m['homeworkRate'];
                }
                if ((int) $p->attendance_rate !== (int) $m['attendanceRate'] && $m['attendanceRate'] > 0) {
                    $updates['attendance_rate'] = (int) $m['attendanceRate'];
                }
                if ($m['schoolAr'] !== '' && $p->school_ar !== $m['schoolAr']) {
                    $updates['school_ar'] = $m['schoolAr'];
                }
                $expectedTier = LeagueTier::resolveFromXp((int) $p->xp_points)->value;
                if ($p->tier !== $expectedTier) {
                    $updates['tier'] = $expectedTier;
                }
                if ($p->rank === null || $p->rank <= 0) {
                    $changed = true;
                }

                if (! empty($updates)) {
                    $p->update($updates);
                }
            }
        }

        if ($changed || (int) $league->total_competitors !== $participants->count()) {
            $league->recalculateRanksAndMetrics();
        }

        return $league;
    }
}
