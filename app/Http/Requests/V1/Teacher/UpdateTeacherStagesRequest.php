<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\StageType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherStagesRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Normalize camelCase stagesTaught into stages_taught
        if ($this->has('stagesTaught') && !$this->has('stages_taught')) {
            $this->merge(['stages_taught' => $this->input('stagesTaught')]);
        }
    }

    public function rules(): array
    {
        return [
            'stages_taught'   => ['required', 'array', 'max:9'],
            'stages_taught.*' => ['required', 'string', Rule::in(StageType::allowedGradesAr())],
        ];
    }
}

