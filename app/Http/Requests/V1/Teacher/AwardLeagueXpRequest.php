<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;

class AwardLeagueXpRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $studentId = $this->input('student_id') ?? $this->input('studentId') ?? $this->input('studentUuid');
        $xp = $this->input('xp_amount') ?? $this->input('xpAmount') ?? $this->input('xp') ?? $this->input('points') ?? 50;
        $reason = $this->input('reason_ar') ?? $this->input('reasonAr') ?? $this->input('reason');

        $merged = [
            'student_id' => $studentId,
            'xp_amount'  => (int) $xp,
        ];

        if ($reason !== null) {
            $merged['reason_ar'] = trim(strip_tags((string) $reason));
        }

        $this->merge($merged);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required'],
            'xp_amount'  => ['required', 'integer', 'min:1', 'max:5000'],
            'reason_ar'  => ['nullable', 'string', 'max:255'],
        ];
    }
}

