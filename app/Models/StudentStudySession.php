<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentStudySession extends Model
{
    use BelongsToStudent, HasFactory;

    protected $fillable = [
        'uuid',
        'student_id',
        'day_of_week',
        'subject_ar',
        'title_ar',
        'start_time',
        'end_time',
        'duration_minutes',
        'is_completed',
        'color',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'duration_minutes' => 'integer',
            'is_completed' => 'boolean',
        ];
    }
}
