<?php

namespace App\Models;

use App\Enums\ExamStatus;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exam extends Model
{
    use HasFactory, SoftDeletes, BelongsToTeacher;

    protected $fillable = [
        'teacher_id',
        'course_id',
        'title_ar',
        'title_en',
        'course_title_ar',
        'stage',
        'grade_ar',
        'duration_minutes',
        'full_mark',
        'pass_score',
        'questions_count',
        'status',
        'show_results',
        'is_bubble_sheet',
        'due_date',
        'starts_at',
        'avg_score',
        'pass_rate',
        'top_score_count',
        'submissions_count',
    ];

    protected function casts(): array
    {
        return [
            'status'            => ExamStatus::class,
            'show_results'      => 'boolean',
            'is_bubble_sheet'   => 'boolean',
            'due_date'          => 'date:Y-m-d',
            'starts_at'         => 'datetime',
            'duration_minutes'  => 'integer',
            'full_mark'         => 'integer',
            'pass_score'        => 'integer',
            'questions_count'   => 'integer',
            'avg_score'         => 'float',
            'pass_rate'         => 'float',
            'top_score_count'   => 'integer',
            'submissions_count' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'exam_id')->orderBy('question_number');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ExamSubmission::class, 'exam_id');
    }

    /**
     * Recalculate questions_count and full_mark based on child questions.
     */
    public function recalculateMetrics(): self
    {
        $count = $this->questions()->count();
        $totalPoints = (int) $this->questions()->sum('points');

        $this->update([
            'questions_count' => $count,
            'full_mark'       => $totalPoints > 0 ? $totalPoints : $this->full_mark,
        ]);

        return $this;
    }

    /**
     * Recalculate exam performance metrics based on graded submissions.
     */
    public function recalculatePerformanceMetrics(): self
    {
        $gradedQuery = $this->submissions()->where('status', \App\Enums\ExamSubmissionStatus::GRADED);
        $count = $gradedQuery->count();
        $avgScore = $count > 0 ? (float) $gradedQuery->avg('score') : 0.00;
        $passedCount = (clone $gradedQuery)->where('is_passed', true)->count();
        $passRate = $count > 0 ? round(($passedCount / $count) * 100, 2) : 0.00;
        $fullMark = max(1, (int) $this->full_mark);
        $topScoreCount = (clone $gradedQuery)->where('score', '>=', 0.9 * $fullMark)->count();

        $this->update([
            'submissions_count' => $count,
            'avg_score'         => round($avgScore, 2),
            'pass_rate'         => $passRate,
            'top_score_count'   => $topScoreCount,
        ]);

        return $this;
    }
}

