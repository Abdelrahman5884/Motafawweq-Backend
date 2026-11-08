<?php

namespace App\Models;

use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonChapter extends Model
{
    use HasFactory, BelongsToTeacher;

    protected $fillable = [
        'lesson_id',
        'teacher_id',
        'title_ar',
        'title_en',
        'start_seconds',
        'timestamp_label',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'start_seconds' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}

