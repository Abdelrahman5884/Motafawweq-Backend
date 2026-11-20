<?php

namespace App\Services\V1;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamQuestionType;
use App\Enums\ExamSubmissionStatus;
use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Models\CourseLeague;
use App\Models\ExamSubmission;
use App\Models\ExamSubmissionAnswer;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\LeagueParticipant;
use App\Models\StudentDailyActivity;
use App\Models\StudentLessonProgress;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for Motafawweq League scoring rules, tier thresholds,
 * live activity-to-league point synchronization, and authoritative participant metrics.
 *
 * Official League Scoring Rules:
 * - Daily Streak:            +3 pts per consecutive day
 * - Exam Full Mark (100%):   +3 bonus pts
 * - Correct True/False Q:    +1 pt
 * - Correct MCQ Question:    +3 pts
 * - Correct Essay Question:  +5 pts
 */
class LeagueRulesService
{
    public const XP_STREAK_PER_DAY = 3;

    public const XP_EXAM_FULL_MARK_BONUS = 3;

    public const XP_QUESTION_TRUE_FALSE = 1;

    public const XP_QUESTION_MCQ = 3;

    public const XP_QUESTION_ESSAY = 5;

    /**
     * Resolve exact league points for a single correctly answered question by its type.
     */
    public function resolveQuestionXp(ExamQuestionType|string|null $type): int
    {
        $val = $type instanceof ExamQuestionType ? $type->value : strtolower((string) $type);

        return match ($val) {
            ExamQuestionType::TRUE_FALSE->value => self::XP_QUESTION_TRUE_FALSE,
            ExamQuestionType::MCQ->value => self::XP_QUESTION_MCQ,
            ExamQuestionType::ESSAY->value => self::XP_QUESTION_ESSAY,
            default => self::XP_QUESTION_TRUE_FALSE,
        };
    }

