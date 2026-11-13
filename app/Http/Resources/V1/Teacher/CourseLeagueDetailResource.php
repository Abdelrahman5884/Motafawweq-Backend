<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\LeagueDuration;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class CourseLeagueDetailResource extends BaseJsonResource
{
    /**
     * Transform league model with podium and leaderboard for TeacherLeagueView.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $durationLabel = $this->duration_type instanceof LeagueDuration
            ? $this->duration_type->labelAr()
            : (LeagueDuration::tryFrom((string) $this->duration_type)?->labelAr() ?? 'دوري شهري');

        $participants = $this->relationLoaded('participants') ? $this->participants : collect();

        // Top 3 for Podium
        $podium = $participants->sortBy('rank')->take(3)->values();

        // Leaderboard (supports tier and search filtering)
        $filteredLeaderboard = $participants->sortBy('rank');

        $tierFilter = $request->query('tier');
        if ($tierFilter && in_array($tierFilter, ['Diamond', 'Gold', 'Silver', 'Bronze'], true)) {
            $filteredLeaderboard = $filteredLeaderboard->where('tier', $tierFilter);
        }

        $search = trim((string) ($request->query('search') ?? $request->query('q') ?? ''));
        if ($search !== '') {
            $filteredLeaderboard = $filteredLeaderboard->filter(function ($p) use ($search) {
                return str_contains($p->student?->name ?? '', $search)
                    || str_contains($p->badge_title_ar ?? '', $search)
                    || str_contains($p->group_ar ?? '', $search);
            });
        }

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
            'podium'             => LeagueParticipantResource::collection($podium)->resolve($request),
            'leaderboard'        => LeagueParticipantResource::collection($filteredLeaderboard->values())->resolve($request),
            'createdAt'          => $this->formatDate($this->created_at),
        ];
    }
}

