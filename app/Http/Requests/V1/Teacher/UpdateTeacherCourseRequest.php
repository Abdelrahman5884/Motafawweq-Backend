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

        if ($this->hasAny(['price_egp', 'priceEgp', 'price'])) {
            $priceEgp = $this->input('price_egp') ?? $this->input('priceEgp') ?? $this->input('price');
            if ($priceEgp !== null) {
                $merged['price_egp'] = $priceEgp;
            }
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'title_ar'       => ['sometimes', 'required', 'string', 'min:3', 'max:200'],
            'title'          => ['sometimes', 'nullable', 'string', 'max:200'],
            'titleAr'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'titleEn'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'title_en'       => ['sometimes', 'nullable', 'string', 'max:200'],
            'stage'          => ['sometimes', 'nullable', 'string', 'max:50'],
            'stageId'        => ['sometimes', 'nullable', 'string', 'max:50'],
            'stage_id'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'grade_ar'       => ['sometimes', 'required', 'string', Rule::in(StageType::allowedGradesAr())],
            'gradeAr'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'grade'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'subject'        => ['sometimes', 'nullable', 'string', 'max:120'],
            'subjectAr'      => ['sometimes', 'nullable', 'string', 'max:120'],
            'subject_ar'     => ['sometimes', 'nullable', 'string', 'max:120'],
            'subjectEn'      => ['sometimes', 'nullable', 'string', 'max:120'],
            'subject_en'     => ['sometimes', 'nullable', 'string', 'max:120'],
            'price_egp'      => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:50000'],
            'priceEgp'       => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:50000'],
            'price'          => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:50000'],
            'description'    => ['sometimes', 'nullable', 'string', 'max:3000'],
            'descriptionAr'  => ['sometimes', 'nullable', 'string', 'max:3000'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'descriptionEn'  => ['sometimes', 'nullable', 'string', 'max:3000'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'hours'          => ['sometimes', 'nullable', 'string', 'max:60'],
            'hoursTotal'     => ['sometimes', 'nullable', 'string', 'max:60'],
            'hours_total'    => ['sometimes', 'nullable', 'string', 'max:60'],
            'lessonsCount'   => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'lessons_count'  => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'status'         => ['sometimes', 'nullable', 'string', Rule::in(CourseStatus::values())],
        ];
    }
}

