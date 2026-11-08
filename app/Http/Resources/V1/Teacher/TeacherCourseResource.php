<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\CourseStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class TeacherCourseResource extends BaseJsonResource
{
    /**
     * Transform the Course model into the exact frontend structure used by TeacherCoursesView.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof CourseStatus
            ? $this->status->value
            : (string) ($this->status ?? 'published');

        return [
            'id'             => $this->uuid,
            'title'          => $this->title_en ?? $this->title_ar,
            'titleAr'        => $this->title_ar,
            'titleEn'        => $this->title_en ?? $this->title_ar,
            'stage'          => $this->stage_name_ar,
            'stageId'        => $this->stage_id,
            'gradeAr'        => $this->grade_ar,
            'subjectAr'      => $this->subject_ar,
            'subjectEn'      => $this->subject_en ?? $this->subject_ar,
            'priceEgp'       => (float) $this->price_egp,
            'studentsCount'  => (int) $this->students_count,
            'lessonsCount'   => (int) $this->lessons_count,
            'hoursTotal'     => $this->hours_total,
            'status'         => $status,
            'completionRate' => (int) $this->completion_rate,
            'revenueEgp'     => (float) $this->revenue_egp,
            'descriptionAr'  => $this->description_ar,
            'descriptionEn'  => $this->description_en ?? $this->description_ar,
            'cover'          => $this->cover_url,
            'createdAt'      => $this->formatDate($this->created_at),
            'updatedAt'      => $this->formatDate($this->updated_at),
        ];
    }
}

