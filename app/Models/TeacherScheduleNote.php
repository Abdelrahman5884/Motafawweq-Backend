<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Enums\ScheduleNotePriority;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherScheduleNote extends Model
{
    use HasFactory, BelongsToTeacher;

    protected $fillable = [
        'teacher_id',
        'day_of_week',
        'note_text',
        'note_time',
        'priority',
        'is_done',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'is_done'     => 'boolean',
        ];
    }
}

