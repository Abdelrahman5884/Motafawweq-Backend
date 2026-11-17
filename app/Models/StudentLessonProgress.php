<?php

namespace App\Models;

use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentLessonProgress extends Model
{
    use BelongsToStudent, HasFactory;

    protected $table = 'student_lesson_progresses';

    protected $fillable = [
        'uuid',
        'student_id',
        'lesson_id',
        'course_id',
        'watched_seconds',
        'max_position_seconds',
        'duration_seconds',
        'progress_percent',
        'is_completed',
        'completed_at',
        'is_bookmarked',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'watched_seconds' => 'integer',
            'max_position_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'progress_percent' => 'integer',
            'is_completed' => 'boolean',
            'is_bookmarked' => 'boolean',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
