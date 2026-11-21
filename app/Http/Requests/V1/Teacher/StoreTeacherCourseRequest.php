<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\CourseStatus;
use App\Enums\StageType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreTeacherCourseRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $titleAr = $this->input('title_ar') ?? $this->input('titleAr') ?? $this->input('title');
        $gradeAr = $this->input('grade_ar') ?? $this->input('gradeAr') ?? $this->input('grade');
        $priceEgp = $this->input('price_egp') ?? $this->input('priceEgp') ?? $this->input('price');
        $stageId = $this->input('stage_id') ?? $this->input('stageId') ?? $this->input('stage_type') ?? $this->input('stageType') ?? $this->input('stage');
        $subjectAr = $this->input('subject_ar') ?? $this->input('subjectAr') ?? $this->input('subject');
        $descriptionAr = $this->input('description_ar') ?? $this->input('descriptionAr') ?? $this->input('description');
        $hoursTotal = $this->input('hours_total') ?? $this->input('hoursTotal') ?? $this->input('hours');

        $merged = [];
        if ($titleAr !== null) {
            $merged['title_ar'] = trim(strip_tags((string) $titleAr));
        }
        if ($gradeAr !== null) {
            $merged['grade_ar'] = trim(strip_tags((string) $gradeAr));
        }
        if ($priceEgp !== null) {
            $merged['price_egp'] = $priceEgp;
        }
        if ($stageId !== null) {
            $merged['stage_id'] = trim(strip_tags((string) $stageId));
        }
        if ($subjectAr !== null) {
            $merged['subject_ar'] = trim(strip_tags((string) $subjectAr));
        }
        if ($descriptionAr !== null) {
            $merged['description_ar'] = trim(strip_tags((string) $descriptionAr));
        }
        if ($hoursTotal !== null) {
            $merged['hours_total'] = trim(strip_tags((string) $hoursTotal));
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'title_ar' => ['required', 'string', 'min:3', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
            'titleAr' => ['nullable', 'string', 'max:200'],
            'titleEn' => ['nullable', 'string', 'max:200'],
            'title_en' => ['nullable', 'string', 'max:200'],
            'stage' => ['nullable', 'string', 'max:50'],
            'stageId' => ['nullable', 'string', 'max:50'],
            'stage_id' => ['nullable', 'string', 'max:50'],
            'stage_type' => ['nullable', 'string', 'max:50'],
            'stageType' => ['nullable', 'string', 'max:50'],
            'grade_ar' => ['required', 'string', Rule::in(StageType::allowedGradesAr())],
            'gradeAr' => ['nullable', 'string', 'max:100'],
            'grade' => ['nullable', 'string', 'max:100'],
            'subject' => ['nullable', 'string', 'max:120'],
            'subjectAr' => ['nullable', 'string', 'max:120'],
            'subject_ar' => ['nullable', 'string', 'max:120'],
            'subjectEn' => ['nullable', 'string', 'max:120'],
            'subject_en' => ['nullable', 'string', 'max:120'],
            'price_egp' => ['nullable', 'numeric', 'min:0', 'max:50000'],
            'priceEgp' => ['nullable', 'numeric', 'min:0', 'max:50000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:50000'],
            'description' => ['nullable', 'string', 'max:3000'],
            'descriptionAr' => ['nullable', 'string', 'max:3000'],
            'description_ar' => ['nullable', 'string', 'max:3000'],
            'descriptionEn' => ['nullable', 'string', 'max:3000'],
            'description_en' => ['nullable', 'string', 'max:3000'],
            'hours' => ['nullable', 'string', 'max:60'],
            'hoursTotal' => ['nullable', 'string', 'max:60'],
            'hours_total' => ['nullable', 'string', 'max:60'],
            'lessonsCount' => ['nullable', 'integer', 'min:0', 'max:500'],
            'lessons_count' => ['nullable', 'integer', 'min:0', 'max:500'],
            'status' => ['nullable', 'string', Rule::in(CourseStatus::values())],
        ];
    }
}
