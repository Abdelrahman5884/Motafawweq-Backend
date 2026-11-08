<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\LeagueDuration;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreCourseLeagueRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $courseId = $this->input('course_id') ?? $this->input('courseId');
        $groupId = $this->input('study_group_id') ?? $this->input('studyGroupId') ?? $this->input('groupId');
        $titleAr = $this->input('title_ar') ?? $this->input('titleAr');
        $duration = $this->input('duration') ?? $this->input('duration_type') ?? $this->input('durationType') ?? 'monthly';
        $startsAt = $this->input('starts_at') ?? $this->input('startsAt');
        $isActive = $this->input('is_active') ?? $this->input('isActive');

        $merged = [];
        if ($courseId !== null) {
            $merged['course_id'] = $courseId;
        }
        if ($groupId !== null && $groupId !== 'all') {
            $merged['study_group_id'] = $groupId;
        } else {
            $merged['study_group_id'] = null;
        }
        if ($titleAr !== null) {
            $merged['title_ar'] = trim(strip_tags((string) $titleAr));
        }
        if ($duration !== null) {
            $merged['duration'] = strtolower(trim((string) $duration));
        }
        if ($startsAt !== null) {
            $merged['starts_at'] = $startsAt;
        }
        if ($isActive !== null) {
            $merged['is_active'] = filter_var($isActive, FILTER_VALIDATE_BOOLEAN);
        }

        $this->merge($merged);
    }

    public function rules(): array
    {
        return [
            'course_id'      => ['required'],
            'study_group_id' => ['nullable'],
            'title_ar'       => ['nullable', 'string', 'max:250'],
            'duration'       => ['nullable', 'string', Rule::in(LeagueDuration::values())],
            'starts_at'      => ['nullable', 'date'],
            'is_active'      => ['nullable', 'boolean'],
        ];
    }
}

