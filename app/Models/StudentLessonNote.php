<?php

namespace App\Models;

use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentLessonNote extends Model
{
    use BelongsToStudent, HasFactory;

    protected $fillable = [
        'uuid',
        'student_id',
        'lesson_id',
        'timestamp_seconds',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'timestamp_seconds' => 'integer',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }
}
