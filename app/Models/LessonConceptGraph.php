<?php

namespace App\Models;

use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonConceptGraph extends Model
{
    use BelongsToTeacher, HasFactory;

    protected $fillable = [
        'lesson_id',
        'teacher_id',
        'tree_data',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'tree_data' => 'array',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
