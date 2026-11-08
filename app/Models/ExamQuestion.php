<?php

namespace App\Models;

use App\Enums\ExamQuestionType;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExamQuestion extends Model
{
    use HasFactory, HasSecurityAudit;

    protected $fillable = [
        'uuid',
        'exam_id',
        'question_number',
        'type',
        'points',
        'difficulty',
        'question_ar',
        'question_en',
        'options_ar',
        'correct_index',
        'correct_bool',
        'model_answer_ar',
        'rubric_points_ar',
        'explanation_ar',
    ];

    protected function casts(): array
    {
        return [
            'type'             => ExamQuestionType::class,
            'question_number'  => 'integer',
            'points'           => 'integer',
            'options_ar'       => 'array',
            'correct_index'    => 'integer',
            'correct_bool'     => 'boolean',
            'rubric_points_ar' => 'array',
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

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }
}

