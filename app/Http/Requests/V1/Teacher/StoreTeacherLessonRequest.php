<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\LessonMediaMode;
use App\Enums\LessonStatus;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreTeacherLessonRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $titleAr = $this->input('title_ar') ?? $this->input('titleAr') ?? $this->input('title');

        if ($titleAr !== null) {
            $this->merge([
                'title_ar' => trim(strip_tags((string) $titleAr)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'title_ar'              => ['required', 'string', 'min:3', 'max:220'],
            'title'                 => ['nullable', 'string', 'max:220'],
            'titleAr'               => ['nullable', 'string', 'max:220'],
            'titleEn'               => ['nullable', 'string', 'max:220'],
            'title_en'              => ['nullable', 'string', 'max:220'],
            'description'           => ['nullable', 'string', 'max:4000'],
            'descriptionAr'         => ['nullable', 'string', 'max:4000'],
            'description_ar'        => ['nullable', 'string', 'max:4000'],
            'descriptionEn'         => ['nullable', 'string', 'max:4000'],
            'description_en'        => ['nullable', 'string', 'max:4000'],
            'courseId'              => ['nullable', 'uuid'],
            'courseUuid'            => ['nullable', 'uuid'],
            'course_uuid'           => ['nullable', 'uuid'],
            'subject'               => ['nullable', 'string', 'max:120'],
            'subjectAr'             => ['nullable', 'string', 'max:120'],
            'subject_ar'            => ['nullable', 'string', 'max:120'],
            'grade'                 => ['nullable', 'string', 'max:100'],
            'gradeAr'               => ['nullable', 'string', 'max:100'],
            'grade_ar'              => ['nullable', 'string', 'max:100'],
            'classGroup'            => ['nullable', 'string', 'max:100'],
            'targetGroup'           => ['nullable', 'string', 'max:100'],
            'target_group_code'     => ['nullable', 'string', 'max:100'],
            'mode'                  => ['nullable', 'string', Rule::in(LessonMediaMode::values())],
            'media_mode'            => ['nullable', 'string', Rule::in(LessonMediaMode::values())],
            'embedUrl'              => ['nullable', 'string', 'max:500'],
            'embed_url'             => ['nullable', 'string', 'max:500'],
            'videoUrl'              => ['nullable', 'string', 'max:500'],
            'durationSeconds'       => ['nullable', 'integer', 'min:0', 'max:86400'],
            'duration_seconds'      => ['nullable', 'integer', 'min:0', 'max:86400'],
            'durationLabel'         => ['nullable', 'string', 'max:40'],
            'duration_label'        => ['nullable', 'string', 'max:40'],
            'aiFeatures'            => ['nullable', 'array'],
            'ai_features_requested' => ['nullable', 'array'],
            'status'                => ['nullable', 'string', Rule::in(LessonStatus::values())],
        ];
    }
}

