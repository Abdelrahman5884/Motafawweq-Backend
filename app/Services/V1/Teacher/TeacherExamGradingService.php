<?php

namespace App\Services\V1\Teacher;

use App\Enums\ExamSubmissionStatus;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\ExamSubmissionRepositoryInterface;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class TeacherExamGradingService extends BaseService
{
    public function __construct(
        protected ExamRepositoryInterface $examRepository,
        protected ExamSubmissionRepositoryInterface $submissionRepository
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
            'exam'        => $exam,
            'submissions' => $submissions,
        ];
    }

    /**
     * Retrieve single submission details with all student answers, model answers, and rubric.
     */
    public function getSubmission(User $teacher, string $examUuid, string $submissionUuid): ExamSubmission
    {
        $exam = $this->examRepository->findByUuidForTeacher($examUuid, $teacher);

        return $this->submissionRepository->findByUuidForExam($submissionUuid, $exam->id);
    }

    /**
     * Grade essay questions, record teacher feedback, and update total score & exam metrics atomically.
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
                        || (string) $ans->exam_question_id === $qUuid;
                });

                if ($answer) {
                    $maxPoints = (float) ($answer->question?->points ?? 5);
                    $awarded = max(0.0, min($maxPoints, (float) ($gradedItem['points_awarded'] ?? 0)));
                    $feedback = isset($gradedItem['teacher_feedback']) ? trim(strip_tags((string) $gradedItem['teacher_feedback'])) : null;

                    $answer->update([
                        'points_awarded'   => $awarded,
                        'is_correct'       => $awarded >= $maxPoints ? true : ($awarded > 0 ? null : false),
                        'teacher_feedback' => $feedback ?: $answer->teacher_feedback,
                    ]);
                }
            }

            if (!empty($data['teacher_feedback'])) {
                $submission->teacher_feedback = trim(strip_tags((string) $data['teacher_feedback']));
            }

            $submission->status = ExamSubmissionStatus::GRADED;
            $submission->graded_by = $teacher->id;
            $submission->graded_at = Carbon::now();
            $submission->save();

            // Recalculate student final scores and pass status
            $submission->recalculateScores();

            // Recalculate exam performance metrics
            $exam->recalculatePerformanceMetrics();

            // Record security audit event
            $submission->logSecurityEvent(
                'exam_submission_graded',
                [
                    'exam_uuid'       => $exam->uuid,
                    'submission_uuid' => $submission->uuid,
                    'score'           => $submission->score,
                    'percentage'      => $submission->percentage,
                    'is_passed'       => $submission->is_passed,
                ],
                'info',
                $submission
            );

            return $submission->fresh(['student', 'grader', 'answers.question', 'exam']);
        });
    }
}
