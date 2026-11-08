<?php

namespace App\Http\Resources\V1\Teacher;

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

        return [
            'id'             => $this->uuid,
            'studentId'      => (string) $this->student_id,
            'rank'           => (int) $this->rank,
            'previousRank'   => $this->previous_rank !== null ? (int) $this->previous_rank : null,
            'movement'       => $this->movement ?? 'same',
            'nameAr'         => $student?->name ?? 'طالب متفوق',
            'avatar'         => $student?->avatar,
            'score'          => (int) $this->xp_points,
            'streak'         => (int) $this->streak_days,
            'badge'          => $this->badge_title_ar ?? 'منافس نشط',
            'tier'           => $this->tier ?? 'Bronze',
            'schoolAr'       => $this->school_ar ?? 'مدرسة المتفوقين',
            'groupAr'        => $this->group_ar ?? ($this->whenLoaded('league', fn () => $this->league?->group_name_ar) ?? 'عام'),
            'perfectExams'   => (int) $this->perfect_exams_count,
            'examsSolved'    => (int) $this->exams_solved_count,
            'homeworkRate'   => $this->homework_rate . '%',
            'attendanceRate' => $this->attendance_rate . '%',
        ];
    }
}