    /**
     * Calculate exact league points earned from an ExamSubmission's graded answers + full-mark bonus.
     *
     * @return array{questionPoints: int, fullMarkBonus: int, totalPoints: int, correctTrueFalse: int, correctMcq: int, correctEssay: int}
     */
    public function calculateExamSubmissionPoints(ExamSubmission $submission, bool $includeEssay = true): array
    {
        $answers = $submission->relationLoaded('answers')
            ? $submission->answers
            : $submission->answers()->with('question:id,type')->get();

        $correctTrueFalse = 0;
        $correctMcq = 0;
        $correctEssay = 0;
        $questionPoints = 0;

        foreach ($answers as $ans) {
            /** @var ExamSubmissionAnswer $ans */
            if ($ans->is_correct !== true) {
                continue;
            }

            $qType = $ans->question?->type instanceof ExamQuestionType
                ? $ans->question->type->value
                : strtolower((string) ($ans->question?->type ?? 'mcq'));

            if ($qType === ExamQuestionType::ESSAY->value) {
                if (! $includeEssay) {
                    continue;
                }
                $correctEssay++;
                $questionPoints += self::XP_QUESTION_ESSAY;
            } elseif ($qType === ExamQuestionType::TRUE_FALSE->value) {
                $correctTrueFalse++;
                $questionPoints += self::XP_QUESTION_TRUE_FALSE;
            } else {
                $correctMcq++;
                $questionPoints += self::XP_QUESTION_MCQ;
            }
        }

        $isGraded = ($submission->status instanceof ExamSubmissionStatus
            ? $submission->status
            : ExamSubmissionStatus::tryFrom((string) $submission->status)) === ExamSubmissionStatus::GRADED;

        $fullMarkBonus = ($isGraded && (bool) $submission->is_full_mark)
            ? self::XP_EXAM_FULL_MARK_BONUS
            : 0;

        return [
            'questionPoints' => $questionPoints,
            'fullMarkBonus' => $fullMarkBonus,
            'totalPoints' => $questionPoints + $fullMarkBonus,
            'correctTrueFalse' => $correctTrueFalse,
            'correctMcq' => $correctMcq,
            'correctEssay' => $correctEssay,
        ];
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
                'id' => 'question-essay',
                'points' => self::XP_QUESTION_ESSAY,
                'pointsLabelAr' => '+'.self::XP_QUESTION_ESSAY.' نقاط',
                'pointsLabelEn' => '+'.self::XP_QUESTION_ESSAY.' pts',
                'titleAr' => 'السؤال المقالي الصحيح',
                'titleEn' => 'Correct Essay Question',
                'descAr' => 'تحصل على '.self::XP_QUESTION_ESSAY.' نقاط عن كل سؤال مقالي تجيب عنه إجابة صحيحة كاملة بعد تصحيح المعلم.',
                'descEn' => 'Earn '.self::XP_QUESTION_ESSAY.' points for every essay question graded fully correct by the teacher.',
            ],
            [
                'id' => 'question-mcq',
                'points' => self::XP_QUESTION_MCQ,
                'pointsLabelAr' => '+'.self::XP_QUESTION_MCQ.' نقاط',
                'pointsLabelEn' => '+'.self::XP_QUESTION_MCQ.' pts',
                'titleAr' => 'السؤال الاختياري الصحيح (MCQ)',
                'titleEn' => 'Correct Multiple-Choice Question',
                'descAr' => 'تحصل على '.self::XP_QUESTION_MCQ.' نقاط تلقائياً عن كل سؤال اختيار من متعدد تجيب عنه إجابة صحيحة في الامتحان.',
                'descEn' => 'Earn '.self::XP_QUESTION_MCQ.' points automatically for every correct multiple-choice question.',
            ],
            [
                'id' => 'question-true-false',
                'points' => self::XP_QUESTION_TRUE_FALSE,
                'pointsLabelAr' => '+'.self::XP_QUESTION_TRUE_FALSE.' نقطة',
                'pointsLabelEn' => '+'.self::XP_QUESTION_TRUE_FALSE.' pt',
                'titleAr' => 'سؤال الصواب والخطأ الصحيح',
                'titleEn' => 'Correct True / False Question',
                'descAr' => 'تحصل على نقطة واحدة (+'.self::XP_QUESTION_TRUE_FALSE.') عن كل سؤال صواب وخطأ تجيب عنه إجابة صحيحة في الامتحان.',
                'descEn' => 'Earn +'.self::XP_QUESTION_TRUE_FALSE.' point for every correct True/False question in any exam.',
            ],
            [
                'id' => 'exam-full-mark',
                'points' => self::XP_EXAM_FULL_MARK_BONUS,
                'pointsLabelAr' => '+'.self::XP_EXAM_FULL_MARK_BONUS.' نقاط بونص',
                'pointsLabelEn' => '+'.self::XP_EXAM_FULL_MARK_BONUS.' bonus pts',
                'titleAr' => 'تقفيل الامتحان (Full Mark 100%)',
                'titleEn' => 'Exam Full Mark Bonus (100%)',
                'descAr' => 'عند تحقيق الدرجة النهائية الكاملة 100% في الامتحان تحصل على +'.self::XP_EXAM_FULL_MARK_BONUS.' نقاط بونص إضافية فوق نقاط الأسئلة.',
                'descEn' => 'Scoring 100% full mark on an exam awards +'.self::XP_EXAM_FULL_MARK_BONUS.' bonus points on top of question points.',
            ],
            [
                'id' => 'daily-streak',
                'points' => self::XP_STREAK_PER_DAY,
                'pointsLabelAr' => '+'.self::XP_STREAK_PER_DAY.' نقاط / يوم',
                'pointsLabelEn' => '+'.self::XP_STREAK_PER_DAY.' pts / day',
                'titleAr' => 'الاستريك اليومي (الاستمرار اليومي)',
                'titleEn' => 'Daily Streak Bonus',
                'descAr' => 'كل يوم متواصل تفتح فيه المنصة يضيف '.self::XP_STREAK_PER_DAY.' نقاط إلى رصيدك في الدوري ويكسر التعادل في المراكز.',
                'descEn' => 'Every consecutive active day adds '.self::XP_STREAK_PER_DAY.' points to your league score and breaks rank ties.',
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
     * Resolve next tier progression info for a given score.
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
     * Compute real, batched performance metrics and rule-based league points for a list of students (zero N+1).
     * When $since is provided, ONLY activities occurring on or after $since (and before $until) are counted!
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, array<string, mixed>>
     */
    public function computeMetricsForStudents(
        array $studentIds,
        ?int $teacherId = null,
        ?int $courseId = null,
        ?Carbon $since = null,
        ?Carbon $until = null
    ): array {
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

        // 3. Exam submissions (real solved & full-mark counts within optional league window)
        $examQuery = ExamSubmission::query()
            ->whereIn('exam_submissions.student_id', $ids)
            ->whereIn('exam_submissions.status', [
                ExamSubmissionStatus::SUBMITTED->value,
                ExamSubmissionStatus::GRADED->value,
            ]);

        if ($since !== null) {
            $examQuery->where('exam_submissions.submitted_at', '>=', $since);
        }
        if ($until !== null) {
            $examQuery->where('exam_submissions.submitted_at', '<=', $until);
        }

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
                DB::raw("SUM(CASE WHEN exam_submissions.status = 'graded' AND exam_submissions.is_full_mark = 1 THEN 1 ELSE 0 END) as perfect_exams"),
            ])
            ->groupBy('exam_submissions.student_id')
            ->get()
            ->keyBy('student_id');

