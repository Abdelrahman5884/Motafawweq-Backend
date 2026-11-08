<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\HomeworkType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreHomeworkRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $titleAr = $this->input('title_ar') ?? $this->input('titleAr');
        $courseId = $this->input('course_id') ?? $this->input('courseId');
        $courseTitleAr = $this->input('course_title_ar') ?? $this->input('courseTitleAr');
        $lessonId = $this->input('lesson_id') ?? $this->input('lessonId');
        $lessonTitleAr = $this->input('lesson_title_ar') ?? $this->input('lessonTitleAr');
        $targetLocationId = $this->input('target_location_id') ?? $this->input('targetLocationId') ?? 'all';
        $targetLocationAr = $this->input('target_location_ar') ?? $this->input('targetLocationAr');
        $type = $this->input('type') ?? ($this->hasFile('file') || $this->hasFile('attachment') ? 'file' : 'text');
        $textContent = $this->input('text_content') ?? $this->input('textContent');
        $dueDate = $this->input('due_date') ?? $this->input('dueDate');
        $dueTime = $this->input('due_time') ?? $this->input('dueTime') ?? '23:59';
        $maxGrade = $this->input('max_grade') ?? $this->input('maxGrade') ?? 20;
        $totalAssigned = $this->input('total_assigned') ?? $this->input('totalAssigned');

        $merged = [];
        if ($titleAr !== null) {
            $merged['title_ar'] = trim(strip_tags((string) $titleAr));
        }
        if ($courseId !== null) {
            $merged['course_id'] = $courseId;
        }
        if ($courseTitleAr !== null) {
            $merged['course_title_ar'] = trim(strip_tags((string) $courseTitleAr));
        }
        if ($lessonId !== null) {
            $merged['lesson_id'] = $lessonId;
        }
        if ($lessonTitleAr !== null) {
            $merged['lesson_title_ar'] = trim(strip_tags((string) $lessonTitleAr));
        }
        if ($targetLocationId !== null) {
            $merged['target_location_id'] = trim((string) $targetLocationId);
        }
        if ($targetLocationAr !== null) {
            $merged['target_location_ar'] = trim(strip_tags((string) $targetLocationAr));
        }
        if ($type !== null) {
            $merged['type'] = strtolower(trim((string) $type));
        }
        if ($textContent !== null) {
            $merged['text_content'] = trim((string) $textContent);
        }
        if ($dueDate !== null) {
            $merged['due_date'] = $dueDate;
        }
        if ($dueTime !== null) {
            $merged['due_time'] = trim((string) $dueTime);
        }
        if ($maxGrade !== null) {
            $merged['max_grade'] = (int) $maxGrade;
        }
        if ($totalAssigned !== null) {
            $merged['total_assigned'] = (int) $totalAssigned;
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'title_ar'           => ['required', 'string', 'min:3', 'max:250'],
            'course_id'          => ['nullable'],
            'course_title_ar'    => ['nullable', 'string', 'max:200'],
            'lesson_id'          => ['nullable'],
            'lesson_title_ar'    => ['nullable', 'string', 'max:200'],
            'target_location_id' => ['nullable', 'string', 'max:50'],
            'target_location_ar' => ['nullable', 'string', 'max:150'],
            'type'               => ['nullable', 'string', Rule::in(HomeworkType::values())],
            'text_content'       => ['nullable', 'string'],
            'due_date'           => ['nullable', 'date'],
            'due_time'           => ['nullable', 'string', 'max:10'],
            'max_grade'          => ['nullable', 'numeric', 'min:1', 'max:500'],
            'total_assigned'     => ['nullable', 'integer', 'min:0'],
            'file'               => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:25600'],
            'attachment'         => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:25600'],
        ];
    }
}

