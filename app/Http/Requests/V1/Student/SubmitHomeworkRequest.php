<?php

namespace App\Http\Requests\V1\Student;

use App\Http\Requests\BaseApiRequest;

class SubmitHomeworkRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStudent();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $submissionType = $this->input('submission_type') ?? $this->input('submissionType');
        $solutionText = $this->input('solution_text') ?? $this->input('solutionText');
        $removeAttachment = $this->input('remove_attachment') ?? $this->input('removeAttachment');

        $merged = [];
        if ($submissionType !== null) {
            $merged['submission_type'] = strtolower(trim((string) $submissionType));
        }
        if ($solutionText !== null) {
            $merged['solution_text'] = trim(strip_tags((string) $solutionText));
        }
        if ($removeAttachment !== null) {
            $merged['remove_attachment'] = filter_var($removeAttachment, FILTER_VALIDATE_BOOLEAN);
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'submission_type' => ['nullable', 'string', 'in:pdf,image,text'],
            'solution_text' => ['nullable', 'string', 'max:20000'],
            'remove_attachment' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:25600'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:25600'],
        ];
    }
}
