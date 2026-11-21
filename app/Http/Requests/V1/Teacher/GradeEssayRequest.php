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

        $hasFeedback = $this->has('teacher_feedback') || $this->has('teacherFeedback');
        $feedback = $this->input('teacher_feedback') ?? $this->input('teacherFeedback');
        $rawAnswers = $this->input('answers') ?? [];

        $normalizedAnswers = [];
        if (is_array($rawAnswers)) {
            foreach ($rawAnswers as $ans) {
                if (! is_array($ans)) {
                    continue;
                }
                $entry = [
                    'question_uuid' => $ans['question_uuid'] ?? $ans['questionUuid'] ?? $ans['exam_question_uuid'] ?? $ans['examQuestionUuid'] ?? $ans['answer_uuid'] ?? $ans['answerUuid'] ?? null,
                    'points_awarded' => isset($ans['points_awarded']) ? $ans['points_awarded'] : ($ans['pointsAwarded'] ?? 0),
                ];
                if (array_key_exists('teacher_feedback', $ans) || array_key_exists('teacherFeedback', $ans)) {
                    $entry['teacher_feedback'] = trim(strip_tags((string) ($ans['teacher_feedback'] ?? $ans['teacherFeedback'] ?? '')));
                }
                $normalizedAnswers[] = $entry;
            }
        }

        $merged = [
            'answers' => $normalizedAnswers,
        ];
        if ($hasFeedback) {
            $merged['teacher_feedback'] = $feedback !== null ? trim(strip_tags((string) $feedback)) : '';
        }

        $this->merge($merged);
    }

    public function rules(): array
    {
        return [
            'answers' => ['nullable', 'array'],
            'answers.*.question_uuid' => ['required', 'string'],
            'answers.*.points_awarded' => ['required', 'numeric', 'min:0'],
            'answers.*.teacher_feedback' => ['nullable', 'string', 'max:1000'],
            'teacher_feedback' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
