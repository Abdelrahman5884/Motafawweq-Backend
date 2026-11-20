<?php

namespace App\Services\V1\Student;

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\RiskLevel;
use App\Enums\RiskSignalStatus;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\ExamSubmissionAnswer;
use App\Models\StudentRiskSignal;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class StudentExamTakingService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService
    ) {}

    /**
     * List all accessible exams for the authenticated student with accurate submission & schedule state.
     */
    public function listExams(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);

        if (empty($entitlements['teacher_ids']) && empty($entitlements['course_ids'])) {
            return [];
        }

        $exams = Exam::query()
            ->whereIn('status', [ExamStatus::ACTIVE, ExamStatus::CLOSED])
            ->where(function ($q) use ($entitlements) {
                if (! empty($entitlements['teacher_ids'])) {
                    $q->whereIn('teacher_id', $entitlements['teacher_ids']);
                }
                if (! empty($entitlements['course_ids'])) {
                    $q->orWhereIn('course_id', $entitlements['course_ids']);
                }
            })
            ->with(['teacher:id,name'])
            ->withCount('questions')
            ->latest()
            ->get();

        if ($exams->isEmpty()) {
            return [];
        }

        $submissionsByExam = ExamSubmission::query()
            ->where('student_id', $student->id)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->get()
            ->keyBy('exam_id');

        $now = Carbon::now();

        return $exams->map(function (Exam $exam) use ($submissionsByExam, $now) {
            /** @var ExamSubmission|null $sub */
            $sub = $submissionsByExam->get($exam->id);
            $subStatus = $sub?->status instanceof ExamSubmissionStatus
                ? $sub->status->value
                : ($sub?->status ?? 'not_started');

            $showResults = (bool) $exam->show_results;
            $canRevealScore = $showResults && $subStatus === ExamSubmissionStatus::GRADED->value;
            $isUpcoming = $exam->starts_at && $exam->starts_at->greaterThan($now);
            $remainingSeconds = null;
            if ($sub && $subStatus === ExamSubmissionStatus::IN_PROGRESS->value && $sub->expires_at) {
                $remainingSeconds = max(0, (int) $now->diffInSeconds($sub->expires_at, false));
            }

            return [
                'id' => $exam->uuid,
                'uuid' => $exam->uuid,
                'titleAr' => $exam->title_ar,
                'titleEn' => $exam->title_en ?? $exam->title_ar,
                'courseTitleAr' => $exam->course_title_ar,
                'teacherName' => $exam->teacher?->name ?? '',
                'durationMinutes' => (int) $exam->duration_minutes,
                'fullMark' => (int) $exam->full_mark,
                'passScore' => (int) $exam->pass_score,
                'questionsCount' => max((int) ($exam->questions_count ?? 0), (int) $exam->questions_count),
                'status' => $exam->status instanceof ExamStatus ? $exam->status->value : (string) $exam->status,
                'isUpcoming' => (bool) $isUpcoming,
                'isBubbleSheet' => (bool) $exam->is_bubble_sheet,
                'showResults' => $showResults,
                'startsAt' => $exam->starts_at?->toIso8601String(),
                'dueDate' => $exam->due_date?->format('Y-m-d'),
                'submissionStatus' => $subStatus,
                'remainingSeconds' => $remainingSeconds,
                'submittedAt' => $sub?->submitted_at?->toIso8601String(),
                'score' => $canRevealScore ? (float) $sub->score : null,
                'percentage' => $canRevealScore ? (float) $sub->percentage : null,
                'isPassed' => $canRevealScore ? (bool) $sub->is_passed : null,
                'isFullMark' => $canRevealScore ? (bool) $sub->is_full_mark : null,
            ];
        })->values()->all();
    }

    /**
     * Retrieve the authenticated student's completed/submitted result for an exam.
     */
    public function getExamResult(User $student, string $examUuid): ExamSubmission
    {
        /** @var Exam|null $exam */
        $exam = Exam::where('uuid', $examUuid)->first();
        if (! $exam) {
            throw (new ModelNotFoundException)->setModel(Exam::class, [$examUuid]);
        }

        $this->accessService->assertCanAccess($student, $exam, (int) $exam->teacher_id, $exam->course_id ? (int) $exam->course_id : null);

        /** @var ExamSubmission|null $submission */
        $submission = ExamSubmission::where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->whereIn('status', [ExamSubmissionStatus::SUBMITTED, ExamSubmissionStatus::GRADED])
            ->with(['exam', 'answers.question'])
            ->first();

        if (! $submission) {
            throw (new ModelNotFoundException)->setModel(ExamSubmission::class, [$examUuid]);
        }

        return $submission;
    }

    /**
     * Start or resume a server-timed exam session and return questions without correct answers.
     */
    public function startExam(User $student, string $examUuid): array
    {
        /** @var Exam|null $exam */
        $exam = Exam::where('uuid', $examUuid)->with('questions')->first();
        if (! $exam) {
            throw (new ModelNotFoundException)->setModel(Exam::class, [$examUuid]);
        }

        $this->accessService->assertCanAccess($student, $exam, (int) $exam->teacher_id, $exam->course_id ? (int) $exam->course_id : null);

        if ($exam->status !== ExamStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'exam' => [__('messages.exam_closed_for_submission')],
            ]);
        }

        if ($exam->starts_at && $exam->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'exam' => ['لم يحن موعد بدء هذا الامتحان بعد.'],
            ]);
        }

        if ($exam->questions->isEmpty()) {
            throw ValidationException::withMessages([
                'exam' => ['لا توجد أسئلة مضافة لهذا الامتحان بعد. يرجى التواصل مع المعلم.'],
            ]);
        }

        $expiredFinalized = false;
        $attempt = $this->transaction(function () use ($student, $exam, &$expiredFinalized) {
            /** @var ExamSubmission|null $existing */
            $existing = ExamSubmission::where('exam_id', $exam->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($existing && in_array($existing->status, [ExamSubmissionStatus::SUBMITTED, ExamSubmissionStatus::GRADED], true)) {
                throw ValidationException::withMessages([
                    'exam' => [__('messages.exam_already_submitted')],
                ]);
            }

            if ($existing) {
                $now = Carbon::now();
                // If an earlier attempt already expired beyond the grace window, commit auto-finalization cleanly
                if ($existing->expires_at && $now->greaterThan($existing->expires_at->copy()->addSeconds(60))) {
                    $existing->update([
                        'submitted_at' => $existing->expires_at,
                        'score' => 0,
                        'percentage' => 0,
                        'is_passed' => false,
                        'is_full_mark' => false,
                        'status' => ExamSubmissionStatus::GRADED,
                        'graded_at' => $now,
                    ]);
                    $exam->recalculatePerformanceMetrics();
                    $expiredFinalized = true;

                    return $existing;
                }

                return $existing;
            }

            $now = Carbon::now();
            $duration = max(5, (int) ($exam->duration_minutes ?: 30));

            return ExamSubmission::create([
                'exam_id' => $exam->id,
                'student_id' => $student->id,
                'started_at' => $now,
                'expires_at' => $now->copy()->addMinutes($duration),
                'full_mark' => (int) $exam->full_mark,
                'status' => ExamSubmissionStatus::IN_PROGRESS,
                'ip_address' => request()?->ip(),
            ]);
        });

        if ($expiredFinalized) {
            throw ValidationException::withMessages([
                'exam' => ['انتهى الوقت المحدد لمحاولتك السابقة في هذا الامتحان وتم إغلاقها.'],
            ]);
        }

        // Strip correct answers and explanations from questions returned during active exam
        $sanitizedQuestions = $exam->questions->map(fn (ExamQuestion $q) => [
            'id' => $q->uuid,
            'uuid' => $q->uuid,
            'questionNumber' => (int) $q->question_number,
            'type' => $q->type instanceof ExamQuestionType ? $q->type->value : (string) $q->type,
            'points' => (int) $q->points,
            'difficulty' => $q->difficulty ?: 'medium',
            'questionAr' => $q->question_ar,
            'questionEn' => $q->question_en ?: $q->question_ar,
            'optionsAr' => is_array($q->options_ar) ? array_values($q->options_ar) : [],
        ])->values()->all();

        $remainingSeconds = $attempt->expires_at
            ? max(0, (int) Carbon::now()->diffInSeconds($attempt->expires_at, false))
            : ((int) $exam->duration_minutes * 60);

        return [
            'attemptId' => $attempt->uuid,
            'examId' => $exam->uuid,
            'titleAr' => $exam->title_ar,
            'courseTitleAr' => $exam->course_title_ar,
            'durationMinutes' => (int) $exam->duration_minutes,
            'fullMark' => (int) $exam->full_mark,
            'passScore' => (int) $exam->pass_score,
            'showResults' => (bool) $exam->show_results,
            'startedAt' => $attempt->started_at?->toIso8601String(),
            'expiresAt' => $attempt->expires_at?->toIso8601String(),
            'remainingSeconds' => $remainingSeconds,
            'questions' => $sanitizedQuestions,
        ];
    }

    /**
     * Process student exam submission, enforce server-side timing & locks, auto-grade objective questions, and award XP.
     */
    public function submitExam(User $student, string $examUuid, array $data): ExamSubmission
    {
        /** @var Exam|null $exam */
        $exam = Exam::where('uuid', $examUuid)
            ->with('questions')
            ->first();

        if (! $exam) {
            throw (new ModelNotFoundException)->setModel(Exam::class, [$examUuid]);
        }

        $this->accessService->assertCanAccess($student, $exam, (int) $exam->teacher_id, $exam->course_id ? (int) $exam->course_id : null);

        if ($exam->status !== ExamStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'exam' => [__('messages.exam_closed_for_submission')],
            ]);
        }

        return $this->transaction(function () use ($student, $exam, $data) {
            /** @var ExamSubmission|null $existing */
            $existing = ExamSubmission::where('exam_id', $exam->id)
                ->where('student_id', $student->id)
                ->lockForUpdate()
                ->first();

            if ($existing && in_array($existing->status, [ExamSubmissionStatus::SUBMITTED, ExamSubmissionStatus::GRADED], true)) {
                throw ValidationException::withMessages([
                    'exam' => [__('messages.exam_already_submitted')],
                ]);
            }

            $now = Carbon::now();
            $durationMinutes = max(5, (int) ($exam->duration_minutes ?: 30));

            if ($existing && $existing->started_at) {
                $startedAt = $existing->started_at;
                if ($existing->expires_at && $now->greaterThan($existing->expires_at->copy()->addSeconds(60))) {
                    throw ValidationException::withMessages([
                        'exam' => ['انتهى الوقت المحدد للامتحان ولا يمكن قبول التسليم المتأخر.'],
                    ]);
                }
            } else {
                // Clamp client-provided started_at to a valid server-bounded window
                $earliestAllowed = $now->copy()->subMinutes($durationMinutes + 1);
                $clientStarted = ! empty($data['started_at']) ? Carbon::parse($data['started_at']) : $now;
                if ($clientStarted->greaterThan($now)) {
                    $startedAt = $now;
                } elseif ($clientStarted->lessThan($earliestAllowed)) {
                    throw ValidationException::withMessages([
                        'exam' => ['انتهى الوقت المحدد للامتحان ولا يمكن قبول التسليم المتأخر.'],
                    ]);
                } else {
                    $startedAt = $clientStarted;
                }
            }

            $tabSwitches = max(0, (int) ($data['tab_switches_count'] ?? 0));

            if ($existing) {
                $submission = $existing;
                $submission->update([
                    'submitted_at' => $now,
                    'full_mark' => (int) $exam->full_mark,
                    'tab_switches_count' => $tabSwitches,
                    'ip_address' => request()?->ip() ?? $submission->ip_address,
                    'status' => ExamSubmissionStatus::SUBMITTED,
                ]);
                $submission->answers()->delete();
            } else {
                $submission = ExamSubmission::create([
                    'exam_id' => $exam->id,
                    'student_id' => $student->id,
                    'started_at' => $startedAt,
                    'expires_at' => $startedAt->copy()->addMinutes($durationMinutes),
                    'submitted_at' => $now,
                    'full_mark' => (int) $exam->full_mark,
                    'tab_switches_count' => $tabSwitches,
                    'ip_address' => request()?->ip(),
                    'status' => ExamSubmissionStatus::SUBMITTED,
                ]);
            }

            $submittedAnswers = collect($data['answers'] ?? []);
            $hasEssayQuestions = false;
            $totalScore = 0.0;

            /** @var ExamQuestion $question */
            foreach ($exam->questions as $question) {
                $qUuid = $question->uuid;

                $studentAnswer = $submittedAnswers->first(function ($item) use ($qUuid, $question) {
                    $itemUuid = $item['question_uuid'] ?? $item['questionUuid'] ?? $item['exam_question_uuid'] ?? null;

                    return $itemUuid === $qUuid || (isset($item['question_id']) && (int) $item['question_id'] === $question->id);
                });

                $selectedIndex = isset($studentAnswer['selected_index']) ? (int) $studentAnswer['selected_index'] : null;
                $selectedBool = array_key_exists('selected_bool', (array) $studentAnswer) && $studentAnswer['selected_bool'] !== null
                    ? (bool) $studentAnswer['selected_bool']
                    : null;
                $essayText = isset($studentAnswer['essay_answer_text']) ? trim((string) $studentAnswer['essay_answer_text']) : null;

                $isCorrect = null;
                $pointsAwarded = 0.0;

                if ($question->type === ExamQuestionType::MCQ) {
                    $isCorrect = $selectedIndex !== null && $selectedIndex === (int) $question->correct_index;
                    $pointsAwarded = $isCorrect ? (float) $question->points : 0.0;
                } elseif ($question->type === ExamQuestionType::TRUE_FALSE) {
                    $isCorrect = $selectedBool !== null && $selectedBool === (bool) $question->correct_bool;
                    $pointsAwarded = $isCorrect ? (float) $question->points : 0.0;
                } elseif ($question->type === ExamQuestionType::ESSAY) {
                    $hasEssayQuestions = true;
                    $isCorrect = null;
                    $pointsAwarded = 0.0;
                }

                $totalScore += $pointsAwarded;

                ExamSubmissionAnswer::create([
                    'exam_submission_id' => $submission->id,
                    'exam_question_id' => $question->id,
                    'selected_index' => $selectedIndex,
                    'selected_bool' => $selectedBool,
                    'essay_answer_text' => $essayText,
                    'is_correct' => $isCorrect,
                    'points_awarded' => $pointsAwarded,
                ]);
            }

            $finalStatus = $hasEssayQuestions
                ? ExamSubmissionStatus::SUBMITTED
                : ExamSubmissionStatus::GRADED;

            $fullMark = $exam->full_mark > 0 ? (int) $exam->full_mark : 50;
            $percentage = $fullMark > 0 ? round(($totalScore / $fullMark) * 100, 2) : 0.00;
            $passScore = (int) ($exam->pass_score ?? 25);

            $submission->update([
                'score' => $totalScore,
                'percentage' => $percentage,
                'is_passed' => $totalScore >= $passScore,
                'is_full_mark' => $totalScore >= $fullMark && $fullMark > 0,
                'status' => $finalStatus,
                'graded_at' => $hasEssayQuestions ? null : Carbon::now(),
            ]);

            $exam->recalculateMetrics();
            $exam->recalculatePerformanceMetrics();

            // Award immutable XP for completing the exam
            $xpPoints = $submission->is_full_mark ? 50 : ($submission->is_passed ? 30 : 10);
            $xpEntry = StudentXpLedger::awardOnce(
                $student->id,
                'exam_submitted',
                $submission->id,
                $xpPoints,
                "إتمام امتحان: {$exam->title_ar}"
            );

            // Log anti-cheat risk signal if abnormal tab switching occurred
            if ($tabSwitches >= 5) {
                StudentRiskSignal::create([
                    'teacher_id' => $exam->teacher_id,
                    'student_id' => $student->id,
                    'risk_level' => RiskLevel::HIGH,
                    'risk_reasons_ar' => "تبديل نافذة الامتحان ({$tabSwitches} مرات) أثناء أداء «{$exam->title_ar}»",
                    'status' => RiskSignalStatus::DETECTED,
                ]);
            }

            $fresh = $submission->fresh(['exam', 'answers.question']);
            $fresh->xp_awarded_runtime = $xpEntry ? (int) $xpEntry->points : 0;

            return $fresh;
        });
    }

    /**
     * Retrieve the student's mistake bank across graded exams where show_results is enabled.
     */
    public function getMistakeBank(User $student): array
    {
        $answers = ExamSubmissionAnswer::query()
            ->where('is_correct', false)
            ->whereHas('submission', function ($q) use ($student) {
                $q->where('student_id', $student->id)
                    ->where('status', ExamSubmissionStatus::GRADED)
                    ->whereHas('exam', fn ($eq) => $eq->where('show_results', true));
            })
            ->with(['question', 'submission.exam'])
            ->latest()
            ->limit(100)
            ->get();

        return $answers->map(function (ExamSubmissionAnswer $ans) {
            $q = $ans->question;
            $exam = $ans->submission?->exam;

            return [
                'id' => $ans->uuid,
                'examId' => $exam?->uuid,
                'examTitleAr' => $exam?->title_ar,
                'questionId' => $q?->uuid,
                'questionAr' => $q?->question_ar,
                'type' => $q?->type instanceof ExamQuestionType ? $q->type->value : (string) $q?->type,
                'optionsAr' => $q?->options_ar ?? [],
                'selectedIndex' => $ans->selected_index,
                'selectedBool' => $ans->selected_bool,
                'correctIndex' => $q?->correct_index,
                'correctBool' => $q?->correct_bool,
                'explanationAr' => $q?->explanation_ar,
            ];
        })->values()->all();
    }
}
