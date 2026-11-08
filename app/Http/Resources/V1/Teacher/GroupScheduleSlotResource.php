<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\DayOfWeek;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class GroupScheduleSlotResource extends BaseJsonResource
{
    /**
     * Transform the schedule slot into the frontend structure.
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
            'day'       => $dayEnum ? $dayEnum->value : (string) $this->day_of_week,
            'dayAr'     => $dayEnum ? $dayEnum->labelAr() : (string) $this->day_of_week,
            'dayEn'     => $dayEnum ? $dayEnum->labelEn() : (string) $this->day_of_week,
            'startTime' => $this->start_time,
            'endTime'   => $this->end_time,
            'hall'      => $this->hall_name,
        ];
    }
}

