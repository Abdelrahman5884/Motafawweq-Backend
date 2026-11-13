<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;

class AddLeagueParticipantRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $studentId = $this->input('student_id') ?? $this->input('studentId') ?? $this->input('student_code') ?? $this->input('studentCode');
        $initialXp = $this->input('initial_xp') ?? $this->input('initialXp') ?? $this->input('xp_points') ?? $this->input('xpPoints');
        $groupAr = $this->input('group_ar') ?? $this->input('groupAr');

        $merged = [];
        if ($studentId !== null) {
            $merged['student_id'] = trim((string) $studentId);
        }
        if ($initialXp !== null && $initialXp !== '') {
            $merged['initial_xp'] = (int) $initialXp;
        }
        if ($groupAr !== null) {
            $merged['group_ar'] = trim(strip_tags((string) $groupAr));
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'string', 'max:100'],
            'initial_xp' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'group_ar'   => ['nullable', 'string', 'max:150'],
        ];
    }
}

