<?php

namespace App\Models;

use App\Enums\LessonAttachmentType;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonAttachment extends Model
{
    use HasFactory, BelongsToTeacher;

    protected $fillable = [
        'lesson_id',
        'teacher_id',
        'type',
        'title',
        'caption',
        'original_file_name',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'file_size_label',
        'pages_count',
        'display_order',
    ];

    /**
     * Hide internal storage paths from accidental JSON serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'storage_disk',
        'storage_path',
    ];

    protected function casts(): array
    {
        return [
            'type'            => LessonAttachmentType::class,
            'file_size_bytes' => 'integer',
            'pages_count'     => 'integer',
            'display_order'   => 'integer',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}

