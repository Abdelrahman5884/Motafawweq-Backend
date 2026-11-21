<?php

namespace App\Services\V1\Teacher;

use App\Enums\ExamQuestionType;
use App\Enums\ExamSubmissionStatus;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\ExamSubmissionRepositoryInterface;
use App\Services\BaseService;
use App\Services\V1\LeagueRulesService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class TeacherExamGradingService extends BaseService
{
    public function __construct(
        protected ExamRepositoryInterface $examRepository,
        protected ExamSubmissionRepositoryInterface $submissionRepository,
        protected LeagueRulesService $leagueRulesService
    ) {}

    /**
     * List all submissions for an exam owned by the teacher with stats and search filters.
     *
     * @return array{exam: Exam, submissions: Collection}
     */
    public function listSubmissions(User $teacher, string $examUuid, array $filters = []): array
    {
        $exam = $this->examRepository->findByUuidForTeacher($examUuid, $teacher);
        $submissions = $this->submissionRepository->getSubmissionsForExam($exam->id, $filters);

        return [
            'exam' => $exam,
            'submissions' => $submissions,
            'pendingCount' => (int) ($exam->getAttribute('pending_grading_count') ?? 0),
            'gradedCount' => (int) ($exam->getAttribute('graded_submissions_count') ?? 0),
        ];
    }

    /**
     * Retrieve single submission details with all student answers sorted by question order, model answers, and rubric.
     */
    public function getSubmission(User $teacher, string $examUuid, string $submissionUuid): ExamSubmission
    {
        $exam = $this->examRepository->findByUuidForTeacher($examUuid, $teacher);
        $submission = $this->submissionRepository->findByUuidForExam($submissionUuid, $exam->id);
        $submission->loadMissing('exam');

        if ($submission->relationLoaded('answers')) {
            $sorted = $submission->answers
                ->sortBy(fn ($ans) => (int) ($ans->question?->question_number ?? 9999))
                ->values();
            $submission->setRelation('answers', $sorted);
        }

        return $submission;
    }

    /**
     * Grade essay questions, record teacher feedback, approve/finalize the submission,
     * and update total score, league XP & exam metrics atomically.
     */
    public function gradeEssaySubmission(User $teacher, string $examUuid, string $submissionUuid, array $data): ExamSubmission
    {
        // Anti-IDOR: verify ownership outside transaction so audit logs persist on failure
        $exam = $this->examRepository->findByUuidForTeacher($examUuid, $teacher);
        $submission = $this->submissionRepository->findByUuidForExam($submissionUuid, $exam->id);

        return $this->transaction(function () use ($teacher, $exam, $submission, $data) {
            $gradedAnswers = $data['answers'] ?? [];

            foreach ($gradedAnswers as $gradedItem) {
                $qUuid = (string) ($gradedItem['question_uuid'] ?? '');
                if ($qUuid === '') {
                    continue;
                }

                $answer = $submission->answers->first(function ($ans) use ($qUuid) {
                    return $ans->question?->uuid === $qUuid
                        || $ans->uuid === $qUuid
                        || (string) $ans->exam_question_id === $qUuid;
                });

                if ($answer) {
                    $maxPoints = (float) ($answer->question?->points ?? 5);
                    $awarded = max(0.0, min($maxPoints, (float) ($gradedItem['points_awarded'] ?? 0)));

                    $updatePayload = [
                        'points_awarded' => $awarded,
                        'is_correct' => $awarded >= $maxPoints ? true : ($awarded > 0 ? null : false),
                    ];

                    if (array_key_exists('teacher_feedback', $gradedItem)) {
                        $cleanFeedback = trim(strip_tags((string) ($gradedItem['teacher_feedback'] ?? '')));
                        $updatePayload['teacher_feedback'] = $cleanFeedback !== '' ? $cleanFeedback : null;
                    }

                    $answer->update($updatePayload);
                }
            }

            if (array_key_exists('teacher_feedback', $data)) {
                $cleanGeneral = trim(strip_tags((string) ($data['teacher_feedback'] ?? '')));
                $submission->teacher_feedback = $cleanGeneral !== '' ? $cleanGeneral : null;
            }

            $submission->status = ExamSubmissionStatus::GRADED;
            $submission->graded_by = $teacher->id;
            $submission->graded_at = Carbon::now();
            $submission->save();

            // Recalculate student final scores and pass status
            $submission->recalculateScores();

            // Credit official league points for newly graded correct essays (+5 each) & full mark bonus (+3)
            $submission->load(['answers.question:id,type', 'student']);
            $correctEssayCount = $submission->answers->filter(function ($ans) {
                $qType = $ans->question?->type instanceof ExamQuestionType
                    ? $ans->question->type->value
                    : strtolower((string) ($ans->question?->type ?? ''));

                return $qType === ExamQuestionType::ESSAY->value && $ans->is_correct === true;
            })->count();

            $newlyAwardedLeaguePts = 0;
            if ($correctEssayCount > 0) {
                $essayPts = $correctEssayCount * LeagueRulesService::XP_QUESTION_ESSAY;
                $essayEntry = StudentXpLedger::awardOnce(
                    (int) $submission->student_id,
                    'exam_essay_graded',
                    (int) $submission->id,
                    $essayPts,
                    "تصحيح أسئلة مقالية صحيحة في امتحان: {$exam->title_ar}"
                );
                if ($essayEntry && $essayEntry->wasRecentlyCreated) {
                    $newlyAwardedLeaguePts += (int) $essayEntry->points;
                }
            }

            if ($submission->is_full_mark) {
                $bonusEntry = StudentXpLedger::awardOnce(
                    (int) $submission->student_id,
                    'exam_full_mark_bonus',
                    (int) $submission->id,
                    LeagueRulesService::XP_EXAM_FULL_MARK_BONUS,
                    "بونص الدرجة النهائية 100% في امتحان: {$exam->title_ar}"
                );
                if ($bonusEntry && $bonusEntry->wasRecentlyCreated) {
                    $newlyAwardedLeaguePts += (int) $bonusEntry->points;
                }
            }

            if ($newlyAwardedLeaguePts > 0 && $submission->student) {
                $this->leagueRulesService->creditActiveLeagues(
                    $submission->student,
                    $newlyAwardedLeaguePts,
                    $exam->teacher_id ? (int) $exam->teacher_id : null,
                    $exam->course_id ? (int) $exam->course_id : null
                );
            }

            // Recalculate exam performance metrics
            $exam->recalculatePerformanceMetrics();

            // Record security audit event
            $submission->logSecurityEvent(
                'exam_submission_graded',
                [
                    'exam_uuid' => $exam->uuid,
                    'submission_uuid' => $submission->uuid,
                    'score' => $submission->score,
                    'percentage' => $submission->percentage,
                    'is_passed' => $submission->is_passed,
                ],
                'info',
                $submission
            );

            $fresh = $submission->fresh(['student', 'grader', 'answers.question', 'exam']);
            if ($fresh && $fresh->relationLoaded('answers')) {
                $fresh->setRelation(
                    'answers',
                    $fresh->answers->sortBy(fn ($ans) => (int) ($ans->question?->question_number ?? 9999))->values()
                );
            }

            return $fresh;
        });
    }
}
