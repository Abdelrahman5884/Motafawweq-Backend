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

        $liveStudents = (int) ($this->getAttribute('live_students_count') ?? 0);
        $studentsCount = max($liveStudents, (int) $this->students_count);

        $actualLessons = (int) ($this->getAttribute('live_lessons_count') ?? 0);
        $lessonsCount = $actualLessons > 0 ? $actualLessons : (int) $this->lessons_count;

        $actualDurationSecs = (int) ($this->getAttribute('live_duration_seconds') ?? 0);
        $hoursTotal = $this->resolveHoursLabel($this->hours_total, $actualDurationSecs);

        $liveRevenue = (float) ($this->getAttribute('live_revenue_egp') ?? 0.0);
        $revenueEgp = $liveRevenue > 0
            ? round($liveRevenue, 2)
            : round((float) $this->price_egp * $studentsCount, 2);

        $liveCompletion = $this->getAttribute('live_completion_rate');
        $completionRate = $liveCompletion !== null ? (int) round((float) $liveCompletion) : 0;

        return [
            'id' => $this->uuid,
            'uuid' => $this->uuid,
            'title' => $this->title_en ?? $this->title_ar,
            'titleAr' => $this->title_ar,
            'titleEn' => $this->title_en ?? $this->title_ar,
            'stage' => $this->stage_name_ar,
            'stageId' => $this->stage_id,
            'gradeAr' => $this->grade_ar,
            'subjectAr' => $this->subject_ar,
            'subjectEn' => $this->subject_en ?? $this->subject_ar,
            'priceEgp' => (float) $this->price_egp,
            'studentsCount' => $studentsCount,
            'lessonsCount' => $lessonsCount,
            'actualLessonsCount' => $actualLessons,
            'actualDurationSeconds' => $actualDurationSecs,
            'hoursTotal' => $hoursTotal,
            'status' => $status,
            'completionRate' => $completionRate,
            'revenueEgp' => $revenueEgp,
            'descriptionAr' => $this->description_ar,
            'descriptionEn' => $this->description_en ?? $this->description_ar,
            'cover' => $this->cover_url,
            'createdAt' => $this->formatDate($this->created_at),
            'updatedAt' => $this->formatDate($this->updated_at),
        ];
    }

    /**
     * Format total hours from actual uploaded lessons if the stored label is still empty/default.
     */
    protected function resolveHoursLabel(?string $storedLabel, int $actualDurationSecs): string
    {
        $clean = trim((string) $storedLabel);
        $isDefaultPlaceholder = in_array($clean, ['', '0 ساعة', '2 ساعة', '20 ساعة'], true);

        if ($actualDurationSecs > 0 && $isDefaultPlaceholder) {
            if ($actualDurationSecs < 3600) {
                $mins = max(1, (int) round($actualDurationSecs / 60));

                return "{$mins} دقيقة";
            }

            $hours = round($actualDurationSecs / 3600, 1);
            $formatted = fmod($hours, 1.0) === 0.0 ? (string) (int) $hours : (string) $hours;

            return "{$formatted} ساعة";
        }

        return $clean !== '' ? $clean : '0 ساعة';
    }
}
