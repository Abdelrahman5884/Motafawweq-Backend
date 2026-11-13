<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\LeagueDuration;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class CourseLeagueResource extends BaseJsonResource
{
    /**
     * Transform league model for list and switcher in TeacherLeagueView.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $durationLabel = $this->duration_type instanceof LeagueDuration
            ? $this->duration_type->labelAr()
            : (LeagueDuration::tryFrom((string) $this->duration_type)?->labelAr() ?? 'دوري شهري');

        return [
            'id'                 => $this->uuid,
            'key'                => $this->uuid,
            'courseId'           => $this->whenLoaded('course', fn () => $this->course?->uuid),
            'groupId'            => $this->study_group_id ? ($this->whenLoaded('studyGroup', fn () => $this->studyGroup?->uuid) ?? (string)$this->study_group_id) : 'all',
            'leagueTitle'        => $this->title_ar,
            'courseNameAr'       => $this->course_name_ar ?: ($this->whenLoaded('course', fn () => $this->course?->title_ar) ?? 'المقرر الدراسي'),
            'groupNameAr'        => $this->group_name_ar,
            'durationLabelAr'    => $durationLabel,
            'durationType'       => $this->duration_type instanceof LeagueDuration ? $this->duration_type->value : (string) $this->duration_type,
            'durationDays'       => (int) $this->duration_days,
            'totalCompetitors'   => (int) $this->total_competitors,
            'roundDaysRemaining' => $this->roundDaysRemaining(),
            'isActive'           => (bool) $this->is_active,
            'startsAt'           => $this->formatDate($this->starts_at),
            'endsAt'             => $this->formatDate($this->ends_at),
            'createdAt'          => $this->formatDate($this->created_at),
        ];
    }
}