        // 4. Correct exam answers broken down by question type (True/False = 1, MCQ = 3, Essay = 5)
        $answersQuery = ExamSubmissionAnswer::query()
            ->join('exam_submissions', 'exam_submissions.id', '=', 'exam_submission_answers.exam_submission_id')
            ->join('exam_questions', 'exam_questions.id', '=', 'exam_submission_answers.exam_question_id')
            ->whereIn('exam_submissions.student_id', $ids)
            ->whereIn('exam_submissions.status', [
                ExamSubmissionStatus::SUBMITTED->value,
                ExamSubmissionStatus::GRADED->value,
            ])
            ->where('exam_submission_answers.is_correct', true);

        if ($since !== null) {
            $answersQuery->where('exam_submissions.submitted_at', '>=', $since);
        }
        if ($until !== null) {
            $answersQuery->where('exam_submissions.submitted_at', '<=', $until);
        }

        if ($teacherId !== null || $courseId !== null) {
            $answersQuery->join('exams', 'exams.id', '=', 'exam_submissions.exam_id');
            if ($teacherId !== null) {
                $answersQuery->where('exams.teacher_id', $teacherId);
            }
            if ($courseId !== null) {
                $answersQuery->where(function ($q) use ($courseId) {
                    $q->where('exams.course_id', $courseId)->orWhereNull('exams.course_id');
                });
            }
        }

        $answersAgg = $answersQuery
            ->select([
                'exam_submissions.student_id',
                DB::raw("SUM(CASE WHEN exam_questions.type = 'true_false' THEN 1 ELSE 0 END) as correct_tf"),
                DB::raw("SUM(CASE WHEN exam_questions.type = 'mcq' THEN 1 ELSE 0 END) as correct_mcq"),
                DB::raw("SUM(CASE WHEN exam_questions.type = 'essay' THEN 1 ELSE 0 END) as correct_essay"),
            ])
            ->groupBy('exam_submissions.student_id')
            ->get()
            ->keyBy('student_id');

        // 5. Daily active streak days strictly inside the league window (when $since is set)
        $windowActiveDaysByStudent = collect();
        if ($since !== null) {
            $actQuery = StudentDailyActivity::query()
                ->whereIn('student_id', $ids)
                ->where('first_seen_at', '>=', $since);
            if ($until !== null) {
                $actQuery->where('first_seen_at', '<=', $until);
            }
            $windowActiveDaysByStudent = $actQuery
                ->select(['student_id', DB::raw('COUNT(DISTINCT activity_date) as active_days')])
                ->groupBy('student_id')
                ->pluck('active_days', 'student_id');
        }

        // 6. Completed lessons (for student profile display metrics)
        $lessonQuery = StudentLessonProgress::query()
            ->whereIn('student_lesson_progresses.student_id', $ids)
            ->where('student_lesson_progresses.is_completed', true);

        if ($since !== null) {
            $lessonQuery->where('student_lesson_progresses.completed_at', '>=', $since);
        }
        if ($until !== null) {
            $lessonQuery->where('student_lesson_progresses.completed_at', '<=', $until);
        }

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

        // 7. Homeworks (for student profile display metrics)
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
                ->when($since !== null, fn ($q) => $q->where('submitted_at', '>=', $since))
                ->when($until !== null, fn ($q) => $q->where('submitted_at', '<=', $until))
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
            $windowStreakDays = $since !== null
                ? min($streakDays, (int) ($windowActiveDaysByStudent[$sid] ?? 0))
                : $streakDays;

            $attendanceRate = $stdEnrollments->isNotEmpty()
                ? (int) round((float) $stdEnrollments->avg('attendance_rate'))
                : 0;

            $ex = $examAgg->get($sid);
            $examsSolved = (int) ($ex?->exams_solved ?? 0);
            $perfectExams = (int) ($ex?->perfect_exams ?? 0);

            $ans = $answersAgg->get($sid);
            $correctTf = (int) ($ans?->correct_tf ?? 0);
            $correctMcq = (int) ($ans?->correct_mcq ?? 0);
            $correctEssay = (int) ($ans?->correct_essay ?? 0);

            $lessonsCompleted = (int) ($lessonAgg[$sid] ?? 0);
            $homeworksSubmitted = (int) ($hwSubmittedAgg[$sid] ?? 0);
            $homeworkRate = $totalScopedHomeworks > 0
                ? (int) min(100, round(($homeworksSubmitted / $totalScopedHomeworks) * 100))
                : 0;

            // Exact Official League Formula (strictly within league window when $since is set):
            // True/False (+1) + MCQ (+3) + Essay (+5) + Full Mark Bonus (+3) + Daily Streak (+3/day)
            $ruleXp = ($correctTf * self::XP_QUESTION_TRUE_FALSE)
                + ($correctMcq * self::XP_QUESTION_MCQ)
                + ($correctEssay * self::XP_QUESTION_ESSAY)
                + ($perfectExams * self::XP_EXAM_FULL_MARK_BONUS)
                + ($windowStreakDays * self::XP_STREAK_PER_DAY);

