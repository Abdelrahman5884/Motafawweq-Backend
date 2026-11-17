<?php

namespace App\Models;

use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StudentConvertedLecture extends Model
{
    use BelongsToStudent, HasFactory;

    protected $fillable = [
        'uuid',
        'student_id',
        'lesson_id',
        'title_ar',
        'subject_ar',
        'grade_ar',
        'file_name',
        'file_size',
        'duration_label',
        'summary_ar',
        'outputs',
        'transcript',
        'topics',
        'mindmap',
        'quiz_questions',
        'questions_count',
        'concepts_count',
    ];

    protected function casts(): array
    {
        return [
            'outputs' => 'array',
            'transcript' => 'array',
            'topics' => 'array',
            'mindmap' => 'array',
            'quiz_questions' => 'array',
            'questions_count' => 'integer',
            'concepts_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
