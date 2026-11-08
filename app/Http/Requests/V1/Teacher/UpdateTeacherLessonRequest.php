<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\LessonMediaMode;
use App\Enums\LessonStatus;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherLessonRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasAny(['title_ar', 'titleAr', 'title'])) {
            $titleAr = $this->input('title_ar') ?? $this->input('titleAr') ?? $this->input('title');
            if ($titleAr !== null) {
                $this->merge([
                    'title_ar' => trim(strip_tags((string) $titleAr)),
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'title_ar'              => ['sometimes', 'required', 'string', 'min:3', 'max:220'],
            'title'                 => ['sometimes', 'nullable', 'string', 'max:220'],
            'titleAr'               => ['sometimes', 'nullable', 'string', 'max:220'],
            'titleEn'               => ['sometimes', 'nullable', 'string', 'max:220'],
            'title_en'              => ['sometimes', 'nullable', 'string', 'max:220'],
            'description'           => ['sometimes', 'nullable', 'string', 'max:4000'],
            'descriptionAr'         => ['sometimes', 'nullable', 'string', 'max:4000'],
            'description_ar'        => ['sometimes', 'nullable', 'string', 'max:4000'],
            'courseId'              => ['sometimes', 'nullable', 'uuid'],
            'courseUuid'            => ['sometimes', 'nullable', 'uuid'],
            'course_uuid'           => ['sometimes', 'nullable', 'uuid'],
            'subject'               => ['sometimes', 'nullable', 'string', 'max:120'],
            'subjectAr'             => ['sometimes', 'nullable', 'string', 'max:120'],
            'subject_ar'            => ['sometimes', 'nullable', 'string', 'max:120'],
            'grade'                 => ['sometimes', 'nullable', 'string', 'max:100'],
            'gradeAr'               => ['sometimes', 'nullable', 'string', 'max:100'],
            'grade_ar'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'classGroup'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'targetGroup'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'target_group_code'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'mode'                  => ['sometimes', 'nullable', 'string', Rule::in(LessonMediaMode::values())],
            'media_mode'            => ['sometimes', 'nullable', 'string', Rule::in(LessonMediaMode::values())],
            'embedUrl'              => ['sometimes', 'nullable', 'string', 'max:500'],
            'embed_url'             => ['sometimes', 'nullable', 'string', 'max:500'],
            'videoUrl'              => ['sometimes', 'nullable', 'string', 'max:500'],
            'aiFeatures'            => ['sometimes', 'nullable', 'array'],
            'ai_features_requested' => ['sometimes', 'nullable', 'array'],
            'status'                => ['sometimes', 'nullable', 'string', Rule::in(LessonStatus::values())],
        ];
    }
}

