<?php

namespace App\Http\Requests\V1\Student;

use App\Http\Requests\BaseApiRequest;

class SubmitHomeworkRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $submissionType = $this->input('submission_type') ?? $this->input('submissionType');
        $solutionText = $this->input('solution_text') ?? $this->input('solutionText');
        $locationAr = $this->input('location_ar') ?? $this->input('locationAr');

        $merged = [];
        if ($submissionType !== null) {
            $merged['submission_type'] = strtolower(trim((string) $submissionType));
        }
        if ($solutionText !== null) {
            $merged['solution_text'] = trim((string) $solutionText);
        }
        if ($locationAr !== null) {
            $merged['location_ar'] = trim(strip_tags((string) $locationAr));
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'submission_type' => ['nullable', 'string', 'in:pdf,image,text'],
            'solution_text'   => ['nullable', 'string'],
            'location_ar'     => ['nullable', 'string', 'max:150'],
            'file'            => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:25600'],
            'attachment'      => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:25600'],
        ];
    }
}

