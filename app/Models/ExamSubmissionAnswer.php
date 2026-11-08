<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExamSubmissionAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'exam_submission_id',
        'exam_question_id',
        'selected_index',
        'selected_bool',
        'essay_answer_text',
        'points_awarded',
        'is_correct',
        'teacher_feedback',
    ];

    protected function casts(): array
    {
        return [
            'selected_index' => 'integer',
            'selected_bool'  => 'boolean',
            'points_awarded' => 'float',
            'is_correct'     => 'boolean',
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

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ExamSubmission::class, 'exam_submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }
}

