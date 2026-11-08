<?php

namespace App\Models;

use App\Enums\ExamSubmissionStatus;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ExamSubmission extends Model
{
    use HasFactory, HasSecurityAudit;

    protected $fillable = [
        'uuid',
        'exam_id',
        'student_id',
        'started_at',
        'submitted_at',
        'score',
        'full_mark',
        'percentage',
        'is_passed',
        'is_full_mark',
        'status',
        'teacher_feedback',
        'graded_by',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => ExamSubmissionStatus::class,
            'started_at'   => 'datetime',
            'submitted_at' => 'datetime',
            'graded_at'    => 'datetime',
            'score'        => 'float',
            'full_mark'    => 'integer',
            'percentage'   => 'float',
            'is_passed'    => 'boolean',
            'is_full_mark' => 'boolean',
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

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamSubmissionAnswer::class, 'exam_submission_id');
    }

    /**
     * Recalculate total score, percentage, and pass/full mark flags from answers.
     */
    public function recalculateScores(): self
    {
        $totalAwarded = (float) $this->answers()->sum('points_awarded');
        $fullMark = $this->full_mark > 0 ? (int) $this->full_mark : (int) ($this->exam?->full_mark ?? 50);
        $percentage = $fullMark > 0 ? round(($totalAwarded / $fullMark) * 100, 2) : 0.00;
        $passScore = (int) ($this->exam?->pass_score ?? 25);

        $this->update([
            'score'        => $totalAwarded,
            'percentage'   => $percentage,
            'is_passed'    => $totalAwarded >= $passScore,
            'is_full_mark' => $totalAwarded >= $fullMark && $fullMark > 0,
        ]);

        return $this;
    }
}

