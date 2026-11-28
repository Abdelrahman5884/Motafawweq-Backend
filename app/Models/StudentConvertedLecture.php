<?php

namespace App\Models;

use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'media_disk',
        'media_path',
        'media_mime_type',
        'source_url',
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

    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiProcessingJob::class, 'converted_lecture_id')->latest('id');
    }

    public function latestSttJob(): HasOne
    {
        return $this->hasOne(AiProcessingJob::class, 'converted_lecture_id')
            ->where('task_type', AiProcessingJob::TASK_SPEECH_TO_TEXT)
            ->latestOfMany('id');
    }

    public function latestAnalysisJob(): HasOne
    {
        return $this->hasOne(AiProcessingJob::class, 'converted_lecture_id')
            ->whereIn('task_type', AiProcessingJob::ANALYSIS_TASK_TYPES)
            ->latestOfMany('id');
    }
}
