<?php

namespace App\Models;

use App\Enums\LessonAttachmentType;
use App\Enums\LessonMediaMode;
use App\Enums\LessonStatus;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use BelongsToTeacher, HasFactory, SoftDeletes;

    protected $fillable = [
        'teacher_id',
        'course_id',
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'subject_ar',
        'grade_ar',
        'target_group_code',
        'media_mode',
        'media_disk',
        'media_path',
        'media_original_name',
        'media_mime_type',
        'media_size_bytes',
        'embed_url',
        'is_youtube',
        'duration_seconds',
        'duration_label',
        'ai_features_requested',
        'status',
        'published_at',
    ];

    protected $hidden = [
        'media_disk',
        'media_path',
    ];

    protected function casts(): array
    {
        return [
            'media_mode' => LessonMediaMode::class,
            'status' => LessonStatus::class,
            'is_youtube' => 'boolean',
            'media_size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'ai_features_requested' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class)->orderBy('display_order')->orderBy('id');
    }

    public function pdfAttachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class)
            ->where('type', LessonAttachmentType::PDF)
            ->orderBy('display_order')
            ->orderBy('id');
    }

    public function whiteboardImages(): HasMany
    {
        return $this->hasMany(LessonAttachment::class)
            ->where('type', LessonAttachmentType::WHITEBOARD_IMAGE)
            ->orderBy('display_order')
            ->orderBy('id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(LessonChapter::class)->orderBy('start_seconds')->orderBy('display_order');
    }

    public function transcriptSegments(): HasMany
    {
        return $this->hasMany(LessonTranscript::class)->orderBy('start_seconds')->orderBy('display_order');
    }

    public function conceptGraph(): HasOne
    {
        return $this->hasOne(LessonConceptGraph::class);
    }

    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiProcessingJob::class)->latest('id');
    }

    public function latestSttJob(): HasOne
    {
        return $this->hasOne(AiProcessingJob::class)
            ->where('task_type', AiProcessingJob::TASK_SPEECH_TO_TEXT)
            ->latestOfMany('id');
    }

    public function latestAnalysisJob(): HasOne
    {
        return $this->hasOne(AiProcessingJob::class)
            ->whereIn('task_type', AiProcessingJob::ANALYSIS_TASK_TYPES)
            ->latestOfMany('id');
    }
}
