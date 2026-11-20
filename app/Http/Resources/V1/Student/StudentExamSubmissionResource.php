<?php

namespace App\Http\Resources\V1\Student;

use App\Enums\ExamQuestionType;
use App\Enums\ExamSubmissionStatus;
use App\Http\Resources\BaseJsonResource;
use App\Models\ExamSubmissionAnswer;
use App\Models\StudentXpLedger;
use Illuminate\Http\Request;

class StudentExamSubmissionResource extends BaseJsonResource
{
    /**
     * Transform the submission for the student.
     * Strictly enforces exam->show_results and graded-status policies before revealing answers or scores.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof ExamSubmissionStatus
            ? $this->status->value
            : (string) ($this->status ?? 'in_progress');

        $statusLabelAr = $this->status instanceof ExamSubmissionStatus
            ? $this->status->labelAr()
            : (ExamSubmissionStatus::tryFrom((string) $this->status)?->labelAr() ?? 'قيد الحل');

        $isGraded = $status === ExamSubmissionStatus::GRADED->value;
        $isPendingManualGrading = $status === ExamSubmissionStatus::SUBMITTED->value;
        $showResults = (bool) ($this->exam?->show_results ?? true);
        $canRevealDetails = $showResults && $isGraded;

        $answersLoaded = $this->relationLoaded('answers') ? $this->answers : collect();
        $correctCount = 0;
        $wrongCount = 0;
        $unansweredCount = 0;
        $essayCount = 0;

        foreach ($answersLoaded as $ans) {
            /** @var ExamSubmissionAnswer $ans */
            $qType = $ans->question?->type instanceof ExamQuestionType
                ? $ans->question->type
                : null;

            if ($qType === ExamQuestionType::ESSAY) {
                $essayCount++;
            }

            if ($ans->is_correct === true) {
                $correctCount++;
            } elseif ($ans->is_correct === false) {
                $hasUserResponse = $ans->selected_index !== null
                    || $ans->selected_bool !== null
                    || ($ans->essay_answer_text !== null && trim((string) $ans->essay_answer_text) !== '');
                if ($hasUserResponse) {
                    $wrongCount++;
                } else {
                    $unansweredCount++;
                }
            }
        }

        $xpAwarded = 0;
        if (isset($this->xp_awarded_runtime)) {
            $xpAwarded = (int) $this->xp_awarded_runtime;
        } elseif ($this->id && $this->student_id) {
            $xpAwarded = (int) StudentXpLedger::where('student_id', $this->student_id)
                ->whereIn('source_type', ['exam_submitted', 'exam_essay_graded', 'exam_full_mark_bonus'])
                ->where('source_id', $this->id)
                ->sum('points');
        }

        $review = [];
        if ($canRevealDetails && $answersLoaded->isNotEmpty()) {
            $review = $answersLoaded
                ->sortBy(fn (ExamSubmissionAnswer $a) => (int) ($a->question?->question_number ?? $a->id))
                ->map(function (ExamSubmissionAnswer $ans) {
                    $q = $ans->question;
                    $typeVal = $q?->type instanceof ExamQuestionType ? $q->type->value : (string) ($q?->type ?? 'mcq');

                    return [
                        'id' => $ans->uuid,
                        'questionId' => $q?->uuid,
                        'questionNumber' => (int) ($q?->question_number ?? 0),
                        'type' => $typeVal,
                        'points' => (int) ($q?->points ?? 0),
                        'pointsAwarded' => (float) ($ans->points_awarded ?? 0),
                        'questionAr' => $q?->question_ar ?? '',
                        'optionsAr' => is_array($q?->options_ar) ? array_values($q->options_ar) : [],
                        'selectedIndex' => $ans->selected_index !== null ? (int) $ans->selected_index : null,
                        'selectedBool' => $ans->selected_bool !== null ? (bool) $ans->selected_bool : null,
                        'essayAnswerText' => $ans->essay_answer_text,
                        'correctIndex' => $q?->correct_index !== null ? (int) $q->correct_index : null,
                        'correctBool' => $q?->correct_bool !== null ? (bool) $q->correct_bool : null,
                        'modelAnswerAr' => $q?->model_answer_ar,
                        'explanationAr' => $q?->explanation_ar,
                        'isCorrect' => $ans->is_correct !== null ? (bool) $ans->is_correct : null,
                        'teacherComment' => $ans->teacher_comment,
                    ];
                })
                ->values()
                ->all();
        }

        return [
            'id' => $this->uuid,
            'examId' => $this->whenLoaded('exam', fn () => $this->exam?->uuid),
            'examTitleAr' => $this->whenLoaded('exam', fn () => $this->exam?->title_ar),
            'courseTitleAr' => $this->whenLoaded('exam', fn () => $this->exam?->course_title_ar),
            'status' => $status,
            'statusLabelAr' => $statusLabelAr,
            'isPendingManualGrading' => $isPendingManualGrading,
            'score' => $showResults ? (float) $this->score : null,
            'fullMark' => (int) $this->full_mark,
            'passScore' => (int) ($this->exam?->pass_score ?? 0),
            'percentage' => $canRevealDetails ? (float) $this->percentage : null,
            'isPassed' => $canRevealDetails ? (bool) $this->is_passed : null,
            'isFullMark' => $canRevealDetails ? (bool) $this->is_full_mark : null,
            'correctCount' => $canRevealDetails ? $correctCount : null,
            'wrongCount' => $canRevealDetails ? $wrongCount : null,
            'unansweredCount' => $canRevealDetails ? $unansweredCount : null,
            'essayCount' => $essayCount,
            'totalQuestions' => $answersLoaded->count() ?: (int) ($this->exam?->questions_count ?? 0),
            'xpAwarded' => $xpAwarded,
            'teacherFeedback' => $canRevealDetails ? $this->teacher_feedback : null,
            'startedAt' => $this->formatDate($this->started_at),
            'submittedAt' => $this->formatDate($this->submitted_at),
            'gradedAt' => $this->formatDate($this->graded_at),
            'showResults' => $showResults,
            'review' => $review,
        ];
    }
}
