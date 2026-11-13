<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\LeagueDuration;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateCourseLeagueRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $merged = [];

        if ($this->hasAny(['title_ar', 'titleAr', 'leagueTitle'])) {
            $titleAr = $this->input('title_ar') ?? $this->input('titleAr') ?? $this->input('leagueTitle');
            $merged['title_ar'] = $titleAr !== null ? trim(strip_tags((string) $titleAr)) : null;
        }

        if ($this->hasAny(['study_group_id', 'studyGroupId', 'groupId'])) {
            $groupId = $this->input('study_group_id') ?? $this->input('studyGroupId') ?? $this->input('groupId');
            $merged['study_group_id'] = ($groupId === null || $groupId === '' || $groupId === 'all') ? 'all' : $groupId;
        }

        if ($this->hasAny(['duration', 'duration_type', 'durationType'])) {
            $duration = $this->input('duration') ?? $this->input('duration_type') ?? $this->input('durationType');
            if ($duration !== null) {
                $merged['duration'] = strtolower(trim((string) $duration));
            }
        }

        if ($this->hasAny(['ends_at', 'endsAt'])) {
            $merged['ends_at'] = $this->input('ends_at') ?? $this->input('endsAt');
        }

        if ($this->hasAny(['is_active', 'isActive'])) {
            $isActive = $this->input('is_active') ?? $this->input('isActive');
            if ($isActive !== null) {
                $merged['is_active'] = filter_var($isActive, FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'title_ar'       => ['sometimes', 'required', 'string', 'min:2', 'max:250'],
            'study_group_id' => ['sometimes', 'nullable'],
            'duration'       => ['sometimes', 'string', Rule::in(LeagueDuration::values())],
            'ends_at'        => ['sometimes', 'nullable', 'date'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }
}