            $schoolAr = trim((string) ($prof?->school_name ?: $prof?->governorate ?: $firstEnrollment?->group?->center_name ?: ''));
            $groupAr = trim((string) ($firstEnrollment?->group?->name_ar ?: ''));

            $result[$sid] = [
                'streakDays' => $streakDays,
                'windowStreakDays' => $windowStreakDays,
                'attendanceRate' => min(100, max(0, $attendanceRate)),
                'homeworkRate' => $homeworkRate,
                'examsSolved' => $examsSolved,
                'perfectExams' => $perfectExams,
                'correctTrueFalse' => $correctTf,
                'correctMcq' => $correctMcq,
                'correctEssay' => $correctEssay,
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
     * Automatically credit all matching active course leagues when a student earns exam points.
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
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
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

            $metrics = $this->computeMetricsForStudents(
                [$student->id],
                (int) $league->teacher_id,
                $league->course_id ? (int) $league->course_id : null,
                $league->starts_at,
                $league->ends_at
            )[$student->id] ?? null;

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
                // First activity in this league starts cleanly from $xpDelta (no pre-league points carried over!)
                LeagueParticipant::create([
                    'course_league_id' => $league->id,
                    'student_id' => $student->id,
                    'rank' => 1,
                    'previous_rank' => null,
                    'xp_points' => $xpDelta,
                    'streak_days' => (int) ($metrics['streakDays'] ?? 0),
                    'badge_title_ar' => 'منافس نشط',
                    'tier' => LeagueTier::resolveFromXp($xpDelta)->value,
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
     * Credit daily streak delta (+3 pts per newly gained streak day) across a student's active course leagues.
     */
    public function syncStudentStreakToActiveLeagues(int $studentId, int $newStreakDays): void
    {
        $now = Carbon::now();
        $participations = LeagueParticipant::where('student_id', $studentId)
            ->with('league')
            ->get();

        foreach ($participations as $participant) {
            /** @var CourseLeague|null $league */
            $league = $participant->league;
            $oldStreak = (int) $participant->streak_days;

            if ($oldStreak === $newStreakDays) {
                continue;
            }

            $isLeagueActive = $league
                && $league->is_active
                && (! $league->starts_at || $league->starts_at->lte($now))
                && (! $league->ends_at || $league->ends_at->gte($now));

            $deltaDays = $newStreakDays - $oldStreak;
            $updates = ['streak_days' => $newStreakDays];

            if ($isLeagueActive && $deltaDays > 0) {
                $updates['xp_points'] = (int) $participant->xp_points + ($deltaDays * self::XP_STREAK_PER_DAY);
            }

            $participant->update($updates);

            if ($isLeagueActive && $deltaDays > 0) {
                $league->recalculateRanksAndMetrics();
            }
        }
    }

    /**
     * Enforce expiration rules and synchronize live participant metrics on a CourseLeague.
     * Newly enrolled students ALWAYS start at 0 points (strictly fresh-start per league).
     */
    public function syncCourseLeague(CourseLeague $league): CourseLeague
    {
        if ($league->is_active && $league->ends_at && $league->ends_at->isPast()) {
            $league->update(['is_active' => false]);
        }

        $changed = false;

        // Auto-enroll eligible enrolled students who joined after the league started (starting at 0 points!)
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
                $metricsMap = $this->computeMetricsForStudents(
                    $missingIds,
                    (int) $league->teacher_id,
                    $league->course_id ? (int) $league->course_id : null,
                    $league->starts_at,
                    $league->ends_at
                );

                foreach ($missingEnrollments as $enrollment) {
                    $m = $metricsMap[$enrollment->student_id] ?? null;

                    LeagueParticipant::firstOrCreate(
                        [
                            'course_league_id' => $league->id,
                            'student_id' => $enrollment->student_id,
                        ],
                        [
                            'rank' => 1,
                            'previous_rank' => null,
                            'xp_points' => 0,
                            'streak_days' => (int) ($m['streakDays'] ?? $enrollment->streak_days ?? 0),
                            'badge_title_ar' => 'منافس نشط',
                            'tier' => LeagueTier::BRONZE->value,
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

        // Refresh live study metrics scoped to this league's window & ensure tier matches xp_points
        $participants = LeagueParticipant::where('course_league_id', $league->id)->get();
        if ($participants->isNotEmpty()) {
            $metricsMap = $this->computeMetricsForStudents(
                $participants->pluck('student_id')->all(),
                (int) $league->teacher_id,
                $league->course_id ? (int) $league->course_id : null,
                $league->starts_at,
                $league->ends_at
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
