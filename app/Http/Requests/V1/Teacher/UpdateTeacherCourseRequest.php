<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\CourseStatus;
use App\Enums\StageType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherCourseRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->hasAny(['title_ar', 'titleAr', 'title'])) {
            $titleAr = $this->input('title_ar') ?? $this->input('titleAr') ?? $this->input('title');
            if ($titleAr !== null) {
                $merged['title_ar'] = trim(strip_tags((string) $titleAr));
            }
        }

        if ($this->hasAny(['grade_ar', 'gradeAr', 'grade'])) {
            $gradeAr = $this->input('grade_ar') ?? $this->input('gradeAr') ?? $this->input('grade');
            if ($gradeAr !== null) {
                $merged['grade_ar'] = trim(strip_tags((string) $gradeAr));
            }
        }

        if ($this->hasAny(['stage_id', 'stageId', 'stage_type', 'stageType', 'stage'])) {
            $stageId = $this->input('stage_id') ?? $this->input('stageId') ?? $this->input('stage_type') ?? $this->input('stageType') ?? $this->input('stage');
            if ($stageId !== null) {
                $merged['stage_id'] = trim(strip_tags((string) $stageId));
            }
        }

        if ($this->hasAny(['subject_ar', 'subjectAr', 'subject'])) {
            $subjectAr = $this->input('subject_ar') ?? $this->input('subjectAr') ?? $this->input('subject');
            if ($subjectAr !== null) {
                $merged['subject_ar'] = trim(strip_tags((string) $subjectAr));
            }
        }

        if ($this->hasAny(['price_egp', 'priceEgp', 'price'])) {
            $priceEgp = $this->input('price_egp') ?? $this->input('priceEgp') ?? $this->input('price');
            if ($priceEgp !== null) {
                $merged['price_egp'] = $priceEgp;
            }
        }

        if ($this->hasAny(['description_ar', 'descriptionAr', 'description'])) {
            $descriptionAr = $this->input('description_ar') ?? $this->input('descriptionAr') ?? $this->input('description');
            if ($descriptionAr !== null) {
                $merged['description_ar'] = trim(strip_tags((string) $descriptionAr));
            }
        }

        if ($this->hasAny(['hours_total', 'hoursTotal', 'hours'])) {
            $hoursTotal = $this->input('hours_total') ?? $this->input('hoursTotal') ?? $this->input('hours');
            if ($hoursTotal !== null) {
                $merged['hours_total'] = trim(strip_tags((string) $hoursTotal));
            }
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'title_ar' => ['sometimes', 'required', 'string', 'min:3', 'max:200'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'titleAr' => ['sometimes', 'nullable', 'string', 'max:200'],
            'titleEn' => ['sometimes', 'nullable', 'string', 'max:200'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:200'],
            'stage' => ['sometimes', 'nullable', 'string', 'max:50'],
            'stageId' => ['sometimes', 'nullable', 'string', 'max:50'],
            'stage_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'stage_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'stageType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'grade_ar' => ['sometimes', 'required', 'string', Rule::in(StageType::allowedGradesAr())],
            'gradeAr' => ['sometimes', 'nullable', 'string', 'max:100'],
            'grade' => ['sometimes', 'nullable', 'string', 'max:100'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:120'],
            'subjectAr' => ['sometimes', 'nullable', 'string', 'max:120'],
            'subject_ar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'subjectEn' => ['sometimes', 'nullable', 'string', 'max:120'],
            'subject_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'price_egp' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:50000'],
            'priceEgp' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:50000'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:50000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'descriptionAr' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'descriptionEn' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'hours' => ['sometimes', 'nullable', 'string', 'max:60'],
            'hoursTotal' => ['sometimes', 'nullable', 'string', 'max:60'],
            'hours_total' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lessonsCount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:500'],
            'lessons_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:500'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(CourseStatus::values())],
        ];
    }
}
