<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class GradeHomeworkSubmissionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $status = $this->input('status') ?? 'graded';
        $score = $this->input('score');
        $feedbackAr = $this->input('feedback_ar') ?? $this->input('feedbackAr') ?? $this->input('feedback');

        $merged = [
            'status' => strtolower(trim((string) $status)),
        ];

        if ($score !== null) {
            $merged['score'] = (float) $score;
        }

        if ($feedbackAr !== null) {
            $merged['feedback_ar'] = trim(strip_tags((string) $feedbackAr));
        }

        $this->merge($merged);
    }

    public function rules(): array
    {
        return [
            'status'      => ['required', 'string', Rule::in(['graded', 'rejected'])],
            'score'       => ['nullable', 'numeric', 'min:0'],
            'feedback_ar' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

