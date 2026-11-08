<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;

class GradeEssayRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $feedback = $this->input('teacher_feedback') ?? $this->input('teacherFeedback');
        $rawAnswers = $this->input('answers') ?? [];

        $normalizedAnswers = [];
        if (is_array($rawAnswers)) {
            foreach ($rawAnswers as $ans) {
                if (!is_array($ans)) {
                    continue;
                }
                $normalizedAnswers[] = [
                    'question_uuid'    => $ans['question_uuid'] ?? $ans['questionUuid'] ?? $ans['exam_question_uuid'] ?? $ans['examQuestionUuid'] ?? null,
                    'points_awarded'   => isset($ans['points_awarded']) ? $ans['points_awarded'] : ($ans['pointsAwarded'] ?? 0),
                    'teacher_feedback' => trim(strip_tags((string) ($ans['teacher_feedback'] ?? $ans['teacherFeedback'] ?? ''))),
                ];
            }
        }

        $this->merge([
            'answers'          => $normalizedAnswers,
            'teacher_feedback' => $feedback !== null ? trim(strip_tags((string) $feedback)) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'answers'                            => ['required', 'array', 'min:1'],
            'answers.*.question_uuid'            => ['required', 'string'],
            'answers.*.points_awarded'           => ['required', 'numeric', 'min:0'],
            'answers.*.teacher_feedback'         => ['nullable', 'string', 'max:1000'],
            'teacher_feedback'                   => ['nullable', 'string', 'max:2000'],
        ];
    }
}

