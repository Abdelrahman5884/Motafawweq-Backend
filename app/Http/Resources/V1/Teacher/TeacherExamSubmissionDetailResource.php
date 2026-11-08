<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\ExamQuestionType;
use App\Enums\ExamSubmissionStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class TeacherExamSubmissionDetailResource extends BaseJsonResource
{
    /**
     * Transform the submission with its full answers matrix for teacher grading modal.
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

        $student = $this->whenLoaded('student');
        $answers = $this->relationLoaded('answers') ? $this->answers : collect();

        return [
            'id'              => $this->uuid,
            'examId'          => $this->whenLoaded('exam', fn () => $this->exam?->uuid),
            'examTitleAr'     => $this->whenLoaded('exam', fn () => $this->exam?->title_ar),
            'status'          => $status,
            'statusLabelAr'   => $statusLabelAr,
            'score'           => (float) $this->score,
            'fullMark'        => (int) $this->full_mark,
            'percentage'      => (float) $this->percentage,
            'isPassed'        => (bool) $this->is_passed,
            'isFullMark'      => (bool) $this->is_full_mark,
            'teacherFeedback' => $this->teacher_feedback,
            'startedAt'       => $this->formatDate($this->started_at),
            'submittedAt'     => $this->formatDate($this->submitted_at),
            'gradedAt'        => $this->formatDate($this->graded_at),
            'student'         => $student ? [
                'id'          => $student->id,
                'name'        => $student->name,
                'email'       => $student->email,
                'studentCode' => $student->student_code ?? null,
                'avatar'      => $student->avatar,
            ] : null,
            'grader'          => $this->whenLoaded('grader', fn () => [
                'id'          => $this->grader?->id,
                'name'        => $this->grader?->name,
            ]),
            'answers'         => $answers->map(function ($answer) {
                $question = $answer->question;
                $questionType = $question?->type instanceof ExamQuestionType
                    ? $question->type->value
                    : (string) ($question?->type ?? 'mcq');

                return [
                    'id'              => $answer->uuid,
                    'questionUuid'    => $question?->uuid,
                    'questionNumber'  => (int) ($question?->question_number ?? 1),
                    'questionType'    => $questionType,
                    'questionAr'      => $question?->question_ar,
                    'optionsAr'       => $question?->options_ar ?? [],
                    'points'          => (int) ($question?->points ?? 0),
                    'selectedIndex'   => $answer->selected_index,
                    'selectedBool'    => $answer->selected_bool,
                    'essayAnswerText' => $answer->essay_answer_text,
                    'pointsAwarded'   => (float) $answer->points_awarded,
                    'isCorrect'       => $answer->is_correct,
                    'teacherFeedback' => $answer->teacher_feedback,
                    // Teacher-only grading references
                    'correctIndex'    => $question?->correct_index,
                    'correctBool'     => $question?->correct_bool,
                    'modelAnswerAr'   => $question?->model_answer_ar,
                    'rubricPointsAr'  => $question?->rubric_points_ar ?? [],
                    'explanationAr'   => $question?->explanation_ar,
                ];
            })->values(),
        ];
    }
}

