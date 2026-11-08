<?php

namespace App\Services\V1\Student;

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\ExamSubmissionAnswer;
use App\Models\User;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class StudentExamTakingService extends BaseService
{
    /**
     * Process student exam submission, auto-grade objective questions, and calculate preliminary metrics.
     */
    public function submitExam(User $student, string $examUuid, array $data): ExamSubmission
    {
        /** @var Exam|null $exam */
        $exam = Exam::where('uuid', $examUuid)
            ->with('questions')
            ->first();

        if (!$exam) {
            throw (new ModelNotFoundException())->setModel(Exam::class, [$examUuid]);
        }

        if ($exam->status !== ExamStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'exam' => [__('messages.exam_closed_for_submission')],
            ]);
        }

        // Check if student already submitted this exam
        $alreadySubmitted = ExamSubmission::where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->whereIn('status', [ExamSubmissionStatus::SUBMITTED, ExamSubmissionStatus::GRADED])
            ->exists();

        if ($alreadySubmitted) {
            throw ValidationException::withMessages([
                'exam' => [__('messages.exam_already_submitted')],
            ]);
        }

        return $this->transaction(function () use ($student, $exam, $data) {
            $submittedAnswers = collect($data['answers'] ?? []);

            /** @var ExamSubmission $submission */
            $submission = ExamSubmission::create([
                'exam_id'      => $exam->id,
                'student_id'   => $student->id,
                'started_at'   => !empty($data['started_at']) ? Carbon::parse($data['started_at']) : Carbon::now(),
                'submitted_at' => Carbon::now(),
                'full_mark'    => (int) $exam->full_mark,
                'status'       => ExamSubmissionStatus::IN_PROGRESS,
            ]);

            $hasEssay = false;

            foreach ($exam->questions as $question) {
                $qUuid = $question->uuid;
                $studentAnswer = $submittedAnswers->first(function ($item) use ($qUuid, $question) {
                    $itemUuid = $item['question_uuid'] ?? $item['questionUuid'] ?? null;
                    return $itemUuid === $qUuid || (string) $itemUuid === (string) $question->id;
                });

                $selectedIndex = null;
                $selectedBool = null;
                $essayText = null;
                $pointsAwarded = 0.00;
                $isCorrect = null;

                $qType = $question->type instanceof ExamQuestionType ? $question->type : ExamQuestionType::tryFrom((string) $question->type);

                if ($qType === ExamQuestionType::MCQ) {
                    if ($studentAnswer && isset($studentAnswer['selected_index']) && $studentAnswer['selected_index'] !== null) {
                        $selectedIndex = (int) $studentAnswer['selected_index'];
                        $isCorrect = ($selectedIndex === (int) $question->correct_index);
                        $pointsAwarded = $isCorrect ? (float) $question->points : 0.00;
                    } else {
                        $isCorrect = false;
                        $pointsAwarded = 0.00;
                    }
                } elseif ($qType === ExamQuestionType::TRUE_FALSE) {
                    if ($studentAnswer && isset($studentAnswer['selected_bool']) && $studentAnswer['selected_bool'] !== null) {
                        $selectedBool = (bool) $studentAnswer['selected_bool'];
                        $isCorrect = ($selectedBool === (bool) $question->correct_bool);
                        $pointsAwarded = $isCorrect ? (float) $question->points : 0.00;
                    } else {
                        $isCorrect = false;
                        $pointsAwarded = 0.00;
                    }
                } elseif ($qType === ExamQuestionType::ESSAY) {
                    $hasEssay = true;
                    if ($studentAnswer && !empty($studentAnswer['essay_answer_text'])) {
                        $essayText = trim(strip_tags((string) $studentAnswer['essay_answer_text']));
                    }
                    $pointsAwarded = 0.00;
                    $isCorrect = null;
                }

                ExamSubmissionAnswer::create([
                    'exam_submission_id' => $submission->id,
                    'exam_question_id'   => $question->id,
                    'selected_index'     => $selectedIndex,
                    'selected_bool'      => $selectedBool,
                    'essay_answer_text'  => $essayText,
                    'points_awarded'     => $pointsAwarded,
                    'is_correct'         => $isCorrect,
                ]);
            }

            if ($hasEssay) {
                $submission->status = ExamSubmissionStatus::SUBMITTED;
            } else {
                $submission->status = ExamSubmissionStatus::GRADED;
                $submission->graded_at = Carbon::now();
            }

            $submission->save();
            $submission->recalculateScores();

            if ($submission->status === ExamSubmissionStatus::GRADED) {
                $exam->recalculatePerformanceMetrics();
            }

            return $submission->fresh(['exam', 'student', 'answers.question']);
        });
    }
}

