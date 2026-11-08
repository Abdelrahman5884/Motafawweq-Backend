<?php

namespace App\Models;

use App\Enums\HomeworkSubmissionStatus;
use App\Enums\HomeworkType;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Homework extends Model
{
    use HasFactory, SoftDeletes, BelongsToTeacher;

    protected $table = 'homeworks';

    protected $fillable = [
        'teacher_id',
        'course_id',
        'course_title_ar',
        'lesson_id',
        'lesson_title_ar',
        'title_ar',
        'target_location_id',
        'target_location_ar',
        'type',
        'file_type',
        'attachment_name',
        'attachment_path',
        'text_content',
        'due_date',
        'due_time',
        'max_grade',
        'total_assigned',
        'submitted_count',
        'graded_count',
        'pending_grading',
        'avg_grade',
    ];

    protected function casts(): array
    {
        return [
            'type'            => HomeworkType::class,
            'due_date'        => 'date:Y-m-d',
            'max_grade'       => 'integer',
            'total_assigned'  => 'integer',
            'submitted_count' => 'integer',
            'graded_count'    => 'integer',
            'pending_grading' => 'integer',
            'avg_grade'       => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(HomeworkSubmission::class, 'homework_id');
    }

    /**
     * Recalculate submission counters and average grade based on child submissions.
     */
    public function recalculateMetrics(): self
    {
        $submitted = $this->submissions()->count();
        $graded = $this->submissions()->where('status', HomeworkSubmissionStatus::GRADED)->count();
        $pending = $this->submissions()->where('status', HomeworkSubmissionStatus::PENDING)->count();
        $avgScore = $this->submissions()->where('status', HomeworkSubmissionStatus::GRADED)->avg('score') ?? 0.00;

        $this->update([
            'submitted_count' => $submitted,
            'graded_count'    => $graded,
            'pending_grading' => $pending,
            'avg_grade'       => round((float) $avgScore, 2),
        ]);

        return $this;
    }
}

