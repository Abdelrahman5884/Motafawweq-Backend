<?php

namespace App\Models;

use App\Enums\HomeworkSubmissionStatus;
use App\Traits\BelongsToStudent;
use App\Traits\HasSecurityAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class HomeworkSubmission extends Model
{
    use BelongsToStudent, HasFactory, HasSecurityAudit, SoftDeletes;

    protected $table = 'homework_submissions';

    protected $fillable = [
        'uuid',
        'homework_id',
        'student_id',
        'location_ar',
        'submission_type',
        'attachment_name',
        'attachment_path',
        'attachment_disk',
        'attachment_mime_type',
        'attachment_size_bytes',
        'solution_text',
        'status',
        'score',
        'max_score',
        'feedback_ar',
        'submitted_at',
        'graded_at',
        'graded_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => HomeworkSubmissionStatus::class,
            'attachment_size_bytes' => 'integer',
            'score' => 'float',
            'max_score' => 'integer',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
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

    public function homework(): BelongsTo
    {
        return $this->belongsTo(Homework::class, 'homework_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
