<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupScheduleSlot extends Model
{
    use HasFactory, BelongsToTeacher;

    protected $fillable = [
        'study_group_id',
        'teacher_id',
        'day_of_week',
        'start_time',
        'end_time',
        'hall_name',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function studyGroup(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }
}

