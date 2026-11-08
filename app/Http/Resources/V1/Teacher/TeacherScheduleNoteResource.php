<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\DayOfWeek;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class TeacherScheduleNoteResource extends BaseJsonResource
{
    /**
     * Transform the schedule note into the frontend structure.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dayEnum = $this->day_of_week instanceof DayOfWeek
            ? $this->day_of_week
            : DayOfWeek::tryFrom((string) $this->day_of_week);

        return [
            'id'        => $this->uuid,
            'uuid'      => $this->uuid,
            'day'       => $dayEnum ? $dayEnum->value : (string) $this->day_of_week,
            'text'      => $this->note_text,
            'time'      => $this->note_time,
            'priority'  => $this->priority,
            'isDone'    => (bool) $this->is_done,
            'createdAt' => $this->created_at?->toDateString(),
        ];
    }
}

