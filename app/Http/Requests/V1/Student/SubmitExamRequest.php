<?php

namespace App\Http\Requests\V1\Student;

use App\Http\Requests\BaseApiRequest;

class SubmitExamRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $rawAnswers = $this->input('answers') ?? [];
        $normalizedAnswers = [];

        if (is_array($rawAnswers)) {
            foreach ($rawAnswers as $ans) {
                if (!is_array($ans)) {
                    continue;
                }
                $normalizedAnswers[] = [
                    'question_uuid'     => $ans['question_uuid'] ?? $ans['questionUuid'] ?? $ans['exam_question_uuid'] ?? $ans['examQuestionUuid'] ?? null,
                    'selected_index'    => isset($ans['selected_index']) ? (int) $ans['selected_index'] : (isset($ans['selectedIndex']) ? (int) $ans['selectedIndex'] : null),
                    'selected_bool'     => isset($ans['selected_bool']) ? filter_var($ans['selected_bool'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : (isset($ans['selectedBool']) ? filter_var($ans['selectedBool'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null),
                    'essay_answer_text' => isset($ans['essay_answer_text']) ? trim(strip_tags((string) $ans['essay_answer_text'])) : (isset($ans['essayAnswerText']) ? trim(strip_tags((string) $ans['essayAnswerText'])) : null),
                ];
            }
        }

        $this->merge([
            'answers'    => $normalizedAnswers,
            'started_at' => $this->input('started_at') ?? $this->input('startedAt'),
        ]);
    }

    public function rules(): array
    {
        return [
            'started_at'                 => ['nullable', 'date'],
            'answers'                    => ['required', 'array'],
            'answers.*.question_uuid'    => ['required', 'string'],
            'answers.*.selected_index'   => ['nullable', 'integer', 'min:0', 'max:10'],
            'answers.*.selected_bool'    => ['nullable', 'boolean'],
            'answers.*.essay_answer_text'=> ['nullable', 'string', 'max:10000'],
        ];
    }
}

