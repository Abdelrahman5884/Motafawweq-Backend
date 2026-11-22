<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\LeagueTier;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class LeagueParticipantResource extends BaseJsonResource
{
    /**
     * Transform participant into leaderboard row for TeacherLeagueView.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $student = $this->whenLoaded('student');
        $tierValue = $this->tier ?? LeagueTier::BRONZE->value;
        $tierEnum = LeagueTier::tryFrom((string) $tierValue) ?? LeagueTier::BRONZE;

        return [
            'id' => $this->uuid,
            'studentId' => (string) $this->student_id,
            'studentCode' => $student?->student_code,
            'rank' => (int) $this->rank,
            'previousRank' => $this->previous_rank !== null ? (int) $this->previous_rank : null,
            'movement' => $this->movement ?? 'same',
            'nameAr' => $student?->name ?? 'طالب متفوق',
            'avatar' => $student?->avatar,
            'score' => (int) $this->xp_points,
            'streak' => (int) $this->streak_days,
            'badge' => $this->badge_title_ar ?? 'منافس نشط',
            'tier' => $tierEnum->value,
            'tierLabelAr' => $tierEnum->labelAr(),
            'schoolAr' => $this->school_ar ?? '',
            'groupAr' => $this->group_ar ?? ($this->whenLoaded('league', fn () => $this->league?->group_name_ar) ?? 'عام'),
            'perfectExams' => (int) $this->perfect_exams_count,
            'examsSolved' => (int) $this->exams_solved_count,
            'homeworkRate' => (int) $this->homework_rate.'%',
            'attendanceRate' => (int) $this->attendance_rate.'%',
        ];
    }
}
